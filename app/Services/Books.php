<?php

namespace App\Services;

use App\Models\Account;
use App\Models\BdtEntry;
use App\Models\Client;
use App\Models\FxBatch;
use App\Models\UsdEntry;
use App\Models\WalletMove;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Every number the agency looks at is worked out here from the two ledgers
 * and the FX batches, the same way the old Google Sheet did:
 *
 *  - USD spent (client or own) is costed FIFO against the batch it came from.
 *  - The card goes down only when a platform actually charges it (card_charge),
 *    so "platform due" is spend booked on a platform but not yet charged.
 *  - A client's margin is the BDT they paid minus the FIFO cost of their
 *    settled spend.
 *
 * USD amounts are cents, BDT amounts are poisha.
 */
class Books
{
    /** @var array{entries: array<int, array{before:int, after:int, cost:int, rate:float}>, bought:int, consumed:int, overdrawn:bool, batches: array<int, array{used:int, left:int}>, segments: list<array{id:int, start:int, end:int, rate:float}>}|null */
    private ?array $fifo = null;

    /** @var Collection<int, UsdEntry>|null */
    private ?Collection $usd = null;

    /** @var Collection<int, BdtEntry>|null */
    private ?Collection $bdt = null;

    /** @return Collection<int, UsdEntry> */
    public function usdEntries(): Collection
    {
        return $this->usd ??= UsdEntry::with(['account', 'client'])->ledgerOrder()->get();
    }

    /** @return Collection<int, BdtEntry> */
    public function bdtEntries(): Collection
    {
        return $this->bdt ??= BdtEntry::with(['client', 'head', 'employee'])->ledgerOrder()->get();
    }

    /**
     * Walks the consuming USD rows in ledger order through the batches.
     *
     * @return array{entries: array<int, array{before:int, after:int, cost:int, rate:float}>, bought:int, consumed:int, overdrawn:bool, batches: array<int, array{used:int, left:int}>, segments: list<array{id:int, start:int, end:int, rate:float}>}
     */
    public function fifo(): array
    {
        if ($this->fifo !== null) {
            return $this->fifo;
        }

        $segments = [];
        $cursor = 0;

        foreach (FxBatch::orderBy('number')->get() as $batch) {
            $segments[] = [
                'id' => $batch->id,
                'start' => $cursor,
                'end' => $cursor + $batch->usd,
                'rate' => $batch->rate(),
            ];
            $cursor += $batch->usd;
        }

        $bought = $cursor;
        $consumed = 0;
        $entries = [];

        foreach ($this->usdEntries() as $entry) {
            if (! in_array($entry->category, UsdEntry::CONSUMING, true)) {
                continue;
            }

            $use = -$entry->amount;
            $before = $consumed;
            $consumed += $use;
            $cost = $this->costOfRange($segments, $before, $consumed);

            $entries[$entry->id] = [
                'before' => $before,
                'after' => $consumed,
                'cost' => (int) round($cost),
                'rate' => $use > 0 ? $cost / $use : 0.0,
            ];
        }

        $batches = [];
        foreach ($segments as $segment) {
            $used = max(0, min($consumed, $segment['end']) - $segment['start']);
            $batches[$segment['id']] = ['used' => $used, 'left' => ($segment['end'] - $segment['start']) - $used];
        }

        return $this->fifo = [
            'entries' => $entries,
            'bought' => $bought,
            'consumed' => $consumed,
            'overdrawn' => $consumed > $bought,
            'batches' => $batches,
            'segments' => $segments,
        ];
    }

    /**
     * BDT cost (poisha, unrounded) of using USD cents from position $from to $to.
     *
     * @param  list<array{id:int, start:int, end:int, rate:float}>  $segments
     */
    private function costOfRange(array $segments, int $from, int $to): float
    {
        if ($to <= $from || $segments === []) {
            return 0.0;
        }

        $cost = 0.0;

        foreach ($segments as $segment) {
            $overlap = min($to, $segment['end']) - max($from, $segment['start']);
            if ($overlap > 0) {
                $cost += $overlap * $segment['rate'];
            }
        }

        // Spending past the last batch is costed at the last known rate.
        $last = end($segments);
        $beyond = $to - max($from, $last['end']);
        if ($beyond > 0) {
            $cost += $beyond * $last['rate'];
        }

        return $cost;
    }

    public function costOf(UsdEntry $entry): int
    {
        return $this->fifo()['entries'][$entry->id]['cost'] ?? 0;
    }

    /** True when the row changes the card balance. */
    public static function hitsCard(UsdEntry $entry): bool
    {
        return $entry->category === 'card_charge' || $entry->account?->kind === 'card';
    }

    /**
     * Balance of the card after each row that touches it, keyed by entry id.
     *
     * @return array<int, int>
     */
    public function cardRunning(): array
    {
        $balance = 0;
        $running = [];

        foreach ($this->usdEntries() as $entry) {
            if (self::hitsCard($entry)) {
                $balance += $entry->amount;
                $running[$entry->id] = $balance;
            }
        }

        return $running;
    }

    /** @return array<int, int> */
    public function bdtRunning(): array
    {
        $balance = 0;
        $running = [];

        foreach ($this->bdtEntries() as $entry) {
            $balance += $entry->amount;
            $running[$entry->id] = $balance;
        }

        return $running;
    }

    /**
     * Spend booked on each ad platform against what the card has paid it.
     *
     * @return list<array{id:int, code:string, name:string, spent:int|float, charged:int|float, due:int|float}>
     */
    public function platforms(): array
    {
        $rows = [];

        foreach (Account::where('kind', 'platform')->orderBy('sort')->get() as $account) {
            $entries = $this->usdEntries()->where('account_id', $account->id);
            $spent = -$entries->where('category', '!=', 'card_charge')->sum('amount');
            $charged = -$entries->where('category', 'card_charge')->sum('amount');

            $rows[] = [
                'id' => $account->id,
                'code' => $account->code,
                'name' => $account->name,
                'spent' => $spent,
                'charged' => $charged,
                'due' => $spent - $charged,
            ];
        }

        return $rows;
    }

    /**
     * Per-client margin, pending and totals.
     *
     * @return list<array<string, mixed>>
     */
    public function clients(?CarbonInterface $from = null, ?CarbonInterface $to = null): array
    {
        $fifo = $this->fifo();
        $rows = [];

        foreach (Client::withTrashed()->orderBy('id')->get() as $client) {
            $spend = $this->inPeriod($this->usdEntries(), $from, $to)
                ->where('client_id', $client->id)
                ->where('category', 'client_spend');
            $settled = $spend->where('status', 'settled');
            $pending = $spend->where('status', 'pending');

            $received = $this->inPeriod($this->bdtEntries(), $from, $to)
                ->where('client_id', $client->id)
                ->whereIn('category', BdtEntry::REVENUE)
                ->sum('amount');

            $settledCost = $settled->sum(fn ($e) => $fifo['entries'][$e->id]['cost'] ?? 0);
            $pendingCost = $pending->sum(fn ($e) => $fifo['entries'][$e->id]['cost'] ?? 0);

            if ($client->trashed() && $spend->isEmpty() && $received == 0) {
                continue;
            }

            $rows[] = [
                'id' => $client->id,
                'name' => $client->name,
                'type' => $client->type,
                'status' => $client->status,
                'usd_settled' => -$settled->sum('amount'),
                'cost_settled' => $settledCost,
                'received' => (int) $received,
                'margin' => (int) $received - $settledCost,
                'usd_pending' => -$pending->sum('amount'),
                'cost_pending' => $pendingCost,
                'pending_count' => $pending->count(),
            ];
        }

        return $rows;
    }

    /**
     * @param  Collection<int, UsdEntry>|Collection<int, BdtEntry>  $entries
     * @return Collection<int, UsdEntry>|Collection<int, BdtEntry>
     */
    private function inPeriod(Collection $entries, ?CarbonInterface $from, ?CarbonInterface $to): Collection
    {
        if (! $from && ! $to) {
            return $entries;
        }

        return $entries->filter(function ($entry) use ($from, $to) {
            if (! $entry->entry_date) {
                return false;
            }

            return (! $from || $entry->entry_date->gte($from)) && (! $to || $entry->entry_date->lte($to));
        });
    }

    /**
     * Profit & loss, optionally for a date range.
     *
     * @return array<string, mixed>
     */
    public function profitAndLoss(?CarbonInterface $from = null, ?CarbonInterface $to = null): array
    {
        $fifo = $this->fifo();
        $clients = $this->clients($from, $to);

        $usd = $this->inPeriod($this->usdEntries(), $from, $to);
        $bdt = $this->inPeriod($this->bdtEntries(), $from, $to);

        $unlinkedIncome = (int) $bdt->whereIn('category', BdtEntry::REVENUE)->whereNull('client_id')->sum('amount');

        $ownSpend = $usd->where('category', 'business_spend');
        $ownCost = $ownSpend->sum(fn ($e) => $fifo['entries'][$e->id]['cost'] ?? 0);

        $expenses = $bdt->whereIn('category', BdtEntry::EXPENSE)
            ->groupBy(fn ($e) => $e->category === 'salary' ? __('বেতন (Salary)') : ($e->head->name ?? __('অন্যান্য খরচ')))
            ->map(fn ($group, $name) => ['name' => $name, 'amount' => -(int) $group->sum('amount')])
            ->sortByDesc('amount')
            ->values()
            ->all();

        $expenseTotal = array_sum(array_column($expenses, 'amount'));
        $margin = array_sum(array_column($clients, 'margin')) + $unlinkedIncome;
        $net = $margin - $ownCost - $expenseTotal;

        return [
            'clients' => $clients,
            'unlinked_income' => $unlinkedIncome,
            'margin' => $margin,
            'own_usd' => -(int) $ownSpend->sum('amount'),
            'own_cost' => $ownCost,
            'expenses' => $expenses,
            'expense_total' => $expenseTotal,
            'total_expense' => $ownCost + $expenseTotal,
            'net' => $net,
            'pending_usd' => array_sum(array_column($clients, 'usd_pending')),
            'pending_cost' => array_sum(array_column($clients, 'cost_pending')),
        ];
    }

    /**
     * Everything the dashboard shows, plus the two cross-checks.
     *
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        $fifo = $this->fifo();
        $entries = $this->usdEntries();

        $card = (int) $entries->filter(fn ($e) => self::hitsCard($e))->sum('amount');
        $holding = (int) $entries->filter(fn ($e) => $e->account?->kind === 'holding')->sum('amount');
        $platforms = $this->platforms();
        $platformDue = array_sum(array_column($platforms, 'due'));
        $businessUsd = $card + $holding - $platformDue;
        $remainingUsd = $fifo['bought'] - $fifo['consumed'];

        $segments = $fifo['segments'];
        $usdAtCost = (int) round($this->costOfRange($segments, $fifo['consumed'], max($fifo['consumed'], $fifo['bought'])));

        $bdtBalance = (int) $this->bdtEntries()->sum('amount');
        $capital = (int) $this->bdtEntries()->where('category', 'capital')->sum('amount');
        $drawings = -(int) $this->bdtEntries()->where('category', 'owner_drawing')->sum('amount');
        // Money the owner took for a while and gives back: still the business's.
        $ownerDue = -(int) $this->bdtEntries()->whereIn('category', ['owner_loan', 'owner_repay'])->sum('amount');

        $pl = $this->profitAndLoss();
        $assets = $bdtBalance + $usdAtCost + $pl['pending_cost'] + $ownerDue;
        $equity = $capital - $drawings + $pl['net'];

        return [
            'card' => $card,
            'holding' => $holding,
            'platforms' => $platforms,
            'platform_due' => $platformDue,
            'business_usd' => $businessUsd,
            'usd_check' => [
                'bought' => $fifo['bought'],
                'consumed' => $fifo['consumed'],
                'remaining' => $remainingUsd,
                'ok' => $remainingUsd === $businessUsd,
            ],
            'overdrawn' => $fifo['overdrawn'],
            'bdt' => $bdtBalance,
            'usd_at_cost' => $usdAtCost,
            'receivable_usd' => $pl['pending_usd'],
            'receivable_cost' => $pl['pending_cost'],
            'capital' => $capital,
            'drawings' => $drawings,
            'owner_due' => $ownerDue,
            'assets' => $assets,
            'equity' => $equity,
            // FIFO costs are rounded per row, so allow up to ৳1 of drift.
            'equity_ok' => abs($assets - $equity) <= 100,
            'margin' => $pl['margin'],
            'net' => $pl['net'],
            'review_count' => UsdEntry::whereNotNull('review_note')->count() + BdtEntry::whereNotNull('review_note')->count(),
            'undated_count' => UsdEntry::whereNull('entry_date')->count() + BdtEntry::whereNull('entry_date')->count(),
        ];
    }

    /**
     * How much BDT sits in each place: rows tagged with it plus moves in and
     * out. Rows not tagged yet are shown on their own so the total still
     * matches the BDT balance.
     *
     * @return array{places: list<array{key: string, label: string, balance: int}>, untagged: int, untagged_count: int, total: int}
     */
    public function wallets(): array
    {
        $entries = $this->bdtEntries();
        $moves = WalletMove::all();
        $places = [];

        foreach (BdtEntry::WALLETS as $key => $label) {
            $places[] = [
                'key' => $key,
                'label' => $label,
                'balance' => (int) $entries->where('wallet', $key)->sum('amount')
                    + (int) $moves->where('to_wallet', $key)->sum('amount')
                    - (int) $moves->where('from_wallet', $key)->sum('amount'),
            ];
        }

        $untagged = $entries->whereNull('wallet');

        return [
            'places' => $places,
            'untagged' => (int) $untagged->sum('amount'),
            'untagged_count' => $untagged->count(),
            'total' => (int) $entries->sum('amount'),
        ];
    }

    /**
     * Month-by-month receipts, costs and result for dated rows.
     *
     * @return list<array<string, mixed>>
     */
    public function monthly(int $months = 6): array
    {
        $fifo = $this->fifo();
        $rows = [];
        $start = now()->startOfMonth()->subMonths($months - 1);

        for ($i = 0; $i < $months; $i++) {
            $from = $start->copy()->addMonths($i);
            $to = $from->copy()->endOfMonth();
            $usd = $this->inPeriod($this->usdEntries(), $from, $to);
            $bdt = $this->inPeriod($this->bdtEntries(), $from, $to);

            $received = (int) $bdt->whereIn('category', BdtEntry::REVENUE)->sum('amount');
            $cost = $usd->whereIn('category', UsdEntry::CONSUMING)
                ->sum(fn ($e) => $fifo['entries'][$e->id]['cost'] ?? 0);
            $expense = -(int) $bdt->whereIn('category', BdtEntry::EXPENSE)->sum('amount');

            $rows[] = [
                'month' => $from->format('Y-m'),
                'label' => $from->format('M'),
                'received' => $received,
                'usd_spent' => -(int) $usd->whereIn('category', UsdEntry::CONSUMING)->sum('amount'),
                'cost' => $cost,
                'expense' => $expense,
                'result' => $received - $cost - $expense,
            ];
        }

        return $rows;
    }
}
