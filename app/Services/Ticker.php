<?php

namespace App\Services;

use App\Models\Setting;
use App\Models\Task;
use App\Notifications\AgencyAlert;
use Illuminate\Support\Facades\Cache;

/**
 * Everything time-based, run every few minutes: by the scheduler, by the
 * ping URL (cron-job.org) or — since the server has no cron — piggy-backed
 * on normal use of the app. Running it twice in a row does nothing extra.
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
            Setting::putQuietly('last_tick_at', now()->toIso8601String());

            return compact('ended', 'reminders', 'summary');
        } finally {
            $lock->release();
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

    /** Once a day, at the time set in Settings (21:00 by default). */
    private function dailySummary(): bool
    {
        $time = (string) (Setting::get('summary_time') ?: '21:00');
        $today = now()->toDateString();

        if (Setting::get('summary_sent_on') === $today || now()->format('H:i') < $time) {
            return false;
        }

        Setting::putQuietly('summary_sent_on', $today);
        $this->summary->send();

        return true;
    }
}
