<?php

namespace App\Http\Controllers\Books;

use App\Http\Controllers\Controller;
use App\Models\BdtEntry;
use App\Models\FxBatch;
use App\Models\UsdEntry;
use App\Services\AdAccountBalances;
use App\Services\Books;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    /** FX pool below this many cents is always flagged. */
    private const FX_FLOOR = 5000;

    /** …or when it covers fewer days of spend than this. */
    private const FX_DAYS = 7;

    public function __invoke(Books $books): Response
    {
        $recentUsd = UsdEntry::with(['client', 'account'])->latest('id')->limit(6)->get()
            ->map(fn ($e) => [
                'id' => $e->id, 'book' => 'usd', 'date' => $e->entry_date?->toDateString(),
                'description' => $e->description, 'category' => UsdEntry::categoryLabel($e->category),
                'client' => $e->client?->name, 'amount' => $e->amount, 'status' => $e->status, 'at' => $e->created_at,
            ]);

        $recentBdt = BdtEntry::with('client')->latest('id')->limit(6)->get()
            ->map(fn ($e) => [
                'id' => $e->id, 'book' => 'bdt', 'date' => $e->entry_date?->toDateString(),
                'description' => $e->description, 'category' => BdtEntry::categoryLabel($e->category),
                'client' => $e->client?->name, 'amount' => $e->amount, 'status' => null, 'at' => $e->created_at,
            ]);

        $month = $books->profitAndLoss(now()->startOfMonth(), now()->endOfMonth());

        return Inertia::render('Dashboard', [
            'summary' => $books->summary(),
            'wallets' => $books->wallets(),
            'monthly' => $books->monthly(6),
            'clients' => $this->clientRows($books),
            'fx' => $this->fx($books),
            'month_net' => $month['net'],
            'recent' => $recentUsd->concat($recentBdt)->sortByDesc('at')->take(6)->values(),
        ]);
    }

    /**
     * One row per client that has money in play, most urgent first:
     * clients who owe money, then those whose credit runs out soonest.
     *
     * @return list<array<string, mixed>>
     */
    private function clientRows(Books $books): array
    {
        // Old-style spend (typed in, not from a campaign) that the client still has to pay.
        $oldPending = [];
        foreach ($books->usdEntries() as $e) {
            if ($e->category === 'client_spend' && $e->status === 'pending' && $e->source !== 'pilot' && $e->client_id) {
                $oldPending[$e->client_id] = ($oldPending[$e->client_id] ?? 0) - $e->amount;
            }
        }

        $rows = [];

        foreach ($books->clients() as $c) {
            $id = (int) $c['id'];
            $credit = (int) $c['credit_usd'];
            $campaign = (int) $c['campaign_usd'];
            $pending = $oldPending[$id] ?? 0;
            // Credit taken day by day that Meta has not charged the card for yet (from the
            // campaigns' own days); a client with no Pilot days keeps the book's figure.
            $split = app(AdAccountBalances::class)->client((int) $c['id']);
            $unbilled = $split['charged'] + $split['uncharged'] > 0 ? $split['uncharged'] : (int) $c['unbilled_usd'];
            $balance = $credit - $campaign - $pending;
            $days = $c['days_left'] === null ? null : (int) $c['days_left'];

            $inPlay = $credit || $campaign || $pending || $unbilled || $balance;
            if (! $inPlay || ($c['status'] !== 'active' && $balance >= 0 && ! $unbilled)) {
                continue;
            }

            $rows[] = [
                'id' => $id,
                'name' => $c['name'],
                'active' => $c['status'] === 'active',
                'credit_usd' => $credit,
                'credit_bdt' => (int) $c['credit_bdt'],
                'spent_usd' => $campaign + $pending,
                // Prepaid spend at the rate the client paid, plus unpaid spend at its cost.
                'spent_bdt' => (int) $c['spent_bdt'] + (int) $c['cost_pending'],
                'balance_usd' => $balance,
                'pending_cost' => (int) $c['cost_pending'],
                'unearned' => (int) $c['unearned'],
                'unbilled_usd' => $unbilled,
                'daily_usd' => (int) $c['daily_usd'],
                'days_left' => $days,
            ];
        }

        usort($rows, function (array $a, array $b): int {
            $owesA = $a['balance_usd'] < 0;
            $owesB = $b['balance_usd'] < 0;

            if ($owesA !== $owesB) {
                return $owesA ? -1 : 1;
            }

            if ($owesA) {
                return $a['balance_usd'] <=> $b['balance_usd'];
            }

            $daysA = $a['days_left'] ?? PHP_INT_MAX;
            $daysB = $b['days_left'] ?? PHP_INT_MAX;

            return [$daysA, -$a['unbilled_usd'], $a['name']] <=> [$daysB, -$b['unbilled_usd'], $b['name']];
        });

        return $rows;
    }

    /**
     * The bought-dollar pool: how much is left, which batch is in use now
     * and whether it is time to buy more.
     *
     * @return array<string, mixed>
     */
    private function fx(Books $books): array
    {
        $fifo = $books->fifo();
        $remaining = $fifo['bought'] - $fifo['consumed'];

        $since = now()->subDays(7)->startOfDay();
        $week = 0;
        foreach ($books->usdEntries() as $e) {
            if (in_array($e->category, UsdEntry::CONSUMING, true) && $e->entry_date && $e->entry_date->gte($since)) {
                $week -= $e->amount;
            }
        }
        $daily = (int) round(max(0, $week) / 7);

        $current = null;
        $queued = 0;
        $queuedUsd = 0;

        foreach (FxBatch::orderBy('number')->get() as $batch) {
            $left = $fifo['batches'][$batch->id]['left'] ?? 0;

            if ($left <= 0) {
                continue;
            }

            if ($current === null) {
                $current = ['number' => $batch->number, 'rate' => round($batch->rate(), 2), 'left' => $left, 'usd' => $batch->usd];
            } else {
                $queued++;
                $queuedUsd += $left;
            }
        }

        return [
            'remaining' => $remaining,
            'daily_usd' => $daily,
            'days' => $daily > 0 ? (int) floor(max(0, $remaining) / $daily) : null,
            'current' => $current,
            'queued' => $queued,
            'queued_usd' => $queuedUsd,
            'low' => $remaining < self::FX_FLOOR || ($daily > 0 && $remaining < $daily * self::FX_DAYS),
        ];
    }
}
