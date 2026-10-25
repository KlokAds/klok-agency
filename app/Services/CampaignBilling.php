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
use App\Notifications\AgencyAlert;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Bills clients by campaign. Campaigns and their daily spend come from Klok
 * Pilot; the spend of every campaign linked to a client, from its bill_from
 * day on, becomes one "client ad spend" row per client per week in the USD
 * book (source "pilot"): seven days one after another from the client's
 * first billed day, the days a weekly payment covers. Those rows are then funded, oldest first, by the
 * USD credit the client's payments bought: funded rows are Settled, the
 * rest stay Pending until the client pays.
 */
class CampaignBilling
{
    /** How far back each sync re-reads spend (Meta adjusts recent days). */
    public const LOOKBACK_DAYS = 35;

    public const CHANGED_TITLE = 'Campaign বদলেছে — budget, শেষের তারিখ বা চালু/বন্ধ'; // i18n

    public const PROBLEM_TITLE = 'Ad account-এ সমস্যা — client-দের ad থেমে আছে'; // i18n

    public function __construct(private PilotClient $pilot) {}

    /**
     * What changed on a campaign Pilot just reported, in plain words: the daily
     * budget, the end date (an extension), on or off. Pauses the credit guard
     * made itself are left out — they have their own notification.
     *
     * @return list<string>
     */
    public static function changes(Campaign $campaign): array
    {
        $out = [];
        $usd = fn (?int $cents) => $cents === null ? '—' : '$'.number_format($cents / 100, 2);
        $day = fn ($at) => $at === null ? __('শেষ নেই') : CarbonImmutable::parse($at)->format('j M');

        if ($campaign->isDirty('daily_budget')) {
            $out[] = __('দৈনিক budget :from → :to', ['from' => $usd($campaign->getOriginal('daily_budget')), 'to' => $usd($campaign->daily_budget)]);
        }

        if ($campaign->isDirty('stop_at') && $day($campaign->getOriginal('stop_at')) !== $day($campaign->stop_at)) {
            $out[] = __('শেষ :from → :to', ['from' => $day($campaign->getOriginal('stop_at')), 'to' => $day($campaign->stop_at)]);
        }

        if ($campaign->isDirty('status')) {
            $was = in_array($campaign->getOriginal('status'), Campaign::LIVE, true);
            $now = in_array($campaign->status, Campaign::LIVE, true);
            $byGuard = $campaign->paused_for_credit_at !== null && $campaign->paused_for_credit_at->gt(now()->subHours(2));

            if ($was !== $now && ! (! $now && $byGuard)) {
                $out[] = $now ? __('আবার চালু') : __('বন্ধ (paused)');
            }
        }

        return $out;
    }

    /**
     * Copies one ad account's campaigns in from Pilot (name, status, spend so far).
     *
     * @throws RuntimeException when Pilot cannot be reached
     */
    public function refreshCampaigns(PilotAccount $account): int
    {
        $report = $this->pilot->campaigns($account->pilot_id);
        $count = 0;

        if (array_key_exists('account_problem', $report) && $report['account_problem'] !== $account->problem) {
            $was = $account->problem;
            $account->forceFill(['problem' => $report['account_problem'], 'problem_since' => $report['account_problem'] ? now() : null])->saveQuietly();
            $this->accountProblem($account, $was);
        }

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

            $changes = $campaign->exists ? self::changes($campaign) : [];
            $held = $campaign->exists && $campaign->client_id !== null ? $this->heldChange($campaign) : null;

            if ($campaign->isDirty()) {
                $campaign->saveQuietly();
            }

            // A client's campaign changed on Meta (budget, end date, on/off): the people who run
            // the ads and keep the client's money hear what changed.
            if ($changes !== [] && $campaign->client_id !== null) {
                $campaign->loadMissing('client');
                app(Notifier::class)->send($campaign->client?->manager_id, new AgencyAlert('campaign_changed', self::CHANGED_TITLE, '{client} · {campaign}: {changes}', [
                    'client' => $campaign->client?->name,
                    'campaign' => $campaign->name,
                    'changes' => implode(' · ', $changes),
                ], ['url' => '/campaigns']));
            }

            if ($held !== null) {
                $this->tellClient($campaign, $held);
            }

            $count++;
        }

        return $count;
    }

    /**
     * Switched off on Meta by someone other than the client or the money guard (and not at its
     * end): 'held'. Back on: 'back' — and a client's own pause is over, since the team or Meta
     * switched it on.
     */
    private function heldChange(Campaign $campaign): ?string
    {
        if (! $campaign->isDirty('status')) {
            return null;
        }

        $was = in_array(strtoupper((string) $campaign->getOriginal('status')), Campaign::LIVE, true);
        $now = in_array(strtoupper((string) $campaign->status), Campaign::LIVE, true);

        if ($now && ! $was) {
            $mine = $campaign->paused_by_client_at !== null || $campaign->paused_for_credit_at !== null || $campaign->paused_by_team_at !== null;
            $campaign->paused_by_client_at = null;
            $campaign->paused_by_team_at = null;
            $campaign->saveQuietly();

            return $mine ? null : 'back';
        }

        return $was && ! $now && ! $campaign->hasEnded() && $campaign->paused_by_client_at === null && $campaign->paused_for_credit_at === null && $campaign->paused_by_team_at === null ? 'held' : null;
    }

    /** The account itself stopped (or started) on Meta: the team, and every client running ads on it. */
    private function accountProblem(PilotAccount $account, ?string $was): void
    {
        if ($account->problem !== null) {
            app(Notifier::class)->send(null, new AgencyAlert('account_problem', self::PROBLEM_TITLE, $account->problem === 'payment' ? '{account} — Meta টাকা কাটতে পারেনি, card/billing দেখুন' : '{account} — Meta account বন্ধ বা review-তে', [ // i18n
                'account' => $account->name,
            ], ['url' => '/campaigns?account='.$account->id]));
        }

        $campaigns = Campaign::where('pilot_account_id', $account->id)->whereNotNull('client_id')->running()->get();

        foreach ($campaigns as $campaign) {
            $this->tellClient($campaign, $account->problem !== null ? 'held' : ($was !== null ? 'back' : null));
        }
    }

    private function tellClient(Campaign $campaign, ?string $what): void
    {
        if ($what === null) {
            return;
        }

        app(ClientNotifier::class)->send($campaign->client_id, $what === 'held'
            ? new AgencyAlert('client_held', ClientNotifier::HELD_TITLE, '{campaign} — Klok Ads ঠিক করছে; এ সময় আপনার টাকা খরচ হচ্ছে না', ['campaign' => $campaign->name], ['url' => '/portal/campaigns/'.$campaign->id]) // i18n
            : new AgencyAlert('client_back', ClientNotifier::BACK_TITLE, '{campaign} — আবার চলছে', ['campaign' => $campaign->name], ['url' => '/portal/campaigns/'.$campaign->id])); // i18n
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

    /**
     * The first day a campaign's spend goes to a client: the campaign's own
     * start, unless that client's spend up to some later day was already
     * written by hand — then the day after, so nothing is billed twice.
     */
    public function defaultBillFrom(Campaign $campaign, int $clientId): CarbonImmutable
    {
        $start = $campaign->start_at?->startOfDay()
            ?? CampaignDay::where('campaign_id', $campaign->id)->min('day')
            ?? $campaign->last_spend_day
            ?? CarbonImmutable::now();
        $start = CarbonImmutable::parse($start)->startOfDay();

        $lastByHand = UsdEntry::where('client_id', $clientId)->where('category', 'client_spend')
            ->whereNull('source')->max('entry_date');

        if ($lastByHand !== null && CarbonImmutable::parse($lastByHand)->gte($start)) {
            return CarbonImmutable::parse($lastByHand)->addDay()->startOfDay();
        }

        return $start;
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

            // Recent days are read again (Meta adjusts them); a campaign seen for the first
            // time is read in full from its start, so its client sees its whole life (only
            // days from bill_from on are billed).
            $from = $campaigns->map(function (Campaign $c) use ($today) {
                $billFrom = CarbonImmutable::parse($c->bill_from);

                return CampaignDay::where('campaign_id', $c->id)->exists()
                    ? $today->subDays(self::LOOKBACK_DAYS)->max($billFrom)
                    : self::lifeStart($c, $today);
            })->min();
            $days += $this->readDays($account, $campaigns, $from, $today);
        }

        $clients = $linked->map(fn (Campaign $c) => (int) $c->client_id)->unique()->values()->all();
        $this->rebuild($clients);
        Setting::putQuietly('pilot_spend_synced_at', now()->toIso8601String());

        return ['campaigns' => $linked->count(), 'days' => $days, 'clients' => count($clients)];
    }

    /** Where a campaign's numbers begin: its start, or its bill-from day; at most three years back. */
    public static function lifeStart(Campaign $campaign, CarbonImmutable $today): CarbonImmutable
    {
        $start = $campaign->start_at ? CarbonImmutable::parse($campaign->start_at)->startOfDay() : null;
        $billFrom = CarbonImmutable::parse($campaign->bill_from);

        return ($start !== null ? $start->min($billFrom) : $billFrom)->max($today->subDays(1095));
    }

    /**
     * Reads every linked campaign's whole life again from its start, for the clients' view of
     * their campaigns (billing still counts only from bill_from).
     *
     * @return int days stored
     *
     * @throws RuntimeException when Pilot cannot be reached
     */
    public function readHistory(): int
    {
        $today = CarbonImmutable::now()->startOfDay();
        $days = 0;

        foreach (Campaign::with('account')->whereNotNull('client_id')->whereNotNull('bill_from')->get()->groupBy('pilot_account_id') as $campaigns) {
            $from = $campaigns->map(fn (Campaign $c) => self::lifeStart($c, $today))->min();
            $days += $this->readDays($campaigns->first()->account, $campaigns, $from, $today);
        }

        return $days;
    }

    /**
     * Stores the day-by-day spend of some campaigns of one ad account
     * between two days, as Pilot reports it (at most 92 days a call).
     *
     * @param  iterable<Campaign>  $campaigns
     *
     * @throws RuntimeException when Pilot cannot be reached
     */
    public function readDays(PilotAccount $account, iterable $campaigns, CarbonImmutable $from, CarbonImmutable $to): int
    {
        $campaigns = collect($campaigns);
        $ids = $campaigns->pluck('id', 'pilot_campaign_id');
        $pilotIds = $campaigns->map(fn (Campaign $c) => $c->pilot_campaign_id)->values()->all();
        $yesterday = CarbonImmutable::now()->subDay()->toDateString();
        $days = 0;

        for ($chunk = $from; $chunk->lte($to); $chunk = $chunk->addDays(90)) {
            $until = $chunk->addDays(89)->min($to);
            $report = $this->pilot->dailySpend($account->pilot_id, $chunk->toDateString(), $until->toDateString(), $pilotIds);

            foreach ($report['rows'] ?? [] as $row) {
                $campaignId = $ids[(int) $row['campaign_id']] ?? null;

                if ($campaignId === null) {
                    continue;
                }

                $day = CampaignDay::updateOrCreate(
                    ['campaign_id' => $campaignId, 'day' => $row['date']],
                    ['spend' => (int) round((float) $row['spend'] * 100), 'results' => (int) ($row['results'] ?? 0), 'clicks' => (int) ($row['clicks'] ?? 0)],
                );

                // The budget is known only as it is now, so only recent days keep it.
                $budget = $campaigns->firstWhere('id', $campaignId)?->dailyBudget();
                if ($day->budget === null && $budget !== null && $row['date'] >= $yesterday) {
                    $day->forceFill(['budget' => $budget])->save();
                }
                $days++;
            }
        }

        return $days;
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
                $billed = $campaigns->flatMap(fn (Campaign $c) => $c->days->filter(fn (CampaignDay $d) => $d->spend > 0 && $d->day->gte($c->bill_from)));
                $weeks = $this->weeks($clientId, $billed->min('day'));

                foreach ($campaigns as $campaign) {
                    $platform = Account::where('kind', 'platform')->where('code', $campaign->account->platform ?? 'meta')->first()
                        ?? Account::where('code', 'meta')->firstOrFail();

                    foreach ($campaign->days as $day) {
                        if ($day->spend <= 0 || $day->day->lt($campaign->bill_from)) {
                            continue;
                        }

                        [$from, $to] = $weeks($day->day);
                        $key = "pilot:{$clientId}:{$platform->id}:{$from->toDateString()}:{$to->toDateString()}";
                        $wanted[$key] ??= ['platform' => $platform, 'day' => $from, 'to' => $to, 'spend' => 0, 'campaigns' => []];
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
                        'description' => ($client->name ?? 'Client').' — '.$w['platform']->name.' spend · '.$w['day']->format('j M').' – '.$w['to']->format('j M'),
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
     * The client's billing weeks: seven days one after another from the
     * first day billed to them, so a week is the seven days a payment buys
     * (a client paid up to the 18th pays on the 20th for the 19th–25th).
     *
     * @return callable(CarbonImmutable): array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function weeks(int $clientId, mixed $firstDay): callable
    {
        $first = $firstDay !== null ? CarbonImmutable::parse($firstDay)->startOfDay() : null;

        return function (CarbonImmutable $day) use ($first): array {
            $day = $day->startOfDay();
            $start = $first !== null && $first->lte($day) ? $first : $day;
            $from = $start->addDays(7 * intdiv((int) $start->diffInDays($day), 7));

            return [$from, $from->addDays(6)];
        };
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
