<?php

namespace App\Payments;

use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\PaymentSms;
use App\Models\User;
use App\Services\Invoices;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Confirms wallet payments from the wallet's own incoming-money SMS.
 *
 * The usual path needs nothing from the client: choosing a wallet on the
 * invoice page starts a short session for an exact amount on that wallet,
 * and the SMS for that wallet and amount settles it. A TrxID typed by the
 * client (fallback) or a sender number narrows the match further.
 */
class PaymentMatcher
{
    /** How long a pay session waits for the money. */
    public const SESSION_MINUTES = 30;

    /** SMS that arrive a little after the session ended still count. */
    public const GRACE_MINUTES = 30;

    /** SMS older than this are not used to confirm TrxID claims. */
    public const WINDOW_DAYS = 7;

    public function __construct(private Invoices $invoices) {}

    /**
     * Opens (or reuses) a wallet session for what is left to pay. When another
     * open session on the same wallet expects the same amount, this one asks
     * for ৳1 more (and again) so each payment stays identifiable.
     */
    public function startSession(Invoice $invoice, PaymentMethod $method, ?string $payerNumber = null): InvoicePayment
    {
        $this->expireStale();

        $existing = $invoice->payments()
            ->where('status', 'pending')
            ->where('method', $method->value)
            ->whereNull('reference')
            ->where('expires_at', '>', now())
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        // One open session per invoice: switching wallets closes the other.
        $invoice->payments()->where('status', 'pending')->whereNotNull('expires_at')->update(['status' => 'expired']);

        return DB::transaction(function () use ($invoice, $method, $payerNumber): InvoicePayment {
            // Whole taka, so the client can type it exactly.
            $amount = (int) ceil($invoice->due() / 100) * 100;

            while ($this->openSessions($method)->where('amount', $amount)->lockForUpdate()->exists()) {
                $amount += 100;
            }

            $payment = $invoice->payments()->create([
                'method' => $method->value,
                'amount' => $amount,
                'sender' => $payerNumber,
                'status' => 'pending',
                'expires_at' => now()->addMinutes(self::SESSION_MINUTES),
            ]);

            $this->forPayment($payment);

            return $payment->refresh();
        });
    }

    /** A new or updated payment: look for an SMS that already arrived for it. */
    public function forPayment(InvoicePayment $payment): bool
    {
        if ($payment->status !== 'pending' || ! $payment->method->isSession()) {
            return false;
        }

        $sms = PaymentSms::query()
            ->where('status', 'unmatched')
            ->when($payment->method !== PaymentMethod::BanglaQr, fn (Builder $query) => $query->where('provider', $payment->method->value));

        if (filled($payment->reference)) {
            $sms = $sms->where('trx_id', strtoupper(trim((string) $payment->reference)))
                ->where('received_at', '>=', now()->subDays(self::WINDOW_DAYS))
                ->first();
        } else {
            $candidates = $sms->where('amount', $payment->amount)
                ->where('received_at', '>=', $payment->created_at->subMinutes(self::SESSION_MINUTES))
                ->get()
                ->filter(fn (PaymentSms $candidate): bool => $this->samePayer($payment->sender, $candidate->payer_number));

            $sms = $candidates->count() === 1 ? $candidates->first() : null;
        }

        return $sms instanceof PaymentSms && $this->settle($payment, $sms);
    }

    /** A new SMS arrived: settle the TrxID claim or the open session it belongs to. */
    public function forSms(PaymentSms $sms): bool
    {
        if ($sms->status !== 'unmatched' || $sms->trx_id === null || $sms->amount === null) {
            return false;
        }

        $methods = [$sms->provider, PaymentMethod::BanglaQr->value];

        $claim = InvoicePayment::query()
            ->where('status', 'pending')
            ->whereIn('method', $methods)
            ->whereRaw('upper(trim(reference)) = ?', [$sms->trx_id])
            ->oldest()
            ->first();

        if ($claim !== null) {
            return $this->settle($claim, $sms);
        }

        $sessions = InvoicePayment::query()
            ->where('status', 'pending')
            ->whereIn('method', $methods)
            ->whereNull('reference')
            ->where('amount', $sms->amount)
            ->where('created_at', '<=', $sms->received_at->addMinutes(5))
            ->where('expires_at', '>=', $sms->received_at->subMinutes(self::GRACE_MINUTES))
            ->get()
            ->filter(fn (InvoicePayment $payment): bool => $this->samePayer($payment->sender, $sms->payer_number));

        // Prefer the session on the exact wallet over a Bangla QR one; never guess between equals.
        $exact = $sessions->filter(fn (InvoicePayment $p) => $p->method->value === $sms->provider);
        $session = $exact->count() === 1 ? $exact->first() : ($exact->isEmpty() && $sessions->count() === 1 ? $sessions->first() : null);

        return $session !== null && $this->settle($session, $sms);
    }

    /** The Owner picked the invoice an unmatched SMS belongs to. */
    public function assign(PaymentSms $sms, Invoice $invoice, User $by): bool
    {
        if ($sms->status !== 'unmatched' || $sms->amount === null || $invoice->status === 'void') {
            return false;
        }

        $method = PaymentMethod::tryFrom($sms->provider) ?? PaymentMethod::Bank;
        $payment = $invoice->payments()->create([
            'method' => $method->value,
            'amount' => $sms->amount,
            'reference' => $sms->trx_id,
            'sender' => $sms->payer_number,
            'status' => 'pending',
        ]);

        return $this->settle($payment, $sms, $by);
    }

    public function expireStale(): void
    {
        InvoicePayment::query()
            ->where('status', 'pending')
            ->whereNull('reference')
            ->where('expires_at', '<', now()->subMinutes(self::GRACE_MINUTES))
            ->update(['status' => 'expired']);
    }

    /** @return Builder<InvoicePayment> */
    protected function openSessions(PaymentMethod $method): Builder
    {
        return InvoicePayment::query()
            ->where('status', 'pending')
            ->where('method', $method->value)
            ->whereNull('reference')
            ->where('expires_at', '>', now());
    }

    /** When both sides know the sending number, they must agree (last 10 digits; masked digits ignored). */
    protected function samePayer(?string $expected, ?string $actual): bool
    {
        $expected = substr(preg_replace('/\D+/', '', (string) $expected) ?? '', -10);
        $actual = (string) $actual;

        if (strlen($expected) < 10 || $actual === '' || str_contains($actual, '*')) {
            return true;
        }

        return substr(preg_replace('/\D+/', '', $actual) ?? '', -10) === $expected;
    }

    protected function settle(InvoicePayment $payment, PaymentSms $sms, ?User $by = null): bool
    {
        if ((int) $sms->amount < $payment->amount && $by === null) {
            $payment->forceFill(['note' => __('এই TrxID-এর SMS পাওয়া গেছে, কিন্তু এসেছে মাত্র :amount।', ['amount' => Money::bdt((int) $sms->amount)])])->save();

            return false;
        }

        return DB::transaction(function () use ($payment, $sms, $by): bool {
            $claimed = PaymentSms::query()->whereKey($sms->id)->where('status', 'unmatched')->update(['status' => 'matched', 'invoice_payment_id' => $payment->id]);

            if ($claimed === 0) {
                return false;
            }

            $payment->forceFill([
                'reference' => $payment->reference ?: $sms->trx_id,
                'sender' => $sms->payer_number ?: $payment->sender,
            ])->save();

            if (! $this->invoices->verify($payment, (int) $sms->amount, $by, $sms->received_at->toDateString())) {
                PaymentSms::query()->whereKey($sms->id)->update(['status' => 'unmatched', 'invoice_payment_id' => null]);

                return false;
            }

            return true;
        });
    }
}
