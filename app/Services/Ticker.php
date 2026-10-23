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
