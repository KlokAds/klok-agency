<?php

namespace App\Services;

use App\Models\AdRun;
use App\Models\Client;
use App\Models\PilotAccount;
use App\Models\Task;
use App\Models\UsdEntry;
use App\Models\User;
use App\Notifications\AgencyAlert;
use App\Support\Access;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Throwable;

/**
 * The end-of-day note: what was spent, which ads end tomorrow, what is
 * still unpaid, what work is due — and, from Klok Pilot, how each
 * client's ad account did that day. Usually today; yesterday's goes out
 * the next morning when nobody had the app open in the evening.
 */
class DailySummary
{
    public function __construct(private ?PilotClient $pilot = null) {}

    /** @return array<string, mixed> */
    public function build(?CarbonInterface $day = null): array
    {
        $day = ($day ?? now())->copy()->startOfDay();
        $today = $day->toDateString();
        $daysBack = (int) $day->diffInDays(now()->startOfDay());

        $spent = -(int) UsdEntry::where('category', 'client_spend')->whereDate('entry_date', $today)->sum('amount');
        $pending = UsdEntry::where('category', 'client_spend')->where('status', 'pending')->get();
        $ending = AdRun::with('client:id,name')->where('status', 'running')
            ->whereBetween('end_on', [$today, $day->copy()->addDay()->toDateString()])->get();
        $dueTasks = Task::open()->where('due_at', '<=', $day->copy()->endOfDay())->count();

        $ads = [];
        $pilotError = null;
        $endings = $ending->map(fn ($r) => ['id' => $r->id, 'client' => $r->client?->name, 'title' => $r->title, 'end_on' => $r->end_on->toDateString()]);

        if (PilotClient::connected()) {
            // Every ad account in Pilot, the agency's own and not-yet-linked ones too; only a
            // client who stopped is left out.
            $accounts = PilotAccount::with('client:id,name,status')->orderBy('name')->get()
                ->filter(fn (PilotAccount $a) => $a->client === null || $a->client->status === 'active');
            $spentPilot = ['USD' => 0.0, 'BDT' => 0.0];

            foreach ($accounts as $account) {
                try {
                    $report = $this->dayReport($account->pilot_id, $daysBack);
                } catch (Throwable $e) {
                    $pilotError = $e->getMessage();

                    continue;
                }

                $totals = $report['totals'] ?? [];
                $results = (int) ($totals['results'] ?? 0);
                $spend = (float) ($totals['spend'] ?? 0);
                $currency = $report['currency'] ?? 'USD';
                $spentPilot[$currency === 'BDT' ? 'BDT' : 'USD'] += $spend;

                if ($spend > 0 || $results > 0 || $account->client !== null) {
                    $ads[] = [
                        'client' => $account->client->name ?? $account->name,
                        'account' => $account->name,
                        'currency' => $currency,
                        'spend' => round($spend, 2),
                        'results' => $results,
                        'cpr' => $results > 0 ? round($spend / $results, 2) : null,
                        'clicks' => (int) ($totals['clicks'] ?? 0),
                        'campaigns' => count($report['campaigns'] ?? []),
                    ];
                }

                // Meta campaigns that stop by the end of the next day and are still spending.
                if ($daysBack === 0) {
                    $endings = $endings->concat($this->endingCampaigns($account, $day));
                }
            }

            // What the ads really spent, from Pilot; the books only get it when it is written in.
            $spent = max($spent, (int) round($spentPilot['USD'] * 100));
            $spentBdt = (int) round($spentPilot['BDT'] * 100);
        }

        return [
            'date' => $today,
            'spent_usd' => $spent,
            'spent_bdt' => $spentBdt ?? 0,
            'pending_usd' => -(int) $pending->sum('amount'),
            'pending_count' => $pending->count(),
            'ending' => $endings->values()->all(),
            'tasks_due' => $dueTasks,
            'ads' => $ads,
            'pilot_error' => $pilotError,
        ];
    }

    /**
     * One ad account's live campaigns that stop today or tomorrow. Meta keeps an ended campaign
     * "ACTIVE", so only those that spent in the last week count.
     *
     * @return list<array{id: string, client: string|null, title: string, end_on: string}>
     */
    private function endingCampaigns(PilotAccount $account, CarbonInterface $day): array
    {
        try {
            $campaigns = ($this->pilot ?? app(PilotClient::class))->campaigns($account->pilot_id)['campaigns'] ?? [];
        } catch (Throwable) {
            return [];
        }

        $last = $day->copy()->addDay()->endOfDay();
        $out = [];

        foreach ($campaigns as $c) {
            $stop = empty($c['stop_time']) ? null : Carbon::parse($c['stop_time'])->timezone(config('app.timezone'));

            if ($stop === null || ($c['status'] ?? '') !== 'ACTIVE' || (float) ($c['spend_7d'] ?? 0) <= 0 || $stop->lt(now()) || $stop->copy()->subSecond()->gt($last)) {
                continue;
            }

            $out[] = [
                'id' => 'meta-'.($c['campaign_id'] ?? $c['external_id'] ?? count($out)),
                'client' => $account->client->name ?? $account->name,
                'title' => (string) ($c['name'] ?? ''),
                // A stop at midnight is the end of the day before.
                'end_on' => $stop->copy()->subSecond()->toDateString(),
            ];
        }

        return $out;
    }

    /**
     * Pilot counts "the last N days up to today", so an earlier day is the
     * difference between two such windows.
     *
     * @return array{currency?: string, totals?: array<string, mixed>, campaigns?: list<array<string, mixed>>}
     */
    private function dayReport(int $adAccountId, int $daysBack): array
    {
        $pilot = $this->pilot ?? app(PilotClient::class);
        $upTo = $pilot->performance($adAccountId, $daysBack + 1);

        if ($daysBack === 0) {
            return $upTo;
        }

        $after = $pilot->performance($adAccountId, $daysBack);
        $totals = [];

        foreach (['spend', 'results', 'clicks'] as $key) {
            $totals[$key] = (float) ($upTo['totals'][$key] ?? 0) - (float) ($after['totals'][$key] ?? 0);
        }

        return ['currency' => $upTo['currency'] ?? 'USD', 'totals' => $totals, 'campaigns' => $upTo['campaigns'] ?? []];
    }

    /** Sends the summary to every Owner and every client manager. */
    public function send(?CarbonInterface $day = null): int
    {
        $summary = $this->build($day);
        $late = $summary['date'] !== now()->toDateString();
        $managers = Client::whereNotNull('manager_id')->pluck('manager_id');
        $people = User::where('is_active', true)
            ->where(fn ($q) => $q->whereIn('id', $managers)->orWhereHas('roles', fn ($r) => $r->where('name', Access::OWNER)))
            ->get();

        $alert = new AgencyAlert('summary', $late ? 'গতকালের ads আর কাজের সারসংক্ষেপ' : 'আজকের ads আর কাজের সারসংক্ষেপ', $late ? 'গতকাল খরচ ${spent} · {ending}টা ad শেষ হচ্ছে · {tasks}টা কাজ বাকি' : 'আজ খরচ ${spent} · {ending}টা ad কাল শেষ · {tasks}টা কাজ বাকি', [ // i18n
            // "$12.40", and "+ ৳310" for accounts billed in taka.
            'spent' => number_format($summary['spent_usd'] / 100, 2)
                .($summary['spent_bdt'] > 0 ? " + \u{09F3}".number_format($summary['spent_bdt'] / 100) : ''),
            'ending' => count($summary['ending']),
            'tasks' => $summary['tasks_due'],
        ], ['url' => '/notifications', 'summary' => $summary]);

        foreach ($people as $user) {
            $user->notify($alert);
        }

        return $people->count();
    }
}
