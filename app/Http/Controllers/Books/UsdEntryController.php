<?php

namespace App\Http\Controllers\Books;

use App\Http\Controllers\Controller;
use App\Models\UsdEntry;
use App\Services\Books;
use App\Support\Lookups;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class UsdEntryController extends Controller
{
    public function index(Request $request, Books $books): Response
    {
        $filters = $request->only(['q', 'category', 'account_id', 'client_id', 'status', 'from', 'to', 'order']);
        $fifo = $books->fifo()['entries'];
        $card = $books->cardRunning();

        $rows = $books->usdEntries()
            ->filter(fn ($e) => $this->matches($e, $filters))
            ->map(fn ($e) => [
                'id' => $e->id,
                'date' => $e->entry_date?->toDateString(),
                'description' => $e->description,
                'category' => $e->category,
                'category_label' => UsdEntry::categoryLabel($e->category),
                'account' => $e->account?->name,
                'account_id' => $e->account_id,
                'account_kind' => $e->account?->kind,
                'client' => $e->client?->name,
                'client_id' => $e->client_id,
                'amount' => $e->amount,
                'status' => $e->status,
                'notes' => $e->notes,
                'review_note' => $e->review_note,
                'card_balance' => $card[$e->id] ?? null,
                'fifo_cost' => $fifo[$e->id]['cost'] ?? null,
                'fifo_rate' => isset($fifo[$e->id]) ? round($fifo[$e->id]['rate'], 2) : null,
                'locked' => $e->fx_batch_id !== null,
                'paired' => $e->pair_key !== null,
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
        $paginator = new LengthAwarePaginator(
            $rows->forPage($page, 50)->values(), $rows->count(), 50, $page,
            ['path' => $request->url(), 'query' => $request->query()],
        );

        return Inertia::render('books/UsdLedger', [
            'entries' => $paginator,
            'filters' => $filters,
            'totals' => $totals,
            'summary' => ['card' => end($card) ?: 0],
            'options' => [
                'categories' => Lookups::usdCategories(),
                'accounts' => Lookups::accounts(),
                'clients' => Lookups::clients(),
                'statuses' => Lookups::options(UsdEntry::STATUSES),
            ],
        ]);
    }

    /** @param  array<string, mixed>  $f */
    private function matches(UsdEntry $e, array $f): bool
    {
        if (! empty($f['q']) && ! str_contains(mb_strtolower($e->description.' '.$e->notes.' '.$e->client?->name), mb_strtolower($f['q']))) {
            return false;
        }

        foreach (['category', 'account_id', 'client_id', 'status'] as $key) {
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
        UsdEntry::create($this->validated($request));
        $this->toast(__('USD entry যোগ হয়েছে।'));

        return back();
    }

    public function update(Request $request, UsdEntry $usd): RedirectResponse
    {
        $this->ensureEditable($usd);
        $data = $this->validated($request, $usd);

        DB::transaction(function () use ($usd, $data) {
            $usd->update($data);

            // The other half of a transfer follows date, amount and text.
            if ($usd->pair_key) {
                UsdEntry::where('pair_key', $usd->pair_key)->whereKeyNot($usd->id)->get()
                    ->each(fn ($partner) => $partner->update([
                        'entry_date' => $usd->entry_date,
                        'description' => $usd->description,
                        'amount' => -$usd->amount,
                    ]));
            }
        });

        $this->toast(__('USD entry আপডেট হয়েছে।'));

        return back();
    }

    public function destroy(UsdEntry $usd): RedirectResponse
    {
        $this->ensureEditable($usd);

        DB::transaction(function () use ($usd) {
            $rows = $usd->pair_key ? UsdEntry::where('pair_key', $usd->pair_key)->get() : collect([$usd]);
            $rows->each->delete();
        });

        $this->toast(__('Entry Trash-এ গেছে। দরকার হলে Trash থেকে ফেরত আনা যাবে।'));

        return back();
    }

    private function ensureEditable(UsdEntry $entry): void
    {
        if ($entry->fx_batch_id) {
            throw ValidationException::withMessages(['entry' => __('এই row একটা FX batch-এর অংশ — FX Batch পাতা থেকে বদলান।')]);
        }
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?UsdEntry $entry = null): array
    {
        $data = $request->validate([
            'entry_date' => [$entry && ! $entry->entry_date ? 'nullable' : 'required', 'date'],
            'description' => ['required', 'string', 'max:255'],
            'category' => ['required', Rule::in(array_diff(array_keys(UsdEntry::CATEGORIES), ['deposit']))],
            'account_id' => ['required', 'exists:accounts,id'],
            'client_id' => ['nullable', Rule::requiredIf($request->input('category') === 'client_spend'), 'exists:clients,id'],
            'amount' => ['required', Money::RULE],
            'direction' => ['nullable', Rule::in(['in', 'out'])],
            'status' => ['required', Rule::in(array_keys(UsdEntry::STATUSES))],
            'notes' => ['nullable', 'string', 'max:2000'],
        ], [], ['amount' => __('পরিমাণ'), 'entry_date' => __('তারিখ'), 'client_id' => 'client']);

        $amount = Money::toMinor($data['amount']);

        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount' => __('পরিমাণ ০-এর বেশি হতে হবে।')]);
        }

        $sign = UsdEntry::CATEGORIES[$data['category']]['sign'];
        if ($sign === 0) {
            $sign = ($data['direction'] ?? 'out') === 'in' ? 1 : -1;
        }

        $data['amount'] = $sign * $amount;

        if ($data['category'] !== 'client_spend') {
            $data['status'] = 'na';
        } elseif ($data['status'] === 'na') {
            $data['status'] = 'pending';
        }

        unset($data['direction']);

        return $data;
    }
}
