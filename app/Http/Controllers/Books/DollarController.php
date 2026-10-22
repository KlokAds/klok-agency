<?php

namespace App\Http\Controllers\Books;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\FxBatch;
use App\Services\Books;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Where the dollars are: the card and other USD accounts, what each ad
 * platform has not charged yet, and how much bought USD is left in the FX
 * batches (and at what rate). The USD accounts are kept here too.
 */
class DollarController extends Controller
{
    public function index(Books $books): Response
    {
        $summary = $books->summary();
        $fifo = $books->fifo();

        $accounts = Account::orderBy('sort')->get()->map(fn (Account $a) => [
            'id' => $a->id,
            'code' => $a->code,
            'name' => $a->name,
            'kind' => $a->kind,
            'is_active' => $a->is_active,
            // The card's balance counts every row that touches it (charges are booked on the platform).
            'balance' => match ($a->kind) {
                'card' => $summary['card'],
                'holding' => (int) $books->usdEntries()->where('account_id', $a->id)->sum('amount'),
                default => null,
            },
        ]);

        $batches = FxBatch::orderBy('number')->get()->map(fn (FxBatch $b) => [
            'id' => $b->id,
            'number' => $b->number,
            'date' => $b->purchased_on?->toDateString(),
            'usd' => $b->usd,
            'bdt' => $b->bdt,
            'rate' => round($b->rate(), 2),
            'left' => $fifo['batches'][$b->id]['left'] ?? 0,
        ]);

        return Inertia::render('books/Dollars', [
            'summary' => [
                'card' => $summary['card'],
                'holding' => $summary['holding'],
                'platform_due' => $summary['platform_due'],
                'card_after' => $summary['card_after'],
                'business_usd' => $summary['business_usd'],
                'usd_check' => $summary['usd_check'],
                'usd_at_cost' => $summary['usd_at_cost'],
            ],
            'platforms' => $summary['platforms'],
            'accounts' => $accounts,
            'batches' => $batches,
            'kinds' => collect(Account::KINDS)->map(fn ($l, $v) => ['value' => $v, 'label' => $l])->values(),
        ]);
    }

    public function storeAccount(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'kind' => ['required', Rule::in(['holding', 'platform'])],
        ]);

        Account::create($data + [
            'code' => str($data['name'])->slug('_')->limit(24, '')->append('_'.now()->format('His'))->toString(),
            'sort' => (int) Account::max('sort') + 1,
        ]);
        $this->toast(__('নতুন account যোগ হয়েছে।'));

        return back();
    }

    public function updateAccount(Request $request, Account $account): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'is_active' => ['required', 'boolean'],
        ]);

        // The card is what every balance is measured against; it stays on.
        if ($account->kind === 'card') {
            $data['is_active'] = true;
        }

        $account->update($data);
        $this->toast(__('Account আপডেট হয়েছে।'));

        return back();
    }
}
