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
    /**
     * @param  int|null  $wanted  poisha the client chose to pay now (an invoice that allows
     *                            part payments); null pays everything due.
     */
    public function startSession(Invoice $invoice, PaymentMethod $method, ?string $payerNumber = null, ?int $wanted = null): InvoicePayment
    {
        $this->expireStale();

        // Whole taka, so the client can type it exactly; the payment charge for this way goes on top.
        $base = (int) ceil(($wanted !== null ? min($wanted, $invoice->due()) : $invoice->due()) / 100) * 100;
        $charge = PaySettings::charge($method, $base);
        $base += $charge;

        $existing = $invoice->payments()
            ->where('status', 'pending')
            ->where('method', $method->value)
            ->whereNull('reference')
            ->where('expires_at', '>', now())
            ->first();

        // The same payment again (a reload) keeps its amount; a new amount is a new payment.
        if ($existing !== null && $existing->amount >= $base && $existing->amount < $base + 100 * 50 && $existing->sender === $payerNumber) {
            return $existing;
        }

        // One open session per invoice: switching wallets closes the other.
        $invoice->payments()->where('status', 'pending')->whereNotNull('expires_at')->update(['status' => 'expired']);

        return DB::transaction(function () use ($invoice, $method, $payerNumber, $base, $charge): InvoicePayment {
            $amount = $base;

            while ($this->openSessions($method)->where('amount', $amount)->lockForUpdate()->exists()) {
                $amount += 100;
            }

            $payment = $invoice->payments()->create([
                'method' => $method->value,
                'amount' => $amount,
                'charge' => $charge,
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
            $candidates = $sms->whereBetween('amount', [$payment->amount - $this->shortBy($payment), $payment->amount])
                ->where('received_at', '>=', $payment->created_at->subMinutes(self::SESSION_MINUTES))
                ->get()
                ->filter(fn (PaymentSms $candidate): bool => $this->samePayer($payment->sender, $candidate->payer_number));
            $exact = $candidates->where('amount', $payment->amount);

            $sms = $exact->count() === 1 ? $exact->first() : ($exact->isEmpty() && $candidates->count() === 1 ? $candidates->first() : null);
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

        // Already written by hand with this TrxID: the SMS is that payment's proof, not a new one.
        $byHand = InvoicePayment::query()
            ->where('status', 'verified')
            ->whereRaw('upper(trim(reference)) = ?', [$sms->trx_id])
            ->first();

        if ($byHand !== null) {
            PaymentSms::query()->whereKey($sms->id)->where('status', 'unmatched')->update(['status' => 'matched', 'invoice_payment_id' => $byHand->id]);

            return true;
        }

        $sessions = InvoicePayment::query()
            ->where('status', 'pending')
            ->whereIn('method', $methods)
            ->whereNull('reference')
            // A Bangla QR session may get a little less: the bank takes its fee before the SMS.
            ->whereBetween('amount', [$sms->amount, $sms->amount + PaySettings::qrShortBy((int) $sms->amount * 2)])
            ->where('created_at', '<=', $sms->received_at->addMinutes(5))
            ->where('expires_at', '>=', $sms->received_at->subMinutes(self::GRACE_MINUTES))
            ->get()
            ->filter(fn (InvoicePayment $payment): bool => $payment->amount - $sms->amount <= $this->shortBy($payment))
            ->filter(fn (InvoicePayment $payment): bool => $this->samePayer($payment->sender, $sms->payer_number));

        // Prefer the session on the exact wallet, then the exact amount; never guess between equals.
        $exact = $sessions->filter(fn (InvoicePayment $p) => $p->method->value === $sms->provider);
        $pool = $exact->isNotEmpty() ? $exact : $sessions;
        $same = $pool->where('amount', $sms->amount);
        $session = $same->count() === 1 ? $same->first() : ($same->isEmpty() && $pool->count() === 1 ? $pool->first() : null);

        return $session !== null && $this->settle($session, $sms);
    }

    /**
     * A TrxID typed in by hand: is it taken already — by another payment, or by an SMS that
     * already paid something?
     */
    public static function referenceUsed(string $reference, ?int $exceptPaymentId = null): bool
    {
        $reference = strtoupper(trim($reference));

        return InvoicePayment::query()
            ->whereIn('status', ['pending', 'verified'])
            ->whereRaw('upper(trim(reference)) = ?', [$reference])
            ->when($exceptPaymentId, fn (Builder $q) => $q->whereKeyNot($exceptPaymentId))
            ->exists()
            || PaymentSms::query()->where('trx_id', $reference)->where('status', 'matched')->exists();
    }

    /** The SMS (on the server, not yet used) of a TrxID typed in by hand, if it came. */
    public static function smsFor(string $reference): ?PaymentSms
    {
        return PaymentSms::query()->where('trx_id', strtoupper(trim($reference)))->where('status', 'unmatched')->first();
    }

    /**
     * A payment written by hand with a TrxID: its SMS is tied to it, so the SMS no longer
     * shows as unmatched and can never pay a second invoice.
     */
    public function linkSms(InvoicePayment $payment): ?PaymentSms
    {
        $sms = filled($payment->reference) ? self::smsFor((string) $payment->reference) : null;

        if ($sms !== null) {
            PaymentSms::query()->whereKey($sms->id)->where('status', 'unmatched')->update(['status' => 'matched', 'invoice_payment_id' => $payment->id]);
        }

        return $sms;
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

    /** How far short of a payment its SMS may come: only Bangla QR, by the bank's fee. */
    protected function shortBy(InvoicePayment $payment): int
    {
        return $payment->method === PaymentMethod::BanglaQr ? PaySettings::qrShortBy((int) $payment->amount) : 0;
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

    /**
     * The number the client said they pay from, against the one in the wallet's SMS. No number
     * given (Bangla QR, bank): nothing to compare. Given: the SMS must show it — a masked one
     * (017****45) by the digits it shows; an SMS without any number is not taken on trust.
     */
    protected function samePayer(?string $expected, ?string $actual): bool
    {
        $expected = substr(preg_replace('/\D+/', '', (string) $expected) ?? '', -10);

        if (strlen($expected) < 10) {
            return true;
        }

        $shown = substr(preg_replace('/[^\d*]+/', '', (string) $actual) ?? '', -10);
        if (strlen($shown) < 10 || ! preg_match('/\d/', $shown)) {
            return false;
        }

        foreach (str_split($shown) as $i => $digit) {
            if ($digit !== '*' && $digit !== $expected[$i]) {
                return false;
            }
        }

        return true;
    }

    protected function settle(InvoicePayment $payment, PaymentSms $sms, ?User $by = null): bool
    {
        // Without the payment charge is still enough (the invoice is paid; no charge is taken), and a
        // Bangla QR payment may come short by the bank's fee.
        if ((int) $sms->amount < $payment->amount - (int) $payment->charge - $this->shortBy($payment) && $by === null) {
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
