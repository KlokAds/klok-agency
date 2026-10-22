<?php

namespace App\Services;

use App\Models\AdRun;
use App\Models\Client;
use App\Models\Task;
use App\Models\UsdEntry;
use App\Models\User;
use App\Notifications\AgencyAlert;
use App\Support\Access;
use Throwable;

/**
 * The end-of-day note: what was spent, which ads end tomorrow, what is
 * still unpaid, what work is due — and, from Klok Pilot, how each
 * client's ads did today.
 */
class DailySummary
{
    public function __construct(private ?PilotClient $pilot = null) {}

    /** @return array<string, mixed> */
    public function build(): array
    {
        $today = now()->toDateString();

        $spent = -(int) UsdEntry::where('category', 'client_spend')->whereDate('entry_date', $today)->sum('amount');
        $pending = UsdEntry::where('category', 'client_spend')->where('status', 'pending')->get();
        $ending = AdRun::with('client:id,name')->where('status', 'running')
            ->whereBetween('end_on', [$today, now()->addDay()->toDateString()])->get();
        $dueTasks = Task::open()->where('due_at', '<=', now()->endOfDay())->count();

        $ads = [];
        $pilotError = null;

        if (PilotClient::connected()) {
            foreach (Client::whereNotNull('pilot_shop_id')->where('status', 'active')->get() as $client) {
                try {
                    $report = ($this->pilot ?? app(PilotClient::class))->performance((int) $client->pilot_shop_id, 1);
                } catch (Throwable $e) {
                    $pilotError = $e->getMessage();

                    continue;
                }

                $totals = $report['totals'] ?? [];
                $results = collect($report['campaigns'] ?? [])->sum(fn ($c) => (float) ($c['platform_conversions'] ?? 0));
                $spend = (float) ($totals['spend'] ?? 0);

                $ads[] = [
                    'client' => $client->name,
                    'currency' => $report['currency'] ?? 'USD',
                    'spend' => round($spend, 2),
                    'results' => (int) $results,
                    'cpr' => $results > 0 ? round($spend / $results, 2) : null,
                    'roas' => $totals['blended_real_roas'] ?? $totals['platform_roas'] ?? null,
                    'campaigns' => count($report['campaigns'] ?? []),
                ];
            }
        }

        return [
            'date' => $today,
            'spent_usd' => $spent,
            'pending_usd' => -(int) $pending->sum('amount'),
            'pending_count' => $pending->count(),
            'ending' => $ending->map(fn ($r) => ['id' => $r->id, 'client' => $r->client?->name, 'title' => $r->title, 'end_on' => $r->end_on->toDateString()])->values()->all(),
            'tasks_due' => $dueTasks,
            'ads' => $ads,
            'pilot_error' => $pilotError,
        ];
    }

    /** Sends the summary to every Owner and every client manager. */
    public function send(): int
    {
        $summary = $this->build();
        $managers = Client::whereNotNull('manager_id')->pluck('manager_id');
        $people = User::where('is_active', true)
            ->where(fn ($q) => $q->whereIn('id', $managers)->orWhereHas('roles', fn ($r) => $r->where('name', Access::OWNER)))
            ->get();

        $alert = new AgencyAlert('summary', 'আজকের ads আর কাজের সারসংক্ষেপ', 'আজ খরচ ${spent} · {ending}টা ad কাল শেষ · {tasks}টা কাজ বাকি', [ // i18n
            'spent' => number_format($summary['spent_usd'] / 100, 2),
            'ending' => count($summary['ending']),
            'tasks' => $summary['tasks_due'],
        ], ['url' => '/notifications', 'summary' => $summary]);

        foreach ($people as $user) {
            $user->notify($alert);
        }

        return $people->count();
    }
}
