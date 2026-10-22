<?php

namespace App\Http\Controllers\Books;

use App\Http\Controllers\Controller;
use App\Models\BdtEntry;
use App\Models\WalletMove;
use App\Services\Books;
use App\Services\MoveFees;
use App\Support\Lookups;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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

        $query = BdtEntry::with('client:id,name')->whereNull('wallet')
            ->orderByRaw('entry_date is null')->orderBy('entry_date')->orderBy('sequence')->orderBy('id');
        $untagged = $query->clone()->paginate(30, ['*'], 'untagged_page');

        // Tagging the last rows of the last page: show the page before it, not an empty list.
        if ($untagged->isEmpty() && $untagged->currentPage() > 1) {
            $untagged = $query->paginate(30, ['*'], 'untagged_page', max(1, $untagged->lastPage()));
        }

        $untagged = $untagged->withQueryString()
            ->through(fn (BdtEntry $e) => [
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
            'moves' => WalletMove::latest('moved_on')->latest('id')
                ->paginate(20, ['*'], 'moves_page')
                ->withQueryString()
                ->through(fn (WalletMove $m) => [
                    'id' => $m->id, 'date' => $m->moved_on->toDateString(), 'from' => $m->from_wallet,
                    'to' => $m->to_wallet, 'amount' => $m->amount, 'fee' => $m->fee, 'note' => $m->note,
                ]),
            'options' => ['wallets' => Lookups::wallets()],
            'fees' => ['mfs_percent' => MoveFees::mfsPercent(), 'bank_fee' => MoveFees::bankFee()],
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

    /** Gives every row that still has no place the same place. */
    public function tagRest(Request $request): RedirectResponse
    {
        $data = $request->validate(['wallet' => ['required', Rule::in(array_keys(BdtEntry::WALLETS))]]);
        $count = 0;

        BdtEntry::whereNull('wallet')->orderBy('id')->each(function (BdtEntry $entry) use ($data, &$count) {
            $entry->update(['wallet' => $data['wallet']]);
            $count++;
        });

        $this->toast(__(':countটা entry :place-এ রাখা হলো।', ['count' => $count, 'place' => BdtEntry::WALLETS[$data['wallet']]]));

        return back();
    }

    public function storeMove(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'moved_on' => ['required', 'date', 'before_or_equal:'.now()->addDay()->toDateString()],
            'from_wallet' => ['required', Rule::in(array_keys(BdtEntry::WALLETS))],
            'to_wallet' => ['required', 'different:from_wallet', Rule::in(array_keys(BdtEntry::WALLETS))],
            'amount' => ['required', Money::RULE],
            'fee' => ['nullable', Money::RULE],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $amount = Money::toMinor($data['amount']);

        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount' => __('পরিমাণ ০-এর বেশি হতে হবে।')]);
        }

        // The usual fee unless one was typed: MFS out comes off the amount, a bank transfer is on top.
        $usual = MoveFees::forMove($data['from_wallet'], $amount);
        $fee = isset($data['fee']) && $data['fee'] !== '' ? Money::toMinor($data['fee']) : $usual['fee'];
        $arrives = $usual['inside'] ? $amount - $fee : $amount;

        if ($arrives <= 0) {
            throw ValidationException::withMessages(['fee' => __('Fee টাকার পরিমাণের চেয়ে বেশি হতে পারে না।')]);
        }

        $from = BdtEntry::WALLETS[$data['from_wallet']];
        $to = BdtEntry::WALLETS[$data['to_wallet']];

        DB::transaction(function () use ($data, $arrives, $fee, $from, $to, $request) {
            $entry = MoveFees::book($fee, $data['from_wallet'], $data['moved_on'], "{$from} → {$to} fee (".number_format($arrives / 100, 2).')');

            WalletMove::create([
                'moved_on' => $data['moved_on'], 'from_wallet' => $data['from_wallet'], 'to_wallet' => $data['to_wallet'],
                'amount' => $arrives, 'fee' => $fee, 'fee_entry_id' => $entry?->id, 'note' => $data['note'] ?? null,
                'created_by' => $request->user()->id,
            ]);
        });

        $this->toast($fee > 0
            ? __(':from থেকে :to-তে :amount গেছে, fee :fee খরচ হিসেবে লেখা হলো।', ['from' => $from, 'to' => $to, 'amount' => '৳'.number_format($arrives / 100, 2), 'fee' => '৳'.number_format($fee / 100, 2)])
            : __(':from থেকে :to-তে টাকা সরানো লেখা হয়েছে।', ['from' => $from, 'to' => $to]));

        return back();
    }

    public function destroyMove(WalletMove $move): RedirectResponse
    {
        DB::transaction(function () use ($move) {
            BdtEntry::find($move->fee_entry_id)?->delete();
            $move->delete();
        });
        $this->toast(__('টাকা সরানোর entry মুছে ফেলা হয়েছে।'));

        return back();
    }
}
