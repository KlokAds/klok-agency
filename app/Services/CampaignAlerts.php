<?php

namespace App\Services;

use App\Models\Campaign;
use App\Models\CampaignDay;
use App\Models\Client;
use App\Models\Setting;
use App\Notifications\AgencyAlert;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * One notification per campaign, for the campaigns linked to a client:
 * that it ends (two days before and the day before, in the evening, and a
 * few hours before it stops), what it spent today (in the evening, next to
 * the day's summary), and — per prepaid client — that their money is about
 * to run out. Each goes once; the evening is the summary time in Settings.
 */
class CampaignAlerts
{
    public const ENDING_TITLE = 'Campaign শেষ হচ্ছে'; // i18n

    public const SPEND_TITLE = 'আজকের campaign খরচ'; // i18n

    public const CREDIT_TITLE = 'Client-এর টাকা শেষের দিকে'; // i18n

    /** "About to stop": this many hours before its end. */
    public const SOON_HOURS = 3;

    /** A prepaid client hears of it when their money lasts less than this many days. */
    public const CREDIT_DAYS = 2;

    public function __construct(private Notifier $notifier, private Books $books) {}

    public static function evening(): string
    {
        return (string) (Setting::get('summary_time') ?: '21:00');
    }

    public function run(): void
    {
        $this->ending();

        if (Cache::add('campaign-alerts:credit:'.now()->format('Y-m-d-H'), true, 3600)) {
            $this->creditLow();
        }

        if (now()->format('H:i') >= self::evening() && Cache::add('campaign-alerts:spend:'.now()->toDateString(), true, 86400)) {
            $this->spentToday();
        }
    }

    /** @return int how many went out */
    public function ending(): int
    {
        $now = CarbonImmutable::now();
        $evening = $now->format('H:i') >= self::evening();
        $sent = 0;

        $campaigns = Campaign::with(['client', 'account'])->whereNotNull('client_id')->running()
            ->whereNotNull('stop_at')->where('stop_at', '<=', $now->addDays(3)->endOfDay())->get();

        foreach ($campaigns as $campaign) {
            $stop = CarbonImmutable::parse($campaign->stop_at)->setTimezone(config('app.timezone'));
            $days = (int) $now->startOfDay()->diffInDays($campaign->lastDay(), false);

            [$stage, $body] = match (true) {
                $stop->lte($now->addHours(self::SOON_HOURS)) => ['soon', '{client} · {campaign} — কয়েক ঘণ্টার মধ্যে: {time}'], // i18n
                $evening && $days === 1 => ['d1', '{client} · {campaign} — কাল শেষ: {time}'], // i18n
                $evening && $days === 2 => ['d2', '{client} · {campaign} — পরশু শেষ: {time}'], // i18n
                default => [null, null],
            };

            if ($stage === null || ! Cache::add("campaign-alerts:end:{$campaign->id}:{$stop->timestamp}:{$stage}", true, now()->addDays(4))) {
                continue;
            }

            $this->notifier->send($campaign->client?->manager_id, new AgencyAlert('campaign_ending', self::ENDING_TITLE, $body, [
                'client' => $campaign->client?->name,
                'campaign' => $campaign->name,
                'date' => $campaign->lastDay()?->format('j M'),
                // "25 Oct রাত শেষে (রাত 12টায়) বন্ধ", never a bare "26 Oct 12:00 AM".
                'time' => $campaign->stopsText(),
            ], ['url' => '/campaigns?account='.$campaign->pilot_account_id]));
            $sent++;
        }

        return $sent;
    }

    /** @return int how many went out */
    public function spentToday(): int
    {
        // The day's last numbers first; a Pilot hiccup sends what is already known.
        if (PilotClient::connected()) {
            rescue(fn () => app(CampaignBilling::class)->sync());
        }

        $days = CampaignDay::with(['campaign.client', 'campaign.account'])
            ->whereDate('day', now()->toDateString())->where('spend', '>', 0)
            ->whereHas('campaign', fn ($q) => $q->whereNotNull('client_id'))
            ->get();

        foreach ($days as $day) {
            $campaign = $day->campaign;

            $this->notifier->send($campaign->client?->manager_id, new AgencyAlert('campaign_spend', self::SPEND_TITLE, '{client} · {campaign} — আজ খরচ {spend} · {results}টা result', [ // i18n
                'client' => $campaign->client?->name,
                'campaign' => $campaign->name,
                'spend' => self::money($day->spend, $campaign->account?->currency),
                'results' => (int) $day->results,
            ], ['url' => '/campaigns?account='.$campaign->pilot_account_id]));
        }

        return $days->count();
    }

    /** @return int how many went out */
    public function creditLow(): int
    {
        $sent = 0;

        foreach ($this->books->clients() as $client) {
            $left = (int) $client['credit_left'];
            $daily = (int) $client['daily_usd'];

            if ($client['status'] !== 'active' || $client['credit_usd'] <= 0 || $daily <= 0 || $left <= 0 || $left >= $daily * self::CREDIT_DAYS) {
                continue;
            }

            if (! Cache::add('campaign-alerts:credit:'.$client['id'].':'.now()->toDateString(), true, 86400)) {
                continue;
            }

            $this->notifier->send(Client::whereKey($client['id'])->value('manager_id'), new AgencyAlert('credit_low', self::CREDIT_TITLE, '{client} — বাকি {left}, দিনে {daily} খরচে আর প্রায় {days} দিন', [ // i18n
                'client' => $client['name'],
                'left' => self::money($left),
                'daily' => self::money($daily),
                'days' => number_format($left / $daily, 1),
            ], ['url' => '/clients/'.$client['id']]));
            $sent++;
        }

        return $sent;
    }

    /** Cents in an ad account's currency: "$3.40", or "৳310" for one billed in taka. */
    public static function money(int $cents, ?string $currency = 'USD'): string
    {
        return strtoupper((string) $currency) === 'BDT'
            ? "\u{09F3}".number_format($cents / 100)
            : '$'.number_format($cents / 100, 2);
    }
}
