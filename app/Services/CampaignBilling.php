<?php

namespace App\Services;

use App\Models\Account;
use App\Models\BdtEntry;
use App\Models\Campaign;
use App\Models\CampaignDay;
use App\Models\Client;
use App\Models\PilotAccount;
use App\Models\Setting;
use App\Models\UsdEntry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Bills clients by campaign. Campaigns and their daily spend come from Klok
 * Pilot; the spend of every campaign linked to a client, from its bill_from
 * day on, becomes one "client ad spend" row per client per day in the USD
 * book (source "pilot"). Those rows are then funded, oldest first, by the
 * USD credit the client's payments bought: funded rows are Settled, the
 * rest stay Pending until the client pays.
 */
class CampaignBilling
{
    /** How far back each sync re-reads spend (Meta adjusts recent days). */
    public const LOOKBACK_DAYS = 35;

    public function __construct(private PilotClient $pilot) {}

    /**
     * Copies one ad account's campaigns in from Pilot (name, status, spend so far).
     *
     * @throws RuntimeException when Pilot cannot be reached
     */
    public function refreshCampaigns(PilotAccount $account): int
    {
        $report = $this->pilot->campaigns($account->pilot_id);
        $count = 0;

        foreach ($report['campaigns'] ?? [] as $c) {
            $campaign = Campaign::firstOrNew(['pilot_campaign_id' => (int) $c['campaign_id']]);
            $campaign->fill([
                'pilot_account_id' => $account->id,
                'external_id' => $c['external_id'] ?? null,
                'name' => (string) ($c['name'] ?? '#'.$c['campaign_id']),
                'status' => $c['status'] ?? null,
                'objective' => $c['objective'] ?? null,
                'daily_budget' => isset($c['daily_budget']) ? (int) round((float) $c['daily_budget'] * 100) : null,
                'start_at' => $c['start_time'] ?? null,
                'stop_at' => $c['stop_time'] ?? null,
                'spend_total' => (int) round((float) ($c['spend_total'] ?? 0) * 100),
                'spend_7d' => (int) round((float) ($c['spend_7d'] ?? 0) * 100),
                'last_spend_day' => $c['last_spend_day'] ?? null,
            ]);

            if ($campaign->isDirty()) {
                $campaign->saveQuietly();
            }

            $count++;
        }

        return $count;
    }

    /**
     * The client whose name appears in the campaign name, if exactly one does.
     *
     * @param  iterable<Client>  $clients
     */
    public static function suggestClient(string $campaignName, iterable $clients): ?Client
    {
        $name = Str::lower($campaignName);
        $hits = [];

        foreach ($clients as $client) {
            $needle = Str::lower(trim($client->name));

            if (mb_strlen($needle) >= 3 && preg_match('/(^|[^\pL\pN])'.preg_quote($needle, '/').'($|[^\pL\pN])/u', $name)) {
                $hits[] = $client;
            }
        }

        return count($hits) === 1 ? $hits[0] : null;
    }

    /** Runs the sync at most once an hour (the reminder tick calls this). */
    public function syncIfDue(): bool
    {
        if (! PilotClient::connected() || ! Cache::add('pilot:spend-sync', true, 3600)) {
            return false;
        }

        $this->sync();

        return true;
    }

    /**
     * Reads recent daily spend of every linked campaign from Pilot and
     * rebuilds the clients' spend rows.
     *
     * @return array{campaigns: int, days: int, clients: int}
     *
     * @throws RuntimeException when Pilot cannot be reached
     */
    public function sync(): array
    {
        $linked = Campaign::with('account')->whereNotNull('client_id')->whereNotNull('bill_from')->get();
        $today = CarbonImmutable::now()->startOfDay();
        $days = 0;

        foreach ($linked->groupBy('pilot_account_id') as $campaigns) {
            /** @var PilotAccount $account */
            $account = $campaigns->first()->account;
            $this->refreshCampaigns($account);

            $from = $campaigns->min(fn (Campaign $c) => $c->bill_from)->max($today->subDays(self::LOOKBACK_DAYS));
            $report = $this->pilot->dailySpend($account->pilot_id, $from->toDateString(), $today->toDateString(), $campaigns->map(fn (Campaign $c) => $c->pilot_campaign_id)->values()->all());
            $ids = $campaigns->pluck('id', 'pilot_campaign_id');

            foreach ($report['rows'] ?? [] as $row) {
                $campaignId = $ids[(int) $row['campaign_id']] ?? null;

                if ($campaignId === null) {
                    continue;
                }

                CampaignDay::updateOrCreate(
                    ['campaign_id' => $campaignId, 'day' => $row['date']],
                    ['spend' => (int) round((float) $row['spend'] * 100), 'results' => (int) ($row['results'] ?? 0), 'clicks' => (int) ($row['clicks'] ?? 0)],
                );
                $days++;
            }
        }

        $clients = $linked->map(fn (Campaign $c) => (int) $c->client_id)->unique()->values()->all();
        $this->rebuild($clients);
        Setting::putQuietly('pilot_spend_synced_at', now()->toIso8601String());

        return ['campaigns' => $linked->count(), 'days' => $days, 'clients' => count($clients)];
    }

    /**
     * Rewrites the "pilot" spend rows of these clients from the stored
     * campaign days, then works out which are funded. Days on or before the
     * book lock date are left alone.
     *
     * @param  array<int, int>  $clientIds
     */
    public function rebuild(array $clientIds): void
    {
        $lock = Setting::get('lock_date');

        // System bookkeeping: not worth a log line per day per client.
        activity()->withoutLogging(fn () => DB::transaction(function () use ($clientIds, $lock) {
            foreach ($clientIds as $clientId) {
                $wanted = [];

                $campaigns = Campaign::with(['account', 'days'])->where('client_id', $clientId)->whereNotNull('bill_from')->get();

                foreach ($campaigns as $campaign) {
                    $platform = Account::where('kind', 'platform')->where('code', $campaign->account->platform ?? 'meta')->first()
                        ?? Account::where('code', 'meta')->firstOrFail();

                    foreach ($campaign->days as $day) {
                        if ($day->spend <= 0 || $day->day->lt($campaign->bill_from)) {
                            continue;
                        }

                        $key = "pilot:{$clientId}:{$platform->id}:{$day->day->toDateString()}";
                        $wanted[$key] ??= ['platform' => $platform, 'day' => $day->day, 'spend' => 0, 'campaigns' => []];
                        $wanted[$key]['spend'] += $day->spend;
                        $wanted[$key]['campaigns'][$campaign->name] = true;
                    }
                }

                $client = Client::withTrashed()->find($clientId);
                $existing = UsdEntry::where('source', 'pilot')->where('client_id', $clientId)->get()->keyBy('source_key');

                foreach ($wanted as $key => $w) {
                    if ($lock && $w['day']->toDateString() <= $lock) {
                        continue;
                    }

                    $values = [
                        'entry_date' => $w['day']->toDateString(),
                        'description' => ($client->name ?? 'Client').' — '.$w['platform']->name.' spend',
                        'category' => 'client_spend',
                        'account_id' => $w['platform']->id,
                        'client_id' => $clientId,
                        'amount' => -$w['spend'],
                        'notes' => implode(', ', array_keys($w['campaigns'])),
                    ];

                    $row = $existing[$key] ?? new UsdEntry(['status' => 'pending']);
                    $row->fill($values);
                    $row->forceFill(['source' => 'pilot', 'source_key' => $key]);

                    if ($row->isDirty()) {
                        $row->save();
                    }
                }

                foreach ($existing as $key => $row) {
                    if (! isset($wanted[$key]) && ! ($lock && $row->entry_date && $row->entry_date->toDateString() <= $lock)) {
                        $row->forceDelete();
                    }
                }

                $this->fund($clientId);
            }
        }));
    }

    /**
     * Marks the client's Pilot spend rows Settled, oldest first, as far as
     * the USD credit of their payments reaches; the rest are Pending.
     */
    public function fund(int $clientId): void
    {
        $credit = (int) BdtEntry::where('client_id', $clientId)->whereNotNull('usd_credit')->sum('usd_credit');
        $used = 0;

        foreach (UsdEntry::where('source', 'pilot')->where('client_id', $clientId)->ledgerOrder()->get() as $row) {
            $used += -$row->amount;
            $status = $used <= $credit ? 'settled' : 'pending';

            if ($row->status !== $status) {
                activity()->withoutLogging(fn () => $row->forceFill(['status' => $status])->save());
            }
        }
    }
}
