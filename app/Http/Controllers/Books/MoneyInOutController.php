<?php

namespace App\Http\Controllers\Books;

use App\Http\Controllers\Controller;
use App\Models\BdtEntry;
use App\Models\ExpenseHead;
use App\Models\Wallet;
use App\Support\Lookups;
use App\Support\Paging;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The BDT book split in two for day-to-day work: money that came in from
 * clients (Payment) and money the business spent (খরচ), each with its own
 * filters and totals. Editing goes through the BDT book's own rules.
 */
class MoneyInOutController extends Controller
{
    public function payments(Request $request): Response
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'client_id' => ['nullable', 'integer'],
            'pay_via' => ['nullable', Rule::in(array_keys(BdtEntry::PAY_VIA))],
            'wallet' => ['nullable', 'string', 'max:20'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $query = $this->filtered(BdtEntry::query()->whereIn('category', BdtEntry::REVENUE), $filters)
            ->when($filters['client_id'] ?? null, fn ($q, $id) => $q->where('client_id', $id))
            ->when($filters['pay_via'] ?? null, fn ($q, $via) => $q->where('pay_via', $via));

        $byClient = (clone $query)->toBase()->selectRaw('client_id, sum(amount) as total, count(*) as n')->groupBy('client_id')
            ->orderByDesc('total')->limit(8)->get()
            ->map(fn ($r) => ['client' => $r->client_id ? Lookups::clientName((int) $r->client_id) : __('Client ছাড়া'), 'total' => (int) $r->total, 'count' => (int) $r->n]);

        return Inertia::render('books/MoneyBook', [
            'mode' => 'in',
            'rows' => $this->page($query, $request),
            'totals' => [
                'amount' => (int) (clone $query)->sum('amount'),
                'count' => (clone $query)->count(),
                'credit_usd' => (int) (clone $query)->sum('usd_credit'),
            ],
            'by_client' => $byClient,
            'filters' => $filters,
            'options' => [
                'clients' => Lookups::clients(),
                'wallets' => Lookups::wallets(),
                'pay_via' => collect(BdtEntry::PAY_VIA)->map(fn ($label, $key) => ['value' => $key, 'label' => $label])->values(),
                'categories' => collect(BdtEntry::REVENUE)->map(fn ($c) => ['value' => $c, 'label' => BdtEntry::categoryLabel($c)])->values(),
            ],
        ]);
    }

    public function expenses(Request $request): Response
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'expense_head_id' => ['nullable', 'integer'],
            'kind' => ['nullable', Rule::in(BdtEntry::EXPENSE)],
            'wallet' => ['nullable', 'string', 'max:20'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $query = $this->filtered(BdtEntry::query()->whereIn('category', BdtEntry::EXPENSE), $filters)
            ->when($filters['expense_head_id'] ?? null, fn ($q, $id) => $q->where('expense_head_id', $id))
            ->when($filters['kind'] ?? null, fn ($q, $kind) => $q->where('category', $kind));

        $byHead = (clone $query)->toBase()->selectRaw('category, expense_head_id, sum(amount) as total, count(*) as n')
            ->groupBy('category', 'expense_head_id')->orderBy('total')->get()
            ->map(fn ($r) => [
                'head' => $r->category === 'salary' ? __('বেতন (Salary)') : ($r->expense_head_id ? Lookups::headName((int) $r->expense_head_id) : __('অন্যান্য খরচ')),
                'total' => -(int) $r->total,
                'count' => (int) $r->n,
            ]);

        return Inertia::render('books/MoneyBook', [
            'mode' => 'out',
            'rows' => $this->page($query, $request),
            'totals' => ['amount' => -(int) (clone $query)->sum('amount'), 'count' => (clone $query)->count()],
            'by_head' => $byHead,
            'filters' => $filters,
            'heads' => ExpenseHead::orderBy('name')->get(['id', 'name', 'is_active']),
            'options' => [
                'heads' => Lookups::heads(),
                'wallets' => Lookups::wallets(),
                'kinds' => collect(BdtEntry::EXPENSE)->map(fn ($c) => ['value' => $c, 'label' => BdtEntry::categoryLabel($c)])->values(),
            ],
        ]);
    }

    /**
     * @param  Builder<BdtEntry>  $query
     * @param  array<string, mixed>  $filters
     * @return Builder<BdtEntry>
     */
    private function filtered(Builder $query, array $filters): Builder
    {
        return $query
            ->when($filters['q'] ?? null, fn ($q, $term) => $q->where(fn ($w) => $w->where('description', 'like', "%{$term}%")->orWhere('notes', 'like', "%{$term}%")))
            ->when($filters['wallet'] ?? null, fn ($q, $wallet) => $q->where('wallet', $wallet))
            ->when($filters['from'] ?? null, fn ($q, $from) => $q->whereDate('entry_date', '>=', $from))
            ->when($filters['to'] ?? null, fn ($q, $to) => $q->whereDate('entry_date', '<=', $to));
    }

    /**
     * @param  Builder<BdtEntry>  $query
     * @return array<string, mixed>
     */
    private function page(Builder $query, Request $request): array
    {
        return (clone $query)->with(['client:id,name', 'head:id,name'])
            ->orderByRaw('entry_date is null')->orderByDesc('entry_date')->orderByDesc('sequence')
            ->paginate(Paging::size($request))->withQueryString()
            ->through(fn (BdtEntry $e) => [
                'id' => $e->id,
                'date' => $e->entry_date?->toDateString(),
                'description' => $e->description,
                'category' => $e->category,
                'category_label' => BdtEntry::categoryLabel($e->category),
                'client_id' => $e->client_id,
                'client' => $e->client?->name,
                'expense_head_id' => $e->expense_head_id,
                'head' => $e->category === 'salary' ? __('বেতন (Salary)') : $e->head?->name,
                'pay_via' => $e->pay_via,
                'pay_via_label' => $e->pay_via ? (BdtEntry::PAY_VIA[$e->pay_via] ?? $e->pay_via) : null,
                'wallet' => $e->wallet,
                'wallet_label' => $e->wallet ? Wallet::nameOf($e->wallet) : null,
                'amount' => $e->amount,
                'usd_credit' => $e->usd_credit,
                'notes' => $e->notes,
                'locked' => $e->fx_batch_id !== null || $e->fee_for_id !== null || ($e->category === 'salary' && $e->employee_id !== null),
            ])->toArray();
    }

    public function storeHead(Request $request): RedirectResponse
    {
        ExpenseHead::create($request->validate(['name' => ['required', 'string', 'max:80', 'unique:expense_heads,name']]));
        $this->toast(__('নতুন খরচের খাত যোগ হয়েছে।'));

        return back();
    }

    public function updateHead(Request $request, ExpenseHead $head): RedirectResponse
    {
        $head->update($request->validate([
            'name' => ['required', 'string', 'max:80', Rule::unique('expense_heads', 'name')->ignore($head->id)],
            'is_active' => ['required', 'boolean'],
        ]));
        $this->toast(__('খরচের খাত আপডেট হয়েছে।'));

        return back();
    }
}
