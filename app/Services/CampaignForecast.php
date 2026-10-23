<?php

namespace App\Services;

use App\Models\Account;
use App\Models\BdtEntry;
use App\Models\Campaign;
use App\Models\CampaignDay;
use App\Models\Client;
use App\Models\UsdEntry;
use Carbon\CarbonImmutable;

/**
 * Meta's side of the card, campaign by campaign: what each campaign has
 * spent that the card has not paid yet (Meta still takes it), what it will
 * spend to its end date at its daily budget, and — per client — whether
 * the money they paid covers that or how much is still due.
 *
 * Card charges are not per campaign, so they pay the oldest spend first
 * (as on the platform table); a day's unpaid spend of a client is shared
 * between their campaigns by what each spent that day.
 */
class CampaignForecast
{
    public function __construct(private Books $books) {}

    /**
     * @return array{clients: list<array<string, mixed>>, pending: int, other: int, rate: ?float}
     */
    public function meta(): array
    {
        $account = Account::where('kind', 'platform')->where('code', 'meta')->first();

        if ($account === null) {
            return ['clients' => [], 'pending' => 0, 'other' => 0, 'rate' => null];
        }

        $unpaid = $this->unpaidRows($account);
        $byCampaign = [];
        $byClientLoose = [];
        $other = 0;
        $rows = UsdEntry::whereIn('id', array_keys($unpaid))->get(['id', 'client_id', 'source', 'entry_date']);

        foreach ($rows as $row) {
            $left = $unpaid[$row->id];

            if ($row->client_id === null) {
                $other += $left;

                continue;
            }

            $days = $row->source === 'pilot'
                ? CampaignDay::whereDate('day', $row->entry_date->toDateString())
                    ->whereIn('campaign_id', Campaign::where('client_id', $row->client_id)->pluck('id'))
                    ->where('spend', '>', 0)->get(['campaign_id', 'spend'])
                : collect();
            $total = (int) $days->sum('spend');

            if ($total <= 0) {
                $byClientLoose[$row->client_id] = ($byClientLoose[$row->client_id] ?? 0) + $left;

                continue;
            }

            $given = 0;
            foreach ($days->values() as $i => $day) {
                $share = $i === $days->count() - 1 ? $left - $given : (int) round($left * $day->spend / $total);
                $given += $share;
                $byCampaign[$day->campaign_id] = ($byCampaign[$day->campaign_id] ?? 0) + $share;
            }
        }

        $figures = collect($this->books->clients())->keyBy('id');
        $rate = Invoices::clientRate();
        $today = CarbonImmutable::now()->startOfDay();
        $campaigns = Campaign::with(['account', 'renewals' => fn ($q) => $q->whereIn('status', ['open', 'invoiced', 'paid', 'declined'])->latest('id')])->whereNotNull('client_id')
            ->where(fn ($q) => $q->running()->orWhereIn('id', array_keys($byCampaign)))
            ->get()
            ->filter(fn (Campaign $c) => $c->account?->platform === 'meta' || isset($byCampaign[$c->id]));
        $clientIds = $campaigns->pluck('client_id')->merge(array_keys($byClientLoose))->unique()->values();
        $clients = Client::withTrashed()->whereIn('id', $clientIds)->orderBy('name')->get();
        $out = [];

        foreach ($clients as $client) {
            $mine = $campaigns->where('client_id', $client->id)->values();
            $lines = $mine->map(function (Campaign $c) use ($byCampaign, $today) {
                $end = $c->lastDay();
                $daysLeft = $c->isLive() && $end !== null ? max(0, (int) $today->diffInDays($end, false) + 1) : ($c->isLive() ? null : 0);
                $budget = $c->dailyBudget() ?? ($c->spend_7d > 0 ? (int) round($c->spend_7d / 7) : null);

                return [
                    'id' => $c->id,
                    'name' => $c->name,
                    'live' => $c->isLive(),
                    'paused_for_credit' => $c->paused_for_credit_at !== null,
                    'pending' => (int) ($byCampaign[$c->id] ?? 0),
                    'end' => $end?->toDateString(),
                    'ends_at' => $c->stop_at?->toIso8601String(),
                    'ended' => $c->hasEnded(),
                    'days_left' => $daysLeft,
                    'budget' => $budget,
                    'to_spend' => $budget !== null && $daysLeft !== null ? $budget * $daysLeft : null,
                    'renewal' => ($r = $c->renewals->first()) ? ['id' => $r->id, 'status' => $r->status] : null,
                    'spent' => (int) CampaignDay::where('campaign_id', $c->id)
                        ->when($c->bill_from, fn ($q) => $q->where('day', '>=', $c->bill_from->toDateString()))->sum('spend'),
                    'extendable' => CampaignLimits::guarded($c) && $c->bill_from !== null,
                ];
            })->sortByDesc('pending')->values();

            $figure = $figures[$client->id] ?? null;
            $credit = (int) ($figure['credit_left'] ?? 0);
            $prepaid = (int) ($figure['credit_usd'] ?? 0) > 0;
            $toSpend = (int) $lines->sum('to_spend');
            // Prepaid: what their money has left after the spend to come. Pay
            // after: what they owe for spend so far plus the spend to come.
            $owed = (int) ($figure['usd_pending'] ?? 0);
            $balance = $prepaid ? $credit - $toSpend : -($owed + $toSpend);
            // Right now: what they owe for spend already run, or what is left of their money.
            $dueNow = $prepaid ? max(0, -$credit) : $owed;
            $creditNow = $prepaid ? max(0, $credit) : 0;
            $daily = (int) $lines->where('live', true)->sum('budget');
            $lastsUntil = $creditNow > 0 && $daily > 0 ? $today->addDays(intdiv($creditNow, $daily) - 1) : null;
            $endsOn = $lines->where('live', true)->pluck('end')->filter()->max();
            $last = BdtEntry::where('client_id', $client->id)->whereIn('category', BdtEntry::REVENUE)->latest('entry_date')->latest('id')->first();

            $out[] = [
                'id' => $client->id,
                'name' => $client->name,
                'prepaid' => $prepaid,
                'credit_left' => $prepaid ? $credit : null,
                'last_paid' => $last ? ['date' => $last->entry_date?->toDateString(), 'amount' => $last->amount, 'usd' => $last->usd_credit] : null,
                'pending' => (int) $lines->sum('pending') + (int) ($byClientLoose[$client->id] ?? 0),
                'loose' => (int) ($byClientLoose[$client->id] ?? 0),
                'owed' => $prepaid ? null : $owed,
                'to_spend' => $toSpend,
                'balance' => $balance,
                'due_bdt' => $balance < 0 && $rate ? (int) round(-$balance * $rate) : null,
                'due_now' => $dueNow,
                'due_now_bdt' => $dueNow > 0 && $rate ? (int) round($dueNow * $rate) : null,
                'credit_now' => $creditNow,
                'daily' => $daily,
                'lasts_until' => $lastsUntil && $lastsUntil->gte($today->subDay()) ? $lastsUntil->toDateString() : null,
                'ends_on' => $endsOn,
                'needed' => max(0, -$balance),
                'collected' => (int) BdtEntry::where('client_id', $client->id)->whereIn('category', BdtEntry::REVENUE)->sum('amount'),
                'collected_usd' => (int) ($figure['credit_usd'] ?? 0),
                'campaigns' => $lines->all(),
            ];
        }

        usort($out, fn ($a, $b) => $a['balance'] <=> $b['balance'] ?: $b['pending'] <=> $a['pending']);

        // The total is the platform's own due (as on the platform table);
        // whatever no client's campaign explains is shown as the rest.
        $due = (int) array_sum($unpaid);
        foreach ($this->books->summary()['platforms'] as $platform) {
            if ($platform['id'] === $account->id) {
                $due = (int) $platform['due'];
            }
        }
        $explained = (int) array_sum(array_column($out, 'pending'));

        return ['clients' => $out, 'pending' => $due, 'other' => $due - $explained, 'rate' => $rate];
    }

    /**
     * Rows of the platform's spend the card has not paid yet, oldest paid
     * first (like Books::unbilledByClient).
     *
     * @return array<int, int> usd entry id => cents unpaid
     */
    private function unpaidRows(Account $account): array
    {
        $entries = $this->books->usdEntries()->where('account_id', $account->id);
        $charged = -(int) $entries->where('category', 'card_charge')->sum('amount');
        $out = [];

        foreach ($entries->whereIn('category', UsdEntry::CONSUMING) as $row) {
            $amount = -$row->amount;
            $paid = max(0, min($charged, $amount));
            $charged -= $paid;

            if ($amount - $paid > 0) {
                $out[$row->id] = $amount - $paid;
            }
        }

        return $out;
    }
}
