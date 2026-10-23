<?php

namespace App\Services;

use App\Models\Setting;
use App\Models\Task;
use App\Notifications\AgencyAlert;
use Illuminate\Support\Facades\Cache;

/**
 * Everything time-based, run every few minutes: by the scheduler, by the
 * ping URL, or — since the server has no cron — piggy-backed on any visit
 * to the site and on the notification bell's once-a-minute check. Running it twice in a row does nothing extra.
 */
class Ticker
{
    public const CARD_LOW_TITLE = 'Card-এ dollar কমে গেছে — USD কিনুন'; // i18n

    public const PILOT_DOWN_TITLE = 'Klok Pilot থেকে campaign-এর খরচ আসছে না'; // i18n

    /** Below this (USD, after what the platforms will still take) the card is "low". */
    public const CARD_LOW_DEFAULT = 20;

    public function __construct(private Agency $agency, private Notifier $notifier, private DailySummary $summary) {}

    /** @return array{ended: int, reminders: int, summary: bool} */
    public function run(): array
    {
        $lock = Cache::lock('agency:tick', 120);

        if (! $lock->get()) {
            return ['ended' => 0, 'reminders' => 0, 'summary' => false];
        }

        try {
            $ended = $this->agency->closeEnded();
            $reminders = $this->dueReminders();
            $summary = $this->dailySummary();
            $this->syncCampaignSpend();
            $this->renewals();
            $this->metaReceipts();
            $this->overdueInvoices();
            $this->cardLow();
            Setting::putQuietly('last_tick_at', now()->toIso8601String());

            return compact('ended', 'reminders', 'summary');
        } finally {
            $lock->release();
        }
    }

    /** Campaign spend from Klok Pilot, at most once an hour; a Pilot hiccup must not stop reminders. */
    private function syncCampaignSpend(): void
    {
        try {
            if (app(CampaignBilling::class)->syncIfDue()) {
                // The money left changed: caps and pauses follow it.
                app(CampaignLimits::class)->guard();
                Cache::forget('pilot:failures');
            }
        } catch (\Throwable $e) {
            report($e);

            // Twice in a row (two hours) and spend is falling behind: say so, at most every six hours.
            $failures = (int) Cache::increment('pilot:failures');
            if ($failures >= 2 && Cache::add('pilot:down-told', true, 6 * 3600)) {
                $this->notifier->send(null, new AgencyAlert('pilot_down', self::PILOT_DOWN_TITLE, '{error}', [
                    'error' => mb_substr($e->getMessage(), 0, 160),
                ], ['url' => '/campaigns']));
            }
        }
    }

    /**
     * Once a day, when the card after what Meta and Google will still take
     * is below the line set in Settings: buy dollars before ads stop.
     */
    private function cardLow(): void
    {
        if (! Cache::add('card-low:'.now()->toDateString(), true, 86400)) {
            return;
        }

        try {
            $after = (int) app(Books::class)->summary()['card_after'];
            $line = (int) round(((float) (Setting::get('card_low_usd') ?: self::CARD_LOW_DEFAULT)) * 100);

            if ($after < $line) {
                $this->notifier->send(null, new AgencyAlert('card_low', self::CARD_LOW_TITLE, '{after}', [
                    'after' => '$'.number_format($after / 100, 2),
                ], ['url' => '/dollars']));
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /** Meta's receipts from the receipts mailbox become card charges. */
    private function metaReceipts(): void
    {
        try {
            app(MetaReceipts::class)->checkIfDue();
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /** Renewal links for prepaid campaigns about to end. */
    private function renewals(): void
    {
        try {
            app(Renewals::class)->openDue();
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /** Sent invoices past their due date, once a day (the first tick of the day). */
    private function overdueInvoices(): void
    {
        if (! Cache::add('invoices:overdue:'.now()->toDateString(), true, 86400)) {
            return;
        }

        try {
            app(Invoices::class)->markOverdue();
        } catch (\Throwable $e) {
            report($e);
        }
    }

    private function dueReminders(): int
    {
        $count = 0;

        foreach (Task::due()->with(['client', 'adRun.client', 'adRun.account'])->limit(200)->get() as $task) {
            $extra = ['url' => $task->ad_run_id ? '/runs?focus='.$task->ad_run_id : '/tasks?focus='.$task->id, 'task_id' => $task->id];

            if ($task->kind === 'renewal' && $task->adRun) {
                $extra += $this->agency->contact($task->adRun);
            }

            $this->notifier->send($task->responsibleId(), new AgencyAlert($task->kind, $task->title, $task->client ? '{client}' : null, [
                'client' => $task->client?->name,
            ], $extra));

            $task->forceFill(['notified_at' => now()])->saveQuietly();
            $count++;
        }

        return $count;
    }

    /**
     * Once a day, at the time set in Settings (21:00 by default). With no
     * cron, the first tick after that time sends it; if nobody came by all
     * evening, yesterday's goes out with the first tick of the morning.
     */
    private function dailySummary(): bool
    {
        $time = (string) (Setting::get('summary_time') ?: '21:00');
        $today = now()->toDateString();
        $yesterday = now()->subDay()->toDateString();
        $sentOn = Setting::get('summary_sent_on');

        if ($sentOn !== null && $sentOn < $yesterday && now()->format('H:i') < $time) {
            Setting::putQuietly('summary_sent_on', $yesterday);
            $this->summary->send(now()->subDay());

            return true;
        }

        if ($sentOn === $today || now()->format('H:i') < $time) {
            return false;
        }

        Setting::putQuietly('summary_sent_on', $today);
        $this->summary->send();

        return true;
    }
}
