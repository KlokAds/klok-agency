<?php

namespace App\Http\Controllers\Books;

use App\Http\Controllers\Controller;
use App\Models\MetaReceipt;
use App\Models\Setting;
use App\Services\MetaReceipts;
use App\Support\Paging;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * Meta's payment receipts as they arrived in the receipts mailbox, what
 * each became in the books, and the ones a person should look at.
 */
class MetaReceiptController extends Controller
{
    public function index(Request $request): Response
    {
        $receipts = MetaReceipt::with(['account:id,name', 'entry:id,amount'])
            ->where('status', '!=', 'ignored')
            ->orderByRaw("case status when 'unread' then 0 else 1 end")
            ->latest('id')
            ->paginate(Paging::size($request))
            ->withQueryString()
            ->through(fn (MetaReceipt $r) => [
                'id' => $r->id,
                'status' => $r->status,
                'charged_at' => $r->charged_at?->toDateString(),
                'amount' => $r->amount,
                'currency' => $r->currency,
                'transaction_id' => $r->transaction_id,
                'account' => $r->pilot_account_id !== null ? $r->account->name : $r->account_ref,
                'card' => $r->card_last4,
                'campaigns' => $r->campaigns ?? [],
                'problem' => $r->problem,
                'subject' => $r->subject,
                'entry_id' => $r->usd_entry_id,
            ]);

        return Inertia::render('books/MetaReceipts', [
            'receipts' => $receipts,
            'statuses' => MetaReceipt::STATUSES,
            'configured' => MetaReceipts::configured(),
            'checked_at' => Setting::get('meta_receipts_checked_at'),
            'error' => Setting::get('meta_receipts_error'),
        ]);
    }

    /** Looks at the mailbox now instead of waiting for the next few minutes. */
    public function check(MetaReceipts $receipts): RedirectResponse
    {
        try {
            $n = $receipts->check();
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['mailbox' => $e->getMessage()]);
        }

        $this->toast($n ? __(':nটা নতুন mail পড়া হয়েছে।', ['n' => $n]) : __('নতুন কোনো receipt নেই।'));

        return back();
    }

    /** Reads a kept receipt again, once its names or account were matched. */
    public function retry(MetaReceipt $receipt, MetaReceipts $receipts): RedirectResponse
    {
        $again = $receipts->retry($receipt);
        $this->toast($again->status === 'booked' ? __('এবার মিলেছে — খাতায় উঠেছে।') : __('এখনো মেলেনি: :problem', ['problem' => (string) $again->problem]));

        return redirect()->route('dollars.receipts.show', $again);
    }

    /** The receipt as it came, to read it yourself. */
    public function show(MetaReceipt $receipt): Response
    {
        return Inertia::render('books/MetaReceipt', [
            'receipt' => [
                'id' => $receipt->id,
                'status' => $receipt->status,
                'subject' => $receipt->subject,
                'body' => $receipt->body,
                'problem' => $receipt->problem,
                'entry_id' => $receipt->usd_entry_id,
            ],
            'statuses' => MetaReceipt::STATUSES,
        ]);
    }
}
