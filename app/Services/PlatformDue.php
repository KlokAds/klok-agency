<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Campaign;
use App\Models\CampaignDay;
use App\Models\Client;
use App\Models\UsdEntry;

/**
 * What an ad platform still has to take off the card, piece by piece: each
 * linked campaign, each client's spend written by hand, and the rest (own
 * ads, adjustments). A card charge can say what it paid for, as the
 * platform's invoice does ("allocations"); whatever a charge does not say
 * pays the oldest spend first.
 *
 * Keys: "c:{campaign id}", "l:{client id}" (by hand), "o" (no client).
 */
class PlatformDue
{
    public function __construct(private Books $books) {}

    /**
     * @return array<string, int> key => cents still to be charged (only keys with something due)
     */
    public function pending(Account $account): array
    {
        $entries = $this->books->usdEntries()->where('account_id', $account->id);
        $charges = $entries->where('category', 'card_charge');
        $pieces = $this->pieces($entries->whereIn('category', UsdEntry::CONSUMING));

        // What charges said they paid for, key by key, oldest spend first.
        $free = -(int) $charges->sum('amount');
        foreach ($charges as $charge) {
            foreach ((array) ($charge->allocations ?? []) as $key => $cents) {
                $paid = $this->pay($pieces, (int) $cents, (string) $key);
                $free -= $paid;
            }
        }

        // The rest of the money pays whatever is oldest.
        $this->pay($pieces, max(0, $free));

        $out = [];
        foreach ($pieces as $piece) {
            if ($piece['left'] > 0) {
                $out[$piece['key']] = ($out[$piece['key']] ?? 0) + $piece['left'];
            }
        }

        // Adjustments and refunds belong to no one: they keep the total equal to the platform's due.
        $due = -(int) $entries->where('category', '!=', 'card_charge')->sum('amount') + (int) $charges->sum('amount');
        $gap = $due - array_sum($out);
        if ($gap !== 0) {
            $out['o'] = ($out['o'] ?? 0) + $gap;
        }

        return array_filter($out, fn ($c) => $c !== 0);
    }

    /**
     * How a new charge of $cents would most likely split: what is still due,
     * oldest first.
     *
     * @return list<array{key: string, label: string, client: ?string, pending: int, suggested: int}>
     */
    public function suggest(Account $account, int $cents): array
    {
        $pending = $this->pending($account);
        $entries = $this->books->usdEntries()->where('account_id', $account->id);
        $pieces = $this->pieces($entries->whereIn('category', UsdEntry::CONSUMING));

        // The pieces still due, oldest first, as pending() left them.
        $left = $pending;
        $open = [];
        foreach (array_reverse($pieces) as $piece) {
            $take = min($piece['left'], max(0, $left[$piece['key']] ?? 0));
            if ($take > 0) {
                $left[$piece['key']] -= $take;
                array_unshift($open, ['key' => $piece['key'], 'left' => $take]);
            }
        }

        $suggested = [];
        $rest = $cents;
        foreach ($open as $piece) {
            $take = min($piece['left'], $rest);
            $suggested[$piece['key']] = ($suggested[$piece['key']] ?? 0) + $take;
            $rest -= $take;
        }

        $labels = $this->labels(array_keys($pending));
        $out = [];
        foreach ($pending as $key => $due) {
            if ($due > 0) {
                $out[] = ['key' => $key, ...$labels[$key], 'pending' => $due, 'suggested' => (int) ($suggested[$key] ?? 0)];
            }
        }

        return $out;
    }

    /**
     * The same, per client (0: no client), for the books' client table.
     *
     * @return array<int, int>
     */
    public function byClient(Account $account): array
    {
        $pending = $this->pending($account);
        $owners = Campaign::whereIn('id', $this->campaignIds(array_keys($pending)))->pluck('client_id', 'id');
        $out = [];

        foreach ($pending as $key => $cents) {
            $client = match (true) {
                str_starts_with($key, 'c:') => (int) ($owners[(int) substr($key, 2)] ?? 0),
                str_starts_with($key, 'l:') => (int) substr($key, 2),
                default => 0,
            };
            $out[$client] = ($out[$client] ?? 0) + $cents;
        }

        return $out;
    }

    /**
     * Each key's name, for the card charge form.
     *
     * @param  list<string>  $keys
     * @return array<string, array{label: string, client: ?string}>
     */
    public function labels(array $keys): array
    {
        $campaigns = Campaign::with('client:id,name')->whereIn('id', $this->campaignIds($keys))->get()->keyBy('id');
        $clients = Client::withTrashed()->whereIn('id', array_map(fn ($k) => (int) substr($k, 2), array_filter($keys, fn ($k) => str_starts_with($k, 'l:'))))->pluck('name', 'id');
        $out = [];

        foreach ($keys as $key) {
            $out[$key] = match (true) {
                str_starts_with($key, 'c:') => ['label' => (string) ($campaigns[(int) substr($key, 2)]->name ?? 'Campaign'), 'client' => isset($campaigns[(int) substr($key, 2)]) ? $campaigns[(int) substr($key, 2)]->client?->name : null],
                str_starts_with($key, 'l:') => ['label' => __('হাতে লেখা খরচ'), 'client' => $clients[(int) substr($key, 2)] ?? null],
                default => ['label' => __('Client ছাড়া (নিজের ad, adjustment)'), 'client' => null],
            };
        }

        return $out;
    }

    /**
     * Spend rows cut into pieces by key, oldest first: a Pilot row is shared
     * between the client's campaigns by what each spent that day.
     *
     * @param  iterable<UsdEntry>  $rows
     * @return list<array{key: string, left: int}>
     */
    private function pieces(iterable $rows): array
    {
        $pieces = [];

        foreach ($rows as $row) {
            $amount = -(int) $row->amount;

            if ($amount <= 0) {
                continue;
            }

            if ($row->client_id === null) {
                $pieces[] = ['key' => 'o', 'left' => $amount];

                continue;
            }

            $days = $row->source === 'pilot' && $row->entry_date
                ? CampaignDay::whereDate('day', $row->entry_date->toDateString())
                    ->whereIn('campaign_id', Campaign::where('client_id', $row->client_id)->pluck('id'))
                    ->where('spend', '>', 0)->get(['campaign_id', 'spend'])->values()
                : collect();
            $total = (int) $days->sum('spend');

            if ($total <= 0) {
                $pieces[] = ['key' => 'l:'.$row->client_id, 'left' => $amount];

                continue;
            }

            $given = 0;
            foreach ($days as $i => $day) {
                $share = $i === $days->count() - 1 ? $amount - $given : (int) round($amount * $day->spend / $total);
                $given += $share;
                $pieces[] = ['key' => 'c:'.$day->campaign_id, 'left' => $share];
            }
        }

        return $pieces;
    }

    /**
     * Pays up to $cents of the pieces (of one key, or any), oldest first.
     *
     * @param  list<array{key: string, left: int}>  $pieces
     * @return int what was paid
     */
    private function pay(array &$pieces, int $cents, ?string $key = null): int
    {
        $paid = 0;

        foreach ($pieces as &$piece) {
            if ($cents <= $paid) {
                break;
            }

            if ($key !== null && $piece['key'] !== $key) {
                continue;
            }

            $take = min($piece['left'], $cents - $paid);
            $piece['left'] -= $take;
            $paid += $take;
        }
        unset($piece);

        return $paid;
    }

    /**
     * @param  list<string>  $keys
     * @return list<int>
     */
    private function campaignIds(array $keys): array
    {
        return array_values(array_map(fn ($k) => (int) substr($k, 2), array_filter($keys, fn ($k) => str_starts_with($k, 'c:'))));
    }
}
