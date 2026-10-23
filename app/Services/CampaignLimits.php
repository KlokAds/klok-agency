<?php

namespace App\Services;

use App\Models\Campaign;
use App\Models\CampaignDay;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\AgencyAlert;
use Carbon\CarbonImmutable;
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

        $change = ['new_end' => $newEnd->toDateString(), 'reason' => $reason ?? 'Klok Finance'];

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

        $read = $this->pilot->limits($campaign->account->pilot_id, $campaign->pilot_campaign_id, ['spend_cap' => round($cap / 100, 2), 'reason' => 'Client credit (Klok Finance)']);
        $this->remember($campaign, $read);
        $campaign->limits_error = $read['spend_cap_error'] ?? null;
        $campaign->saveQuietly();

        return ($read['spend_cap_error'] ?? null) === null;
    }

    private function pause(Campaign $campaign, string $clientName): void
    {
        $read = $this->pilot->limits($campaign->account->pilot_id, $campaign->pilot_campaign_id, ['active' => false, 'reason' => 'Client credit used up (Klok Finance)']);
        $this->remember($campaign, $read);
        $campaign->paused_for_credit_at = now();
        $campaign->saveQuietly();

        activity('Campaign')->performedOn($campaign)->log('paused: credit used up');
        $this->notifier->send($campaign->client?->manager_id, new AgencyAlert('credit_out', self::PAUSED_TITLE, '{client} · {campaign}', [
            'client' => $clientName, 'campaign' => $campaign->name,
        ], ['url' => '/campaigns?account='.$campaign->pilot_account_id]));
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
