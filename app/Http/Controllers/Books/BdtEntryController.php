<?php

namespace App\Http\Controllers\Books;

use App\Http\Controllers\Controller;
use App\Models\BdtEntry;
use App\Models\Wallet;
use App\Services\Books;
use App\Services\CampaignBilling;
use App\Support\Lookups;
use App\Support\Money;
use App\Support\Paging;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class BdtEntryController extends Controller
{
    public function index(Request $request, Books $books): Response
    {
        $filters = $request->only(['q', 'category', 'client_id', 'expense_head_id', 'wallet', 'from', 'to', 'order']);
        $running = $books->bdtRunning();

        $rows = $books->bdtEntries()
            ->filter(fn ($e) => $this->matches($e, $filters))
            ->map(fn ($e) => [
                'id' => $e->id,
                'date' => $e->entry_date?->toDateString(),
                'description' => $e->description,
                'category' => $e->category,
                'category_label' => BdtEntry::categoryLabel($e->category),
                'client' => $e->client?->name,
                'client_id' => $e->client_id,
                'pay_via' => $e->pay_via,
                'usd_credit' => $e->usd_credit,
                'wallet' => $e->wallet,
                'head' => $e->head?->name,
                'expense_head_id' => $e->expense_head_id,
                'employee' => $e->employee?->name,
                'amount' => $e->amount,
                'notes' => $e->notes,
                'review_note' => $e->review_note,
                'balance' => $running[$e->id] ?? null,
                'locked' => $e->fx_batch_id !== null || $e->fee_for_id !== null || ($e->category === 'salary' && $e->employee_id !== null),
            ]);

        if (($filters['order'] ?? 'new') === 'new') {
            $rows = $rows->reverse();
        }

        $totals = [
            'in' => $rows->where('amount', '>', 0)->sum('amount'),
            'out' => -$rows->where('amount', '<', 0)->sum('amount'),
            'count' => $rows->count(),
        ];

        $page = LengthAwarePaginator::resolveCurrentPage();
        $size = Paging::size($request);
        $paginator = new LengthAwarePaginator(
            $rows->forPage($page, $size)->values(), $rows->count(), $size, $page,
            ['path' => $request->url(), 'query' => $request->query()],
        );

        return Inertia::render('books/BdtLedger', [
            'entries' => $paginator,
            'filters' => $filters,
            'totals' => $totals,
            'summary' => ['balance' => end($running) ?: 0],
            'options' => [
                'categories' => Lookups::bdtCategories(),
                'clients' => Lookups::clients(),
                'wallets' => Lookups::wallets(),
                'heads' => Lookups::heads(),
            ],
        ]);
    }

    /** @param  array<string, mixed>  $f */
    private function matches(BdtEntry $e, array $f): bool
    {
        if (! empty($f['q']) && ! str_contains(mb_strtolower($e->description.' '.$e->notes.' '.$e->client?->name), mb_strtolower($f['q']))) {
            return false;
        }

        foreach (['category', 'client_id', 'expense_head_id', 'wallet'] as $key) {
            if (! empty($f[$key]) && (string) $e->{$key} !== (string) $f[$key]) {
                return false;
            }
        }

        if (! empty($f['from']) && (! $e->entry_date || $e->entry_date->toDateString() < $f['from'])) {
            return false;
        }

        if (! empty($f['to']) && (! $e->entry_date || $e->entry_date->toDateString() > $f['to'])) {
            return false;
        }

        return true;
    }

    public function store(Request $request): RedirectResponse
    {
        BdtEntry::create($this->validated($request));
        $this->toast(__('BDT entry যোগ হয়েছে।'));

        return back();
    }

    public function update(Request $request, BdtEntry $bdt): RedirectResponse
    {
        $this->ensureEditable($bdt);
        $data = $this->validated($request, $bdt);

        if ($request->boolean('clear_review')) {
            $data['review_note'] = null;
        }

        $before = $bdt->client_id;
        $bdt->update($data);

        // A payment's ad credit changed: its client's campaign spend is funded again.
        foreach (array_unique(array_filter([(int) $before, (int) $bdt->client_id])) as $clientId) {
            app(CampaignBilling::class)->fund($clientId);
        }
        $this->toast(__('BDT entry আপডেট হয়েছে।'));

        return back();
    }

    public function destroy(BdtEntry $bdt): RedirectResponse
    {
        $this->ensureEditable($bdt);

        // Spend this payment settled goes back to pending.
        $bdt->settles()->get()->each->update(['status' => 'pending', 'settled_by_id' => null]);
        $bdt->delete();
        $this->toast(__('Entry Trash-এ গেছে। দরকার হলে Trash থেকে ফেরত আনা যাবে।'));

        return back();
    }

    private function ensureEditable(BdtEntry $entry): void
    {
        if ($entry->fx_batch_id) {
            throw ValidationException::withMessages(['entry' => __('এই row একটা FX batch-এর অংশ — FX Batch পাতা থেকে বদলান।')]);
        }

        if ($entry->category === 'salary' && $entry->employee_id) {
            throw ValidationException::withMessages(['entry' => __('বেতনের row Payroll পাতা থেকে বদলান।')]);
        }

        if ($entry->fee_for_id) {
            throw ValidationException::withMessages(['entry' => __('এই fee payment থেকে নিজে আসে — payment বদলালে fee-ও বদলাবে।')]);
        }
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?BdtEntry $entry = null): array
    {
        $categories = array_diff(array_keys(BdtEntry::CATEGORIES), ['usd_purchase']);

        $data = $request->validate([
            'entry_date' => [$entry && ! $entry->entry_date ? 'nullable' : 'required', 'date'],
            'description' => ['required', 'string', 'max:255'],
            'category' => ['required', Rule::in($categories)],
            'client_id' => ['nullable', Rule::requiredIf($request->input('category') === 'client_payment'), 'exists:clients,id'],
            'pay_via' => ['nullable', Rule::requiredIf(in_array($request->input('category'), ['client_payment', 'service_income'], true)), Rule::in(array_keys(BdtEntry::PAY_VIA))],
            'expense_head_id' => ['nullable', 'exists:expense_heads,id'],
            'wallet' => ['nullable', Rule::in(Wallet::keys())],
            'amount' => ['required', Money::RULE],
            'notes' => ['nullable', 'string', 'max:2000'],
            // An advance: the USD of ads this payment buys, spent by the client's campaigns.
            'usd_credit' => ['nullable', Money::RULE],
        ], [], ['amount' => __('পরিমাণ'), 'entry_date' => __('তারিখ'), 'client_id' => 'client', 'usd_credit' => 'USD credit']);

        $amount = Money::toMinor($data['amount']);

        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount' => __('পরিমাণ ০-এর বেশি হতে হবে।')]);
        }

        $data['amount'] = BdtEntry::CATEGORIES[$data['category']]['sign'] * $amount;

        if ($data['category'] !== 'expense') {
            $data['expense_head_id'] = null;
        }

        if (! in_array($data['category'], ['client_payment', 'service_income'], true)) {
            $data['pay_via'] = null;
        }

        $data['usd_credit'] = $data['category'] === 'client_payment' && filled($data['usd_credit'] ?? null)
            ? (Money::toMinor($data['usd_credit']) ?: null)
            : null;

        return $data;
    }
}
