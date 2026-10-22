<?php

namespace App\Http\Controllers\Books;

use App\Http\Controllers\Controller;
use App\Models\BdtEntry;
use App\Models\WalletMove;
use App\Services\Books;
use App\Support\Lookups;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Where the money is: each place's balance, moves between places, the card's
 * USD, and the rows that still need a place.
 */
class WalletController extends Controller
{
    public function index(Request $request, Books $books): Response
    {
        $summary = $books->summary();

        $untagged = BdtEntry::with('client:id,name')->whereNull('wallet')
            ->orderByRaw('entry_date is null')->orderBy('entry_date')->orderBy('sequence')->orderBy('id')
            ->get()
            ->map(fn (BdtEntry $e) => [
                'id' => $e->id,
                'date' => $e->entry_date?->toDateString(),
                'description' => $e->description,
                'category' => BdtEntry::categoryLabel($e->category),
                'client' => $e->client?->name,
                'amount' => $e->amount,
            ]);

        return Inertia::render('books/Wallets', [
            'wallets' => $books->wallets(),
            'card' => $summary['card'],
            'platform_due' => $summary['platform_due'],
            'untagged' => $untagged,
            'moves' => WalletMove::latest('moved_on')->latest('id')->limit(30)->get()
                ->map(fn (WalletMove $m) => [
                    'id' => $m->id, 'date' => $m->moved_on->toDateString(), 'from' => $m->from_wallet,
                    'to' => $m->to_wallet, 'amount' => $m->amount, 'note' => $m->note,
                ]),
            'options' => ['wallets' => Lookups::wallets()],
        ]);
    }

    /** Sets the place of one BDT row (its fee row follows a payment). */
    public function tag(Request $request, BdtEntry $entry): RedirectResponse
    {
        $data = $request->validate(['wallet' => ['nullable', Rule::in(array_keys(BdtEntry::WALLETS))]]);

        $entry->update(['wallet' => $data['wallet'] ?? null]);
        $entry->fee()->first()?->update(['wallet' => $data['wallet'] ?? null]);

        return back();
    }

    public function storeMove(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'moved_on' => ['required', 'date', 'before_or_equal:'.now()->addDay()->toDateString()],
            'from_wallet' => ['required', Rule::in(array_keys(BdtEntry::WALLETS))],
            'to_wallet' => ['required', 'different:from_wallet', Rule::in(array_keys(BdtEntry::WALLETS))],
            'amount' => ['required', Money::RULE],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $amount = Money::toMinor($data['amount']);

        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount' => __('পরিমাণ ০-এর বেশি হতে হবে।')]);
        }

        WalletMove::create(['amount' => $amount, 'created_by' => $request->user()->id] + $data);
        $this->toast(__(':from থেকে :to-তে টাকা সরানো লেখা হয়েছে।', [
            'from' => BdtEntry::WALLETS[$data['from_wallet']], 'to' => BdtEntry::WALLETS[$data['to_wallet']],
        ]));

        return back();
    }

    public function destroyMove(WalletMove $move): RedirectResponse
    {
        $move->delete();
        $this->toast(__('টাকা সরানোর entry মুছে ফেলা হয়েছে।'));

        return back();
    }
}
