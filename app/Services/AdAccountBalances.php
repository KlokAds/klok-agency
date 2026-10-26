<?php

namespace App\Services;

use App\Models\Campaign;
use App\Models\CampaignDay;
use App\Models\MetaReceipt;
use App\Models\PilotAccount;
use App\Models\Setting;
use App\Models\UsdEntry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * What each ad account owes Meta and what Meta has taken from our card — and which campaign
 * each charge paid for.
 *
 * Meta charges the card in lumps (a billing threshold, or month end), not day by day. So an ad
 * account always has a running balance: spend Meta has counted but not yet charged. Every charge
 * that arrives (a receipt mail, or Pilot's charge list) is spread over the account's campaigns,
 * oldest spend first, unless the receipt itself says which campaign got what. From that, a
 * client's spend splits into what Meta has already charged (final) and what it has not (still
 * provisional against their credit).
 */
class AdAccountBalances
{
    /** The first day charges are tracked from: spend before it was charged before Pilot read charges. */
    public static function since(): CarbonImmutable
    {
        $since = Setting::get('meta_charges_since');

        return $since ? CarbonImmutable::parse((string) $since)->startOfDay() : CarbonImmutable::now()->startOfDay();
    }

    /**
     * Spreads every charge that has no breakdown yet over the account's spend, oldest first.
     *
     * @return int how many charges were spread
     */
    public function allocate(): int
    {
        $done = 0;

        foreach (PilotAccount::all() as $account) {
            $charges = $this->charges($account);
            if ($charges->isEmpty()) {
                continue;
            }

            // Spend not yet covered: a day of a campaign, oldest first, minus what earlier charges took.
            $left = $this->days($account)->mapWithKeys(fn (CampaignDay $d) => [$d->campaign_id.':'.$d->day->toDateString() => (int) $d->spend]);
            $takenByCampaign = [];
            foreach ($charges as $entry) {
                foreach ((array) ($entry->allocations ?? []) as $key => $cents) {
                    if (str_starts_with((string) $key, 'c:')) {
                        $takenByCampaign[(int) substr((string) $key, 2)] = ($takenByCampaign[(int) substr((string) $key, 2)] ?? 0) + (int) $cents;
                    }
                }
            }
            foreach ($left as $key => $spend) {
                $campaignId = (int) strtok($key, ':');
                $take = min($spend, $takenByCampaign[$campaignId] ?? 0);
                $left[$key] = $spend - $take;
                $takenByCampaign[$campaignId] = ($takenByCampaign[$campaignId] ?? 0) - $take;
            }

            foreach ($charges as $entry) {
                if ($entry->allocations !== null) {
                    continue;
                }
                $amount = -(int) $entry->amount;
                $out = [];
                foreach ($left as $key => $spend) {
                    if ($amount <= 0) {
                        break;
                    }
                    $take = min($spend, $amount);
                    if ($take <= 0) {
                        continue;
                    }
                    $campaignKey = 'c:'.strtok($key, ':');
                    $out[$campaignKey] = ($out[$campaignKey] ?? 0) + $take;
                    $left[$key] = $spend - $take;
                    $amount -= $take;
                }
                // More than the spend we know of (an old balance, a campaign not synced): "other".
                if ($amount > 0) {
                    $out['o'] = $amount;
                }
                activity()->withoutLogging(fn () => $entry->forceFill(['allocations' => $out])->save());
                $done++;
            }
        }

        return $done;
    }

    /**
     * Every ad account: what Meta counted, what it charged, what it still will — and the same for
     * each campaign, with the latest charges and whom they paid for.
     *
     * @return list<array<string, mixed>>
     */
    public function accounts(): array
    {
        $out = [];

        foreach (PilotAccount::orderBy('name')->get() as $account) {
            $days = $this->days($account);
            $charges = $this->charges($account);
            if ($days->isEmpty() && $charges->isEmpty()) {
                continue;
            }

            $spentBy = $days->groupBy('campaign_id')->map(fn (Collection $d) => (int) $d->sum('spend'));
            $chargedBy = [];
            foreach ($charges as $entry) {
                foreach ((array) ($entry->allocations ?? []) as $key => $cents) {
                    $chargedBy[$key] = ($chargedBy[$key] ?? 0) + (int) $cents;
                }
            }
            $campaigns = Campaign::whereKey($spentBy->keys()->all())->with('client:id,name')->get()->keyBy('id');
            $spent = (int) $spentBy->sum();
            $charged = (int) $charges->sum(fn (UsdEntry $e) => -(int) $e->amount);

            $out[] = [
                'id' => $account->id,
                'name' => $account->name,
                'currency' => $account->currency,
                'since' => self::since()->toDateString(),
                'spent' => $spent,
                'charged' => $charged,
                'pending' => max(0, $spent - $charged),
                'campaigns' => $spentBy->sortDesc()->map(fn (int $s, int $id) => [
                    'id' => $id,
                    'slug' => $campaigns->get($id)?->slug,
                    'name' => $campaigns->get($id)->name ?? '#'.$id,
                    'client' => $campaigns->get($id)?->client?->name,
                    'spent' => $s,
                    'charged' => min($s, (int) ($chargedBy['c:'.$id] ?? 0)),
                    'pending' => max(0, $s - (int) ($chargedBy['c:'.$id] ?? 0)),
                ])->values()->all(),
                'charges' => $charges->sortByDesc('entry_date')->take(12)->map(fn (UsdEntry $e) => [
                    'id' => $e->id,
                    'date' => $e->entry_date?->toDateString(),
                    'amount' => -(int) $e->amount,
                    'card' => preg_match('/card (\d{4})/', (string) $e->description, $m) ? $m[1] : null,
                    'by_campaign' => collect((array) ($e->allocations ?? []))->map(fn ($cents, $key) => [
                        'name' => str_starts_with((string) $key, 'c:') ? ($campaigns->get((int) substr((string) $key, 2))->name ?? '#'.substr((string) $key, 2)) : __('অন্য'),
                        'amount' => (int) $cents,
                    ])->values()->all(),
                ])->values()->all(),
            ];
        }

        return $out;
    }

    /**
     * A client's ad spend since charges are tracked: what Meta has charged our card for (final)
     * and what it has not yet (counted against their credit day by day, settled when charged).
     *
     * @return array{charged: int, uncharged: int}
     */
    public function client(int $clientId): array
    {
        $campaigns = Campaign::where('client_id', $clientId)->whereNotNull('bill_from')->get(['id', 'bill_from']);
        if ($campaigns->isEmpty()) {
            return ['charged' => 0, 'uncharged' => 0];
        }
        $since = self::since();
        $spent = 0;
        foreach ($campaigns as $c) {
            $from = $c->bill_from->greaterThan($since) ? $c->bill_from : $since;
            $spent += (int) CampaignDay::where('campaign_id', $c->id)->whereDate('day', '>=', $from->toDateString())->sum('spend');
        }
        $charged = 0;
        $keys = $campaigns->map(fn ($c) => 'c:'.$c->id)->all();
        foreach (UsdEntry::where('category', 'card_charge')->whereNotNull('allocations')->whereDate('entry_date', '>=', $since->toDateString())->get(['allocations']) as $entry) {
            $charged += (int) array_sum(array_intersect_key((array) $entry->allocations, array_flip($keys)));
        }

        return ['charged' => min($spent, $charged), 'uncharged' => max(0, $spent - $charged)];
    }

    /**
     * What Meta itself says is still to charge, account by account (USD Meta accounts), read
     * live through Pilot and kept ten minutes; null when Pilot cannot be reached.
     *
     * @return array{total: int, accounts: list<array{id: int, name: string, unbilled: int|null, card: string|null, ours: bool, error: string|null}>, read_at: string}|null
     */
    public function liveUnbilled(): ?array
    {
        if (! PilotClient::connected()) {
            return null;
        }

        return Cache::remember('meta:unbilled', 600, function () {
            $rows = [];
            $ours = array_map(fn ($c) => substr(preg_replace('/\D/', '', (string) $c), -4), MetaReceipts::ourCards());
            // One account Meta will not answer for (no access, a closed account) does not hide the rest.
            foreach (PilotAccount::where('platform', 'meta')->where('currency', 'USD')->orderBy('name')->get() as $account) {
                try {
                    $b = app(PilotClient::class)->billing($account->pilot_id);
                } catch (\Throwable $e) {
                    $rows[] = ['id' => $account->id, 'name' => $account->name, 'unbilled' => null, 'card' => null, 'ours' => false, 'error' => mb_substr($e->getMessage(), 0, 120)];

                    continue;
                }
                // Only an account that pays with one of our cards is our money to set aside.
                $last4 = preg_match('/(\d{4})\s*$/', (string) ($b['card'] ?? ''), $m) ? $m[1] : null;
                $rows[] = ['id' => $account->id, 'name' => $account->name, 'unbilled' => (int) ($b['unbilled'] ?? 0), 'card' => $b['card'] ?? null,
                    'ours' => $last4 !== null && in_array($last4, $ours, true), 'error' => null];
            }
            if (collect($rows)->whereNotNull('unbilled')->isEmpty()) {
                return null;
            }

            return ['total' => (int) collect($rows)->where('ours', true)->sum('unbilled'), 'accounts' => $rows, 'read_at' => now()->toIso8601String()];
        });
    }

    /** @return Collection<int, CampaignDay> oldest first */
    private function days(PilotAccount $account): Collection
    {
        return CampaignDay::whereIn('campaign_id', Campaign::where('pilot_account_id', $account->id)->select('id'))
            ->whereDate('day', '>=', self::since()->toDateString())->where('spend', '>', 0)
            ->orderBy('day')->orderBy('campaign_id')->get();
    }

    /** @return Collection<int, UsdEntry> the account's booked charges, oldest first */
    private function charges(PilotAccount $account): Collection
    {
        $ref = ltrim((string) $account->external_id, 'act_');
        $ids = MetaReceipt::query()
            ->where(fn ($q) => $q->where('pilot_account_id', $account->id)->when($ref !== '', fn ($q) => $q->orWhere('account_ref', $ref)))
            ->whereIn('status', ['booked', 'duplicate'])->whereNotNull('usd_entry_id')
            ->pluck('usd_entry_id')->unique()->all();

        return UsdEntry::whereKey($ids)->where('category', 'card_charge')
            ->whereDate('entry_date', '>=', self::since()->toDateString())
            ->orderBy('entry_date')->orderBy('id')->get();
    }
}
