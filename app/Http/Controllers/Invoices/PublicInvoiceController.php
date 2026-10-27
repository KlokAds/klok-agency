<?php

namespace App\Http\Controllers\Invoices;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\Renewal;
use App\Payments\BanglaQr;
use App\Payments\PaymentMatcher;
use App\Payments\PaymentMethod;
use App\Payments\PaySettings;
use App\Services\Invoices;
use App\Services\Renewals;
use App\Support\InvoiceView;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * The client's own invoice page, /i/{token}: no sign-in, the 40-character
 * token is the key. It shows this one invoice and lets the client pay it;
 * the pay area talks to the JSON endpoints below and polls for the result.
 */
class PublicInvoiceController extends Controller
{
    public function show(string $token): Response
    {
        $invoice = $this->find($token);

        return Inertia::render('public/Invoice', [
            'token' => $token,
            'business' => PaySettings::business(),
            'invoice' => InvoiceView::document($invoice),
            'methods' => (object) PaySettings::methods(),
            'state' => $this->state($invoice),
        ]);
    }

    public function status(string $token): JsonResponse
    {
        return response()->json($this->state($this->find($token)));
    }

    /** Opens a wallet / Bangla QR session for the exact amount to send. */
    public function start(Request $request, string $token, PaymentMatcher $matcher): JsonResponse
    {
        $invoice = $this->find($token);
        $data = $request->validate([
            'method' => ['required', Rule::in(array_keys(PaySettings::methods()))],
            // bKash, Nagad, Rocket: the number the money comes from, so only that SMS can match.
            'payer' => ['nullable', 'required_if:method,bkash,nagad,rocket', 'regex:/^(\+?88)?01[3-9]\d{8}$/'],
            // Part of it now, when the invoice allows it: from ৳10 up to what is due.
            'amount' => ['nullable', 'numeric', 'min:10', 'max:'.max(10, $invoice->due() / 100)],
        ], [
            'payer.regex' => __('১১ সংখ্যার নম্বর দিন, যেমন 01712345678।'),
            'payer.required_if' => __('কোন নম্বর থেকে পাঠাবেন, সেটা দিন — শুধু সেই নম্বরের টাকাই নিজে থেকে মিলবে।'),
            'amount.min' => __('কমপক্ষে ৳10 দিন।'),
            'amount.max' => __('বাকির চেয়ে বেশি দেওয়া যায় না।'),
        ]);

        $method = PaymentMethod::from($data['method']);
        abort_unless($invoice->isPayable() && $method->isSession(), 422);

        // Our own wallet number copied into "your number": the money would never match.
        $ours = substr(preg_replace('/\D+/', '', (string) (PaySettings::methods()[$method->value]['number'] ?? '')) ?? '', -10);
        if (filled($data['payer'] ?? null) && $ours !== '' && substr(preg_replace('/\D+/', '', (string) $data['payer']) ?? '', -10) === $ours) {
            throw ValidationException::withMessages(['payer' => __('এটা Klok Ads-এর নম্বর — এখানে আপনার নিজের নম্বর দিন, যেটা থেকে টাকা পাঠাবেন।')]);
        }

        // A number known as another client's is not refused here: that answer would tell anyone with
        // an invoice link who our clients are. The matcher never takes that number's money for this
        // invoice anyway (PaymentMatcher::payerOk); it waits for the Owner instead.

        $wanted = $invoice->allow_partial && isset($data['amount']) ? (int) round((float) $data['amount'] * 100) : null;
        $matcher->startSession($invoice, $method, $data['payer'] ?? null, $wanted);

        return response()->json($this->state($invoice->refresh()));
    }

    /**
     * The Bangla QR for the payment now open: the agency's own QR with this
     * exact amount filled in and locked, drawn in the brand's colours.
     */
    public function qr(string $token): \Illuminate\Http\Response
    {
        $invoice = $this->find($token);
        $session = $invoice->payments->first(fn (InvoicePayment $p) => $p->isOpenSession() && $p->method === PaymentMethod::BanglaQr);
        $qr = PaySettings::banglaQr();
        abort_if($session === null || $qr === null, 404);

        return response(BanglaQr::svg($qr->forAmount($session->amount)), 200, [
            'Content-Type' => 'image/svg+xml',
            'Cache-Control' => 'no-store',
        ]);
    }

    /** The client picks how many days this renewal is for; the total follows at the invoice's rate. */
    public function days(Request $request, string $token, Renewals $renewals): JsonResponse
    {
        $invoice = $this->find($token);
        $data = $request->validate(['days' => ['required', 'integer', 'min:1', 'max:90']]);
        $renewal = Renewal::where('invoice_id', $invoice->id)->firstOrFail();

        try {
            $renewals->changeDays($renewal, (int) $data['days']);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => ['days' => [$e->getMessage()]]], 422);
        }

        return response()->json($this->state($invoice->refresh()));
    }

    /** The client will not go on: the invoice is withdrawn, the campaign stops at its end. */
    public function stop(string $token, Renewals $renewals): JsonResponse
    {
        $invoice = $this->find($token);
        $renewal = Renewal::where('invoice_id', $invoice->id)->firstOrFail();
        $renewals->decline($renewal);

        return response()->json($this->state($invoice->refresh()));
    }

    /** @return array<string, mixed>|null */
    private function renewal(Invoice $invoice): ?array
    {
        $renewal = Renewal::with('campaign')->where('invoice_id', $invoice->id)->first();

        if ($renewal === null || $renewal->status !== 'invoiced' || $invoice->paid > 0 || $invoice->status === 'void') {
            return $renewal?->status === 'declined' ? ['declined' => true, 'stops' => $renewal->campaign?->stopsText()] : null;
        }

        $line = $invoice->items->where('kind', 'ad_budget')->where('description', '!=', Renewals::DUE_LINE)->last();

        return [
            'declined' => false,
            'days' => $renewal->days,
            'budget' => $renewal->budget,
            'rate' => $line ? (float) $line->rate : null,
            'campaign' => $renewal->campaign?->name,
            'stops' => $renewal->campaign?->stopsText(),
        ];
    }

    public function cancel(string $token): JsonResponse
    {
        $invoice = $this->find($token);
        $invoice->payments()->where('status', 'pending')->whereNotNull('expires_at')->update(['status' => 'expired']);

        return response()->json($this->state($invoice));
    }

    /**
     * Fallback: the client paid but nothing confirmed, so they give the TrxID
     * (wallets) or the transfer reference (bank) for us to check.
     */
    public function claim(Request $request, string $token, PaymentMatcher $matcher, Invoices $invoices): JsonResponse
    {
        $invoice = $this->find($token);
        abort_unless($invoice->isPayable(), 422);

        $data = $request->validate([
            'method' => ['required', Rule::in(array_keys(PaySettings::methods()))],
            'reference' => ['required', 'string', 'min:4', 'max:60', 'regex:/^[A-Za-z0-9\-\/ ]+$/'],
            'sender' => ['required', 'string', 'max:120'],
            'amount' => ['nullable', 'regex:/^\d{1,9}(\.\d{1,2})?$/'],
        ], [], ['reference' => 'TrxID / reference', 'sender' => __('কোথা থেকে পাঠিয়েছেন')]);

        $reference = strtoupper(trim($data['reference']));
        $method = PaymentMethod::from($data['method']);

        // No more than the due and its charge: a claim never carries a bigger sum than the invoice asks.
        if (isset($data['amount']) && $data['amount'] !== '' && (int) round((float) $data['amount'] * 100) > $invoice->due() + PaySettings::charge($method, $invoice->due())) {
            throw ValidationException::withMessages(['amount' => __('বাকির চেয়ে বেশি দেওয়া যায় না।')]);
        }

        // Our own wallet number copied into "sent from": the money would never be found.
        $ours = substr(preg_replace('/\D+/', '', (string) (PaySettings::methods()[$method->value]['number'] ?? '')) ?? '', -10);
        if ($ours !== '' && substr(preg_replace('/\D+/', '', (string) $data['sender']) ?? '', -10) === $ours) {
            throw ValidationException::withMessages(['sender' => __('এটা Klok Ads-এর নম্বর — এখানে আপনার নিজের নম্বর দিন, যেটা থেকে টাকা পাঠাবেন।')]);
        }

        if (PaymentMatcher::referenceUsed($reference)) {
            return response()->json(['message' => __('এই TrxID আগেই ব্যবহার হয়েছে।'), 'errors' => ['reference' => [__('এই TrxID আগেই ব্যবহার হয়েছে।')]]], 422);
        }

        $session = $invoice->payments()->where('status', 'pending')->whereNotNull('expires_at')->latest('id')->first();
        $invoice->payments()->where('status', 'pending')->whereNotNull('expires_at')->update(['status' => 'expired']);

        // The payment charge for this way rides along; verifying takes it only from what is beyond the due.
        $charge = $session !== null && $session->method === $method ? $session->charge : PaySettings::charge($method, $invoice->due());

        $payment = $invoice->payments()->create([
            'method' => $method->value,
            'amount' => isset($data['amount']) && $data['amount'] !== '' ? (int) round((float) $data['amount'] * 100) : ($session->amount ?? $invoice->due() + $charge),
            'charge' => $charge,
            'reference' => $reference,
            'sender' => trim($data['sender']),
            'status' => 'pending',
        ]);

        if (! $matcher->forPayment($payment)) {
            $invoices->claimNeedsReview($payment);
        }

        return response()->json($this->state($invoice->refresh()));
    }

    private function find(string $token): Invoice
    {
        abort_unless(strlen($token) === 40 && ctype_alnum($token), 404);

        $invoice = Invoice::with(['client:id,name,company', 'items', 'payments'])->where('public_token', $token)->first();

        abort_if($invoice === null, 404);

        return $invoice;
    }

    /**
     * Everything the pay area needs, and nothing more.
     *
     * @return array<string, mixed>
     */
    private function state(Invoice $invoice): array
    {
        $invoice->unsetRelation('payments');
        $session = $invoice->payments->first(fn (InvoicePayment $p) => $p->isOpenSession());

        return [
            'status' => $invoice->status,
            'total' => $invoice->total,
            'paid' => $invoice->paid,
            'due' => $invoice->status === 'void' ? 0 : $invoice->due(),
            'payable' => $invoice->isPayable(),
            'auto' => PaySettings::autoVerify(),
            'allow_partial' => $invoice->allow_partial,
            // Each way's payment charge on what is due, for the buttons and the bank details.
            'charges' => collect(PaySettings::methods())->keys()
                ->mapWithKeys(fn (string $key) => [$key => PaySettings::charge(PaymentMethod::from($key), $invoice->status === 'void' ? 0 : $invoice->due())])
                ->all(),
            'session' => $session ? [
                'method' => $session->method->value,
                'amount' => $session->amount,
                // The number the client said they pay from, to remind them on the pay screen.
                'sender' => $session->sender,
                // The amount in the URL makes a new picture whenever the amount changes.
                'qr' => $session->method === PaymentMethod::BanglaQr && PaySettings::banglaQr() !== null
                    ? route('invoice.public.qr', [$invoice->public_token, 'a' => $session->amount]) : null,
                'charge' => $session->charge,
                'extra' => max(0, $session->amount - $session->charge - $invoice->due()),
                'seconds_left' => max(0, (int) now()->diffInSeconds($session->expires_at, false)),
            ] : null,
            'checking' => $invoice->payments->contains(fn (InvoicePayment $p) => $p->isClaim()),
            'payments' => InvoiceView::publicPayments($invoice),
            // A renewal's invoice not paid yet: the client can pick the days here (or stop).
            'renewal' => $this->renewal($invoice),
        ];
    }
}
