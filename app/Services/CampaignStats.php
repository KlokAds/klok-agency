<?php

namespace App\Services;

use App\Models\Campaign;
use App\Models\CampaignDay;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * A campaign's numbers over a period — the same figures on the client's portal and on the
 * team's campaign page: the period asked for, totals, a chart series and the day-by-day rows.
 */
class CampaignStats
{
    public const RANGES = ['all', 'today', 'yesterday', '7d', '15d', '30d', '90d', '180d', '365d', 'custom'];

    /**
     * The period: a named range from the query, else $default; "all" runs from $first.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable, 2: string}
     */
    public static function range(Request $request, ?CarbonImmutable $first = null, string $default = 'all'): array
    {
        $key = in_array($request->query('range'), self::RANGES, true) ? (string) $request->query('range') : $default;
        $today = CarbonImmutable::now()->startOfDay();

        if ($key === 'all') {
            return [($first ?? $today->subDays(6))->max($today->subDays(1095))->min($today), $today, 'all'];
        }

        if ($key === 'custom') {
            $from = rescue(fn () => CarbonImmutable::parse((string) $request->query('from'))->startOfDay(), $today->subDays(6), false);
            $to = rescue(fn () => CarbonImmutable::parse((string) $request->query('to'))->startOfDay(), $today, false);
            [$from, $to] = $from->gt($to) ? [$to, $from] : [$from, $to];

            // At most two years in one view.
            return [$from->max($to->subDays(730)), $to->min($today), 'custom'];
        }

        return match ($key) {
            'today' => [$today, $today, $key],
            'yesterday' => [$today->subDay(), $today->subDay(), $key],
            default => [$today->subDays((int) rtrim($key, 'd') - 1), $today, $key],
        };
    }

    /**
     * The day a list opens on: today once something was spent today, else yesterday — so the
     * first hours of a day do not open on a page of zeros.
     *
     * @param  array<int, int>  $campaignIds
     */
    public static function openingDay(array $campaignIds): string
    {
        return CampaignDay::whereIn('campaign_id', $campaignIds)->whereDate('day', now()->toDateString())->where('spend', '>', 0)->exists() ? 'today' : 'yesterday';
    }

    /**
     * @param  Collection<int, CampaignDay>  $days
     * @return array{spend: int, results: int, clicks: int, cpr: int|null}
     */
    public static function totals(Collection $days): array
    {
        $spend = (int) $days->sum('spend');
        $results = (int) $days->sum('results');

        return ['spend' => $spend, 'results' => $results, 'clicks' => (int) $days->sum('clicks'), 'cpr' => $results > 0 ? (int) round($spend / $results) : null];
    }

    /**
     * Day by day, every day of the period (a quiet day is 0), for the chart; past three months a
     * bar a week, so it stays readable on a phone.
     *
     * @param  Collection<int, CampaignDay>  $days
     * @return list<array{day: string, spend: int, results: int}>
     */
    public static function series(Collection $days, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $weekly = $from->diffInDays($to) > 92;
        $key = fn (CarbonImmutable $d) => ($weekly ? $d->startOfWeek() : $d)->toDateString();
        $byDay = $days->groupBy(fn (CampaignDay $d) => $key(CarbonImmutable::parse($d->day)));
        $out = [];

        for ($day = $weekly ? $from->startOfWeek() : $from; $day->lte($to); $day = $weekly ? $day->addWeek() : $day->addDay()) {
            $rows = $byDay->get($day->toDateString(), collect());
            $out[] = ['day' => $day->toDateString(), 'spend' => (int) $rows->sum('spend'), 'results' => (int) $rows->sum('results')];
        }

        return $out;
    }

    /**
     * The rows of a campaign's own page, newest first.
     *
     * @param  Collection<int, CampaignDay>  $days
     * @return array<int, array{day: string, spend: int, results: int, clicks: int, cpr: int|null}>
     */
    public static function dayRows(Collection $days): array
    {
        return $days->sortByDesc('day')->values()->map(fn (CampaignDay $d) => [
            'day' => $d->day->toDateString(),
            'spend' => (int) $d->spend,
            'results' => (int) $d->results,
            'clicks' => (int) $d->clicks,
            'cpr' => $d->results > 0 ? (int) round($d->spend / $d->results) : null,
        ])->all();
    }

    /**
     * The first day these campaigns ran: the earliest day they spent, or the earliest start.
     *
     * @param  array<int, int>  $campaignIds
     */
    public static function firstDay(array $campaignIds): ?CarbonImmutable
    {
        $day = CampaignDay::whereIn('campaign_id', $campaignIds)->where('spend', '>', 0)->min('day');
        $start = Campaign::whereKey($campaignIds)->min('start_at');
        $first = collect([$day, $start])->filter()->map(fn ($d) => CarbonImmutable::parse($d)->startOfDay())->min();

        return $first instanceof CarbonImmutable ? $first : null;
    }
}
