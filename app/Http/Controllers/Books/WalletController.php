<?php

namespace App\Http\Controllers\Books;

use App\Http\Controllers\Controller;
use App\Models\BdtEntry;
use App\Models\ExpenseHead;
use App\Models\Setting;
use App\Models\Wallet;
use App\Models\WalletMove;
use App\Services\Books;
use App\Services\MoveFees;
use App\Support\Lookups;
use App\Support\Money;
use App\Support\Paging;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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

        $opening = Setting::get('wallet_opening_on');
        $query = BdtEntry::with('client:id,name')->whereNull('wallet')
            ->when($opening, fn ($q, $day) => $q->whereDate('entry_date', '>', $day))
            ->orderByRaw('entry_date is null')->orderBy('entry_date')->orderBy('sequence')->orderBy('id');
        $untagged = $query->clone()->paginate(Paging::size($request), ['*'], 'untagged_page');

        // Tagging the last rows of the last page: show the page before it, not an empty list.
        if ($untagged->isEmpty() && $untagged->currentPage() > 1) {
            $untagged = $query->paginate(Paging::size($request), ['*'], 'untagged_page', max(1, $untagged->lastPage()));
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
                ->paginate(Paging::size($request), ['*'], 'moves_page')
                ->withQueryString()
                ->through(fn (WalletMove $m) => [
                    'id' => $m->id, 'date' => $m->moved_on->toDateString(), 'from' => $m->from_wallet,
                    'to' => $m->to_wallet, 'amount' => $m->amount, 'fee' => $m->fee, 'note' => $m->note,
                ]),
            'options' => ['wallets' => Lookups::wallets(), 'types' => collect(Wallet::TYPES)->map(fn ($label, $key) => ['value' => $key, 'label' => __($label)])->values()],
            'list' => Wallet::places()->map(fn (Wallet $w) => [
                'id' => $w->id, 'key' => $w->key, 'name' => $w->name, 'type' => $w->type, 'detail' => $w->detail,
                'opening_balance' => $w->opening_balance, 'is_active' => $w->is_active,
                'used' => BdtEntry::where('wallet', $w->key)->exists() || WalletMove::where('from_wallet', $w->key)->orWhere('to_wallet', $w->key)->exists(),
            ])->values(),
            'fees' => ['mfs_percent' => MoveFees::mfsPercent(), 'bank_fee' => MoveFees::bankFee()],
        ]);
    }

    /** Sets the place of one BDT row (its fee row follows a payment). */
    public function tag(Request $request, BdtEntry $entry): RedirectResponse
    {
        $data = $request->validate(['wallet' => ['nullable', Rule::in(Wallet::keys())]]);

        $entry->update(['wallet' => $data['wallet'] ?? null]);
        $entry->fee()->first()?->update(['wallet' => $data['wallet'] ?? null]);

        return back();
    }

    /** Gives every row that still has no place the same place. */
    public function tagRest(Request $request): RedirectResponse
    {
        $data = $request->validate(['wallet' => ['required', Rule::in(Wallet::keys())]]);
        $count = 0;

        BdtEntry::whereNull('wallet')
            ->when(Setting::get('wallet_opening_on'), fn ($q, $day) => $q->whereDate('entry_date', '>', $day))
            ->orderBy('id')->each(function (BdtEntry $entry) use ($data, &$count) {
                $entry->update(['wallet' => $data['wallet']]);
                $count++;
            });

        $this->toast(__(':countটা entry :place-এ রাখা হলো।', ['count' => $count, 'place' => Wallet::nameOf($data['wallet'])]));

        return back();
    }

    public function storeMove(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'moved_on' => ['required', 'date', 'before_or_equal:'.now()->addDay()->toDateString()],
            'from_wallet' => ['required', Rule::in(Wallet::keys())],
            'to_wallet' => ['required', 'different:from_wallet', Rule::in(Wallet::keys())],
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

        $from = Wallet::nameOf($data['from_wallet']);
        $to = Wallet::nameOf($data['to_wallet']);

        DB::transaction(function () use ($data, $arrives, $fee, $from, $to, $request) {
            $entry = MoveFees::book($fee, $data['from_wallet'], $data['moved_on'], "{$from} → {$to} fee (".number_format($arrives / 100, 2).')');

            WalletMove::create([
                'moved_on' => $data['moved_on'], 'from_wallet' => $data['from_wallet'], 'to_wallet' => $data['to_wallet'],
                'amount' => $arrives, 'fee' => $fee, 'fee_entry_id' => $entry?->id, 'note' => $data['note'] ?? null,
                'created_by' => $request->user()->id,
            ]);
        });

        $this->toast($fee > 0
            ? __(':from থেকে :to-তে :amount গেছে, fee :fee খরচ হিসেবে লেখা হলো।', ['from' => $from, 'to' => $to, 'amount' => "\u{09F3}".number_format($arrives / 100, 2), 'fee' => "\u{09F3}".number_format($fee / 100, 2)])
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

    public function storePlace(Request $request): RedirectResponse
    {
        $data = $this->placeData($request);
        $data['key'] = $this->newKey($data['name']);
        $data['sort'] = (int) Wallet::max('sort') + 1;

        Wallet::create($data);
        $this->toast(__(':name যোগ হয়েছে।', ['name' => $data['name']]));

        return back();
    }

    public function updatePlace(Request $request, Wallet $wallet): RedirectResponse
    {
        $wallet->update($this->placeData($request) + ['is_active' => $request->boolean('is_active', true)]);
        $this->toast(__(':name আপডেট হয়েছে।', ['name' => $wallet->name]));

        return back();
    }

    /** A place with history is switched off instead, so old rows keep their name. */
    public function destroyPlace(Wallet $wallet): RedirectResponse
    {
        $used = BdtEntry::withTrashed()->where('wallet', $wallet->key)->exists()
            || WalletMove::withTrashed()->where('from_wallet', $wallet->key)->orWhere('to_wallet', $wallet->key)->exists();

        if ($used) {
            $wallet->update(['is_active' => false]);
            $this->toast(__(':name-এ লেনদেন আছে, তাই মোছা হয়নি — বন্ধ করে রাখা হলো।', ['name' => $wallet->name]));
        } else {
            $wallet->delete();
            $this->toast(__(':name মুছে ফেলা হয়েছে।', ['name' => $wallet->name]));
        }

        return back();
    }

    /**
     * The real balance of every place, counted on a day. From then on each
     * place starts there; older rows no longer need a place.
     */
    public function opening(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'opening_on' => ['required', 'date', 'before_or_equal:today'],
            'balances' => ['required', 'array'],
            'balances.*' => ['nullable', Money::RULE],
        ]);

        DB::transaction(function () use ($data) {
            foreach (Wallet::places() as $wallet) {
                $wallet->update(['opening_balance' => Money::toMinor($data['balances'][$wallet->key] ?? '0')]);
            }

            Setting::put('wallet_opening_on', $data['opening_on']);
            // Counted today: at this moment (later rows of today still count). Another day: its end.
            Setting::put('wallet_opening_at', $data['opening_on'] === now()->toDateString() ? now()->toIso8601String() : null);
        });

        $this->toast(__('আসল balance বসানো হয়েছে — :date থেকে হিসাব চলবে।', ['date' => $data['opening_on']]));

        return back();
    }

    /**
     * Books the difference between the books and the counted places as an
     * adjustment on the opening day, so both say the same.
     */
    public function adjust(Books $books): RedirectResponse
    {
        $wallets = $books->wallets();
        $gap = $wallets['unexplained'];

        if ($gap === 0 || $wallets['opening_on'] === null) {
            return back();
        }

        // The books have more than the places: it went somewhere as an expense; less: unrecorded income.
        BdtEntry::create([
            'entry_date' => $wallets['opening_on'],
            'description' => 'Balance adjustment (counted places)',
            'category' => $gap > 0 ? 'expense' : 'other_income',
            'expense_head_id' => $gap > 0 ? ExpenseHead::firstOrCreate(['name' => 'Others'])->id : null,
            'amount' => -$gap,
        ]);

        $this->toast(__('অমিল :amount খাতায় তোলা হলো।', ['amount' => "\u{09F3}".number_format(abs($gap) / 100, 2)]));

        return back();
    }

    /** @return array{name: string, type: string, detail: string|null, opening_balance?: int} */
    private function placeData(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'type' => ['required', Rule::in(array_keys(Wallet::TYPES))],
            'detail' => ['nullable', 'string', 'max:120'],
        ]);

        return ['name' => trim($data['name']), 'type' => $data['type'], 'detail' => $data['detail'] ?? null];
    }

    private function newKey(string $name): string
    {
        $base = Str::of($name)->ascii()->lower()->replaceMatches('/[^a-z0-9]+/', '')->limit(12, '')->toString() ?: 'place';
        $key = $base;

        for ($i = 2; Wallet::where('key', $key)->exists(); $i++) {
            $key = substr($base, 0, 10).$i;
        }

        return $key;
    }
}
