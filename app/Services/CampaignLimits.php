<?php

namespace App\Services;

use App\Models\Campaign;
use App\Models\CampaignDay;
use App\Models\Client;
use App\Models\Renewal;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\AgencyAlert;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

/**
 * Prepaid campaigns stop when the client's money does. Meta enforces it:
 * each linked campaign of a client who pays in advance gets a spend cap of
 * what it spent before billing began, plus what it has been billed, plus
 * its share of the client's USD credit still left. When nothing is left the
 * campaign is paused; only an extension (an admin's, or an approved
 * renewal) moves its end later and switches it back on.
 *
 * Only Meta ad accounts in USD: the books count spend in USD cents.
 */
class CampaignLimits
{
    /** How often Meta's own budget, cap and end are read again. */
    public const REFRESH_HOURS = 6;

    /** A cap that moved less than this (cents) is not worth a call to Meta. */
    public const CAP_STEP = 50;

    public const PAUSED_TITLE = 'Client-এর টাকা শেষ — campaign বন্ধ করা হয়েছে'; // i18n

    public const CLIENT_TITLE = 'Client নিজে campaign বদলেছেন'; // i18n

    public function __construct(private PilotClient $pilot, private Books $books, private Notifier $notifier) {}

    public static function enabled(): bool
    {
        return Setting::get('credit_guard', '1') === '1';
    }

    public static function guarded(Campaign $campaign): bool
    {
        $account = $campaign->account;

        return $account !== null && $account->platform === 'meta' && strtoupper((string) ($account->currency ?? 'USD')) === 'USD';
    }

    /**
     * Caps or pauses every prepaid client's linked campaigns. Runs after each
     * spend sync, since that is when the money left changes.
     *
     * @return array{capped: int, paused: int}
     */
    public function guard(): array
    {
        $done = ['capped' => 0, 'paused' => 0];

        if (! self::enabled() || ! PilotClient::connected() || Cache::get('pilot:limits:denied')) {
            return $done;
        }

        $figures = collect($this->books->clients())->keyBy('id');
        $linked = Campaign::with('account')->whereNotNull('client_id')->whereNotNull('bill_from')->get()
            ->filter(fn (Campaign $c) => self::guarded($c));

        foreach ($linked->groupBy('client_id') as $clientId => $campaigns) {
            $client = $figures[$clientId] ?? null;

            // Clients who pay after the spend are billed, not stopped.
            if ($client === null || $client['credit_usd'] <= 0) {
                continue;
            }

            try {
                foreach ($campaigns as $campaign) {
                    $this->refresh($campaign);
                }

                $running = $campaigns->filter(fn (Campaign $c) => $c->isLive());
                $left = (int) $client['credit_left'];

                if ($left <= 0) {
                    foreach ($running as $campaign) {
                        $this->pause($campaign, (string) $client['name']);
                        $done['paused']++;
                    }

                    continue;
                }

                foreach ($this->shares(array_values($running->all()), $left) as [$campaign, $share]) {
                    $billed = (int) CampaignDay::where('campaign_id', $campaign->id)->where('day', '>=', $campaign->bill_from->toDateString())->sum('spend');
                    $done['capped'] += $this->cap($campaign, (int) ($campaign->spent_before ?? 0) + $billed + $share) ? 1 : 0;
                }

                $done['extended'] = ($done['extended'] ?? 0) + $this->runOut($campaigns, $client, $left);
            } catch (RuntimeException $e) {
                if (str_contains($e->getMessage(), 'write:campaigns')) {
                    // The Pilot token may only read: say so once a day, not every tick.
                    Cache::put('pilot:limits:denied', true, 3600);
                    Setting::putQuietly('credit_guard_error', $e->getMessage());

                    return $done;
                }

                report($e);
            }
        }

        Setting::putQuietly('credit_guard_error', null);

        return $done;
    }

    /**
     * Reads Meta's budget, cap and end of a campaign again when it is time
     * (or when $force), and what it spent before billing began.
     *
     * @throws RuntimeException when Pilot refuses
     */
    public function refresh(Campaign $campaign, bool $force = false): Campaign
    {
        if (! $force && $campaign->limits_at !== null && $campaign->limits_at->gt(now()->subHours(self::REFRESH_HOURS))) {
            return $campaign;
        }

        $before = $campaign->spent_before === null && $campaign->bill_from !== null
            && ($campaign->start_at === null || $campaign->bill_from->gt($campaign->start_at->startOfDay()));
        $read = $this->pilot->limits($campaign->account->pilot_id, $campaign->pilot_campaign_id, $before ? ['spent_before' => $campaign->bill_from->toDateString()] : []);

        $this->remember($campaign, $read);

        if ($campaign->spent_before === null && $campaign->bill_from !== null) {
            $campaign->spent_before = $before ? self::cents($read['spent_before'] ?? 0) : 0;
        }

        $campaign->saveQuietly();
        $this->stampBudget($campaign);

        return $campaign;
    }

    /**
     * Pays for more days: the end moves to $days after the later of today
     * and the current end, and a campaign paused for lack of money runs again.
     *
     * @throws RuntimeException when Pilot or Meta refuses
     */
    public function extend(Campaign $campaign, int $days, ?User $by = null, ?string $reason = null): CarbonImmutable
    {
        $campaign->loadMissing('account');
        $today = CarbonImmutable::now()->startOfDay();
        $end = $campaign->lastDay() ?? $today;
        $newEnd = $end->max($today)->addDays($days);

        $change = ['new_end' => $newEnd->toDateString(), 'reason' => $reason ?? 'KlokOne'];

        if ($campaign->paused_for_credit_at !== null) {
            $change['active'] = true;
        }

        $read = $this->pilot->limits($campaign->account->pilot_id, $campaign->pilot_campaign_id, $change);
        $this->remember($campaign, $read);
        $campaign->paused_for_credit_at = null;
        $campaign->limits_at = null; // read again on the next guard, with the new credit
        $campaign->saveQuietly();

        activity('Campaign')->performedOn($campaign)->causedBy($by)
            ->withProperties(['days' => $days, 'new_end' => $newEnd->toDateString(), 'reason' => $reason])
            ->log('extended');

        return $newEnd;
    }

    /** Credit under this is neither run on nor refunded: a dollar. */
    public const RUN_OUT_MIN = 100;

    public const RUN_OUT_TITLE = 'বাকি credit দিয়ে campaign বাড়ানো হলো'; // i18n

    /**
     * A client's credit is spent to the last dollar: when a paid campaign reaches its end with a
     * dollar or more still unspent, the end moves on by as many days as that credit buys and the
     * spend cap stops it exactly where the credit ends. Not for a campaign the client or the
     * team paused, nor one whose renewal the client declined; under a dollar nothing moves.
     *
     * @param  Collection<int, Campaign>  $campaigns  the client's guarded campaigns
     * @param  array<string, mixed>  $client  the client's figures
     * @return int how many were moved on
     */
    private function runOut(Collection $campaigns, array $client, int $left): int
    {
        if ($left < self::RUN_OUT_MIN) {
            return 0;
        }
        $today = CarbonImmutable::now()->startOfDay();
        $ending = $campaigns->filter(function (Campaign $c) use ($today) {
            $last = $c->lastDay();

            return $last !== null && $last->lte($today) && $last->gte($today->subDay())
                && $c->paused_by_client_at === null && $c->paused_by_team_at === null && ! $c->hasEnded()
                && ! Renewal::where('campaign_id', $c->id)->where('status', 'declined')->whereDate('ends_on', $last->toDateString())->exists();
        })->values();
        if ($ending->isEmpty()) {
            return 0;
        }

        $daily = max(1, (int) $ending->sum(fn (Campaign $c) => max(0, (int) $c->dailyBudget())) ?: $left);
        $days = max(1, min(30, (int) ceil($left / $daily)));
        $moved = 0;

        foreach ($ending as $campaign) {
            $stop = CarbonImmutable::parse($campaign->stop_at);
            if (! Cache::add("credit-runout:{$campaign->id}:{$stop->timestamp}", true, now()->addDays(3))) {
                continue;
            }
            $newEnd = $this->extend($campaign, $days, null, 'credit left');
            $moved++;

            $params = ['campaign' => $campaign->name, 'client' => (string) $client['name'], 'left' => CampaignAlerts::money($left, $campaign->account?->currency), 'days' => $days, 'date' => $newEnd->format('j M')];
            app(ClientNotifier::class)->send($campaign->client_id, new AgencyAlert('client_extended', ClientNotifier::EXTENDED_TITLE, '{campaign} — আপনার বাকি {left} credit দিয়ে আরও প্রায় {days} দিন চলবে ({date} পর্যন্ত); credit শেষ হলে নিজে থামবে।', $params, // i18n
                ['url' => '/portal/campaigns/'.($campaign->slug ?? $campaign->id)]));
            $this->notifier->send(Client::whereKey($campaign->client_id)->value('manager_id'), new AgencyAlert('credit_runout', self::RUN_OUT_TITLE, '{client} · {campaign} — বাকি {left} দিয়ে {days} দিন, {date} পর্যন্ত', $params, // i18n
                ['url' => '/campaigns/'.($campaign->slug ?? $campaign->id)]));
        }

        return $moved;
    }

    /** The fewest and most dollars a day a client may set on the portal. */
    public const CLIENT_BUDGET_MIN = 100;

    public const CLIENT_BUDGET_MAX = 100000;

    /**
     * The client pauses their own campaign; it stays off until they (or the team) switch it on.
     *
     * @throws RuntimeException when Pilot or Meta refuses, or it is not running
     */
    public function clientPause(Campaign $campaign, User $by): void
    {
        if (! $campaign->isLive()) {
            throw new RuntimeException(__('Campaign এখন চলছে না।'));
        }

        $read = $this->pilot->limits($campaign->account->pilot_id, $campaign->pilot_campaign_id, ['active' => false, 'reason' => 'Paused by the client on the portal (KlokOne)']);
        $this->remember($campaign, $read);
        $campaign->paused_by_client_at = now();
        $campaign->saveQuietly();
        $this->clientDid($campaign, $by, 'paused', __('থামিয়েছেন'));
    }

    /**
     * The client switches their paused campaign on again — only one they paused themselves, with
     * money left for it; one stopped for want of money runs again once paid. A client with no
     * paid money (one who pays after the spend) asks the team: guard() puts no spend cap on
     * their campaigns, so switching on here would spend the agency's money with no limit.
     *
     * @throws RuntimeException when there is no money left, or Pilot or Meta refuses
     */
    public function clientResume(Campaign $campaign, User $by): void
    {
        if ($campaign->paused_by_client_at === null || $campaign->paused_by_team_at !== null || $campaign->isLive() || $campaign->hasEnded()) {
            throw new RuntimeException(__('এই campaign এখান থেকে চালু করা যায় না।'));
        }

        $figure = collect($this->books->clients())->firstWhere('id', $campaign->client_id) ?? [];

        // No money left (or none ever paid ahead): pay first, or the team switches it on.
        if ((int) ($figure['credit_left'] ?? 0) <= 0) {
            throw new RuntimeException(__('বিজ্ঞাপনের টাকা শেষ — আগে pay করুন, তারপর চালু হবে।'));
        }

        $read = $this->pilot->limits($campaign->account->pilot_id, $campaign->pilot_campaign_id, ['active' => true, 'reason' => 'Switched on by the client on the portal (KlokOne)']);
        $this->remember($campaign, $read);
        $campaign->paused_by_client_at = null;
        $campaign->limits_at = null; // the next guard caps it at the money left
        $campaign->saveQuietly();
        $this->clientDid($campaign, $by, 'resumed', __('আবার চালু করেছেন'));
    }

    /**
     * The most a day a prepaid client's campaign may take: the money left must carry it, and the
     * client's other running campaigns, to its last day. Null when the client pays after the spend.
     */
    public function budgetRoom(Campaign $campaign): ?int
    {
        $figure = collect($this->books->clients())->firstWhere('id', $campaign->client_id) ?? [];

        if ((int) ($figure['credit_usd'] ?? 0) <= 0) {
            return null;
        }

        $last = $campaign->lastDay();
        $days = $last === null ? 1 : max(1, (int) CarbonImmutable::now()->startOfDay()->diffInDays($last, false) + 1);
        $others = Campaign::where('client_id', $campaign->client_id)->whereKeyNot($campaign->id)->running()->get()
            ->sum(fn (Campaign $c) => (int) $c->dailyBudget());

        return max(0, intdiv((int) ($figure['credit_left'] ?? 0) - $others * $days, $days));
    }

    /**
     * The client sets the budget a day (cents, USD). Lowering it is always allowed; raising it
     * only as far as the money left carries the campaign to its last day — more needs paying first,
     * so a campaign never runs on money not paid. A client who pays after the spend has no paid
     * money to raise it on (and no spend cap): they may lower it, the team raises it.
     *
     * @throws RuntimeException when the money does not carry it, or Pilot or Meta refuses
     */
    public function clientBudget(Campaign $campaign, int $cents, User $by): void
    {
        if ($campaign->hasEnded()) {
            throw new RuntimeException(__('Campaign শেষ হয়ে গেছে।'));
        }

        $was = $campaign->dailyBudget();
        // Null room (pays after the spend) is no room at all, not no limit.
        $room = $this->budgetRoom($campaign) ?? 0;

        if ($cents > (int) $was && $cents > $room) {
            throw new RuntimeException($room >= self::CLIENT_BUDGET_MIN
                ? __('আপনার বাকি টাকায় শেষ দিন পর্যন্ত দিনে সর্বোচ্চ :max চলবে। বেশি চাইলে আগে pay করুন।', ['max' => '$'.number_format($room / 100, 2)])
                : __('বাকি টাকায় budget বাড়ানো যাবে না — আগে pay করুন।'));
        }

        $read = $this->pilot->limits($campaign->account->pilot_id, $campaign->pilot_campaign_id, ['daily_budget' => round($cents / 100, 2), 'reason' => 'Budget set by the client on the portal (KlokOne)']);
        $this->remember($campaign, $read);
        $campaign->daily_budget = $cents;
        $campaign->saveQuietly();
        $this->stampBudget($campaign);
        $this->clientDid($campaign, $by, 'budget', __('দৈনিক budget :from → :to করেছেন', [
            'from' => $was !== null ? '$'.number_format($was / 100, 2) : '—', 'to' => '$'.number_format($cents / 100, 2),
        ]));
    }

    /**
     * The team switches a campaign off or on from KlokOne. Off by the team is its own state, so the
     * client sees "paused by Klok Ads" (not a problem, not their own pause) and is told either way.
     *
     * @throws RuntimeException when Pilot or Meta refuses
     */
    public function teamSetActive(Campaign $campaign, bool $on, User $by): void
    {
        if ($campaign->hasEnded()) {
            throw new RuntimeException(__('Campaign শেষ হয়ে গেছে।'));
        }

        $read = $this->pilot->limits($campaign->account->pilot_id, $campaign->pilot_campaign_id, ['active' => $on, 'reason' => ($on ? 'Switched on' : 'Paused').' by '.$by->name.' in KlokOne']);
        $this->remember($campaign, $read);
        $campaign->forceFill($on
            ? ['paused_by_team_at' => null, 'paused_by_client_at' => null, 'paused_for_credit_at' => null, 'limits_at' => null]
            : ['paused_by_team_at' => now(), 'paused_by_client_at' => null])->saveQuietly();

        activity('Campaign')->performedOn($campaign)->causedBy($by)->log($on ? 'switched on' : 'paused');

        if ($campaign->client_id !== null) {
            app(ClientNotifier::class)->send($campaign->client_id, new AgencyAlert($on ? 'client_back' : 'client_team_paused', $on ? ClientNotifier::BACK_TITLE : ClientNotifier::TEAM_PAUSED_TITLE, $on ? '{campaign} — আবার চলছে' : '{campaign} — Klok Ads থামিয়ে রেখেছে; এ সময় টাকা খরচ হচ্ছে না', [ // i18n
                'campaign' => $campaign->name,
            ], ['url' => '/portal/campaigns/'.($campaign->slug ?? $campaign->id)]));
        }
    }

    /**
     * The team sets the budget a day (cents). Not held to the money left as a client is: the spend
     * cap still stops the campaign where the client's money ends.
     *
     * @throws RuntimeException when Pilot or Meta refuses
     */
    public function teamBudget(Campaign $campaign, int $cents, User $by): void
    {
        if ($campaign->hasEnded()) {
            throw new RuntimeException(__('Campaign শেষ হয়ে গেছে।'));
        }

        $was = $campaign->dailyBudget();
        $read = $this->pilot->limits($campaign->account->pilot_id, $campaign->pilot_campaign_id, ['daily_budget' => round($cents / 100, 2), 'reason' => 'Budget set by '.$by->name.' in KlokOne']);
        $this->remember($campaign, $read);
        $campaign->daily_budget = $cents;
        $campaign->saveQuietly();
        $this->stampBudget($campaign);

        activity('Campaign')->performedOn($campaign)->causedBy($by)
            ->withProperties(['from' => $was, 'to' => $cents])->log('budget');

        if ($campaign->client_id !== null && $was !== $cents) {
            $currency = $campaign->account?->currency;
            app(ClientNotifier::class)->send($campaign->client_id, new AgencyAlert('client_budget', ClientNotifier::BUDGET_TITLE, '{campaign} — দিনে {from} → {to}', [ // i18n
                'campaign' => $campaign->name,
                'from' => CampaignAlerts::money((int) $was, $currency),
                'to' => CampaignAlerts::money($cents, $currency),
            ], ['url' => '/portal/campaigns/'.($campaign->slug ?? $campaign->id)]));
        }
    }

    /** What the client did, in the log and to the people who run the ads. */
    private function clientDid(Campaign $campaign, User $by, string $what, string $said): void
    {
        $campaign->loadMissing('client');
        activity('Campaign')->performedOn($campaign)->causedBy($by)->withProperties(['by_client' => true])->log('client '.$what);
        $this->notifier->send($campaign->client?->manager_id, new AgencyAlert('client_campaign', self::CLIENT_TITLE, '{client} · {campaign}: {who} {did}', [
            'client' => $campaign->client?->name, 'campaign' => $campaign->name, 'who' => $by->name, 'did' => $said,
        ], ['url' => '/campaigns?account='.$campaign->pilot_account_id]));
    }

    /**
     * Splits the money left between the running campaigns by their daily
     * budget (equally when none is known); the last one takes the rounding.
     *
     * @param  list<Campaign>  $campaigns
     * @return list<array{0: Campaign, 1: int}>
     */
    private function shares(array $campaigns, int $left): array
    {
        if ($campaigns === []) {
            return [];
        }

        $weights = array_map(fn (Campaign $c) => max(0, (int) $c->dailyBudget()), $campaigns);
        $sum = array_sum($weights);
        $given = 0;
        $out = [];

        foreach ($campaigns as $i => $campaign) {
            $share = $i === count($campaigns) - 1
                ? $left - $given
                : (int) floor($left * ($sum > 0 ? $weights[$i] / $sum : 1 / count($campaigns)));
            $given += $share;
            $out[] = [$campaign, $share];
        }

        return $out;
    }

    private function cap(Campaign $campaign, int $cap): bool
    {
        if ($campaign->spend_cap !== null && abs($campaign->spend_cap - $cap) < self::CAP_STEP) {
            return false;
        }

        $read = $this->pilot->limits($campaign->account->pilot_id, $campaign->pilot_campaign_id, ['spend_cap' => round($cap / 100, 2), 'reason' => 'Client credit (KlokOne)']);
        $this->remember($campaign, $read);
        $campaign->limits_error = $read['spend_cap_error'] ?? null;
        $campaign->saveQuietly();

        return ($read['spend_cap_error'] ?? null) === null;
    }

    private function pause(Campaign $campaign, string $clientName): void
    {
        $read = $this->pilot->limits($campaign->account->pilot_id, $campaign->pilot_campaign_id, ['active' => false, 'reason' => 'Client credit used up (KlokOne)']);
        $this->remember($campaign, $read);
        $campaign->paused_for_credit_at = now();
        $campaign->saveQuietly();

        activity('Campaign')->performedOn($campaign)->log('paused: credit used up');
        $this->notifier->send($campaign->client?->manager_id, new AgencyAlert('credit_out', self::PAUSED_TITLE, '{client} · {campaign}', [
            'client' => $clientName, 'campaign' => $campaign->name,
        ], ['url' => '/campaigns?account='.$campaign->pilot_account_id]));
        app(ClientNotifier::class)->send($campaign->client_id, new AgencyAlert('client_paused', ClientNotifier::PAUSED_TITLE, '{campaign} — pay করলেই আবার চালু হবে', [ // i18n
            'campaign' => $campaign->name,
        ], ['url' => '/portal/invoices']));
    }

    /**
     * @param  array<string, mixed>  $read  a set_campaign_limits answer
     */
    private function remember(Campaign $campaign, array $read): void
    {
        $campaign->fill([
            'budget' => isset($read['daily_budget']) ? self::cents($read['daily_budget']) : $campaign->budget,
            'spend_cap' => isset($read['spend_cap']) ? self::cents($read['spend_cap']) : null,
            'stop_at' => array_key_exists('end', $read) ? $read['end'] : $campaign->stop_at,
            'status' => isset($read['active']) ? ($read['active'] ? 'ACTIVE' : 'PAUSED') : $campaign->status,
            'limits_at' => now(),
            'limits_error' => $read['why_not'] ?? null,
        ]);
    }

    /** Today's row (and yesterday's, if it has none) keeps the budget of the day. */
    private function stampBudget(Campaign $campaign): void
    {
        if ($campaign->budget === null) {
            return;
        }

        CampaignDay::where('campaign_id', $campaign->id)->whereNull('budget')
            ->whereDate('day', '>=', now()->subDay()->toDateString())
            ->update(['budget' => $campaign->budget]);
    }

    private static function cents(mixed $amount): int
    {
        return (int) round((float) $amount * 100);
    }
}
