<?php

namespace App\Http\Controllers\Books;

use App\Http\Controllers\Controller;
use App\Models\Reconciliation;
use App\Services\Books;
use App\Support\Money;
use App\Support\Paging;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Compare the balance the card's app shows with what the books say.
 */
class ReconcileController extends Controller
{
    public function index(Books $books): Response
    {
        $summary = $books->summary();

        return Inertia::render('books/Reconcile', [
            'card' => $summary['card'],
            'platform_due' => $summary['platform_due'],
            'business_usd' => $summary['business_usd'],
            'history' => Reconciliation::with('author:id,name')->latest('checked_on')->latest('id')->paginate(Paging::size(request()))->withQueryString()
                ->through(fn ($r) => [
                    'id' => $r->id,
                    'date' => $r->checked_on->toDateString(),
                    'app' => $r->app_balance,
                    'system' => $r->system_balance,
                    'difference' => $r->app_balance - $r->system_balance,
                    'note' => $r->note,
                    'by' => $r->author?->name,
                ]),
        ]);
    }

    public function store(Request $request, Books $books): RedirectResponse
    {
        $data = $request->validate([
            'checked_on' => ['required', 'date'],
            'app_balance' => ['required', Money::RULE],
            'note' => ['nullable', 'string', 'max:1000'],
        ], [], ['app_balance' => __('App-এর balance')]);

        $reconciliation = Reconciliation::create([
            'checked_on' => $data['checked_on'],
            'app_balance' => Money::toMinor($data['app_balance']),
            'system_balance' => $books->summary()['card'],
            'note' => $data['note'],
        ]);

        $difference = $reconciliation->app_balance - $reconciliation->system_balance;

        $difference === 0
            ? $this->toast(__('মিলেছে — card-এর balance খাতার সাথে এক।'))
            : $this->toast(__('মেলেনি — পার্থক্য $:amount। কোন entry বাদ পড়েছে দেখুন।', ['amount' => number_format(abs($difference) / 100, 2)]), 'warning');

        return back();
    }
}
