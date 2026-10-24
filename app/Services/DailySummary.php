<?php

namespace App\Services;

use App\Models\AdRun;
use App\Models\Campaign;
use App\Models\CampaignDay;
use App\Models\Client;
use App\Models\Task;
use App\Models\UsdEntry;
use App\Models\User;
use App\Notifications\AgencyAlert;
use App\Support\Access;
use Carbon\CarbonInterface;

/**
 * The end-of-day note: how many of the clients' campaigns spent and how
 * much, which end tomorrow, what is still unpaid, what work is due — and
 * each client's numbers that day. Only campaigns linked to a client count
 * (their day-by-day spend comes from Klok Pilot, CampaignBilling). Usually
 * today; yesterday's goes out the next morning when nobody had the app
 * open in the evening.
 */
class DailySummary
{
    /** @return array<string, mixed> */
    public function build(?CarbonInterface $day = null): array
    {
        $day = ($day ?? now())->copy()->startOfDay();
        $today = $day->toDateString();
        $tomorrow = $day->copy()->addDay()->toDateString();

        $pending = UsdEntry::where('category', 'client_spend')->where('status', 'pending')->get();
        $dueTasks = Task::open()->where('due_at', '<=', $day->copy()->endOfDay())->count();

        $days = CampaignDay::with(['campaign.client:id,name', 'campaign.account:id,currency'])
            ->whereDate('day', $today)
            ->whereHas('campaign', fn ($q) => $q->whereNotNull('client_id'))
            ->get();
        $inTaka = fn (CampaignDay $d) => strtoupper((string) $d->campaign->account?->currency) === 'BDT';
        $booked = -(int) UsdEntry::where('category', 'client_spend')->whereDate('entry_date', $today)->sum('amount');

        $ads = $days->groupBy(fn (CampaignDay $d) => $d->campaign->client_id.':'.($inTaka($d) ? 'BDT' : 'USD'))
            ->map(function ($rows) use ($inTaka) {
                $spend = $rows->sum('spend') / 100;
                $results = (int) $rows->sum('results');
                $first = $rows->first();
                $currency = $inTaka($first) ? 'BDT' : 'USD';

                return [
                    'client' => (string) $first->campaign->client?->name,
                    'account' => $first->campaign->client?->name.' '.$currency,
                    'currency' => $currency,
                    'spend' => round($spend, 2),
                    'results' => $results,
                    'cpr' => $results > 0 ? round($spend / $results, 2) : null,
                    'clicks' => (int) $rows->sum('clicks'),
                    'campaigns' => $rows->where('spend', '>', 0)->count(),
                ];
            })
            ->filter(fn (array $a) => $a['spend'] > 0 || $a['results'] > 0)
            ->sortBy('client')->values()->all();

        // The clients' campaigns whose last day is tomorrow, and the tracked ad runs that end by then.
        $ending = Campaign::with('client:id,name')->whereNotNull('client_id')->running()->whereNotNull('stop_at')->get()
            ->filter(fn (Campaign $c) => $c->lastDay()?->toDateString() === $tomorrow)
            ->map(fn (Campaign $c) => ['id' => 'c'.$c->id, 'client' => $c->client?->name, 'title' => $c->name, 'end_on' => $tomorrow])
            ->concat(AdRun::with('client:id,name')->where('status', 'running')->whereBetween('end_on', [$today, $tomorrow])->get()
                ->map(fn (AdRun $r) => ['id' => $r->id, 'client' => $r->client?->name, 'title' => $r->title, 'end_on' => $r->end_on->toDateString()]))
            ->values()->all();

        return [
            'date' => $today,
            'campaigns' => $days->where('spend', '>', 0)->count(),
            'spent_usd' => max($booked, (int) $days->reject($inTaka)->sum('spend')),
            'spent_bdt' => (int) $days->filter($inTaka)->sum('spend'),
            'pending_usd' => -(int) $pending->sum('amount'),
            'pending_count' => $pending->count(),
            'ending' => $ending,
            'tasks_due' => $dueTasks,
            'ads' => $ads,
            'pilot_error' => null,
        ];
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

        $alert = new AgencyAlert('summary', $late ? 'গতকালের ads আর কাজের সারসংক্ষেপ' : 'আজকের ads আর কাজের সারসংক্ষেপ', $late ? 'গতকাল {count}টা campaign-এ খরচ ${spent} · {ending}টা campaign শেষ হচ্ছে · {tasks}টা কাজ বাকি' : 'আজ {count}টা campaign-এ খরচ ${spent} · {ending}টা campaign কাল শেষ · {tasks}টা কাজ বাকি', [ // i18n
            'count' => $summary['campaigns'],
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
