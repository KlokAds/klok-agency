<?php

namespace App\Http\Controllers\Invoices;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Payments\PaymentMatcher;
use App\Payments\PaymentMethod;
use App\Payments\PaySettings;
use App\Services\Invoices;
use App\Support\InvoiceView;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

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
            'payer' => ['nullable', 'regex:/^(\+?88)?01[3-9]\d{8}$/'],
        ], ['payer.regex' => __('১১ সংখ্যার নম্বর দিন, যেমন 01712345678।')]);

        $method = PaymentMethod::from($data['method']);
        abort_unless($invoice->isPayable() && $method->isSession(), 422);

        $matcher->startSession($invoice, $method, $data['payer'] ?? null);

        return response()->json($this->state($invoice->refresh()));
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

        $used = InvoicePayment::where('method', $method->value)->whereIn('status', ['pending', 'verified'])
            ->whereRaw('upper(trim(reference)) = ?', [$reference])->exists();

        if ($used) {
            return response()->json(['message' => __('এই TrxID আগেই ব্যবহার হয়েছে।'), 'errors' => ['reference' => [__('এই TrxID আগেই ব্যবহার হয়েছে।')]]], 422);
        }

        $session = $invoice->payments()->where('status', 'pending')->whereNotNull('expires_at')->latest('id')->first();
        $invoice->payments()->where('status', 'pending')->whereNotNull('expires_at')->update(['status' => 'expired']);

        $payment = $invoice->payments()->create([
            'method' => $method->value,
            'amount' => isset($data['amount']) && $data['amount'] !== '' ? (int) round((float) $data['amount'] * 100) : ($session->amount ?? $invoice->due()),
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

        abort_if($invoice === null || $invoice->status === 'draft', 404);

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
            'session' => $session ? [
                'method' => $session->method->value,
                'amount' => $session->amount,
                'extra' => max(0, $session->amount - $invoice->due()),
                'seconds_left' => max(0, (int) now()->diffInSeconds($session->expires_at, false)),
            ] : null,
            'checking' => $invoice->payments->contains(fn (InvoicePayment $p) => $p->isClaim()),
            'payments' => InvoiceView::publicPayments($invoice),
        ];
    }
}
