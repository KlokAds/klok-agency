<?php

namespace App\Http\Controllers\Invoices;

use App\Http\Controllers\Controller;
use App\Models\InvoicePayment;
use App\Models\PaymentSms;
use App\Models\Setting;
use App\Notifications\AgencyAlert;
use App\Payments\PaymentMatcher;
use App\Payments\SmsParser;
use App\Services\Invoices;
use App\Services\Notifier;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/**
 * Receives SMS forwarded by the Android phone holding the agency's wallet
 * SIM. Accepts the field names used by common SMS-forwarder apps
 * (from/sender/address, text/message/body); an empty body is a heartbeat.
 *
 * Only money SMS are kept, and only to pay invoices: one no invoice takes
 * sends no notification and is deleted after a day (App\Services\Ticker),
 * long enough for a TrxID the client types in a little later.
 */
class SmsHookController extends Controller
{
    public const CHECK_TITLE = 'পেমেন্ট এসেছে, কিন্তু নম্বর মেলেনি — মিলিয়ে দিন'; // i18n

    /** How long a money SMS no invoice took stays on the server. */
    public const KEEP_UNMATCHED_HOURS = 24;

    public function __invoke(Request $request, string $token, SmsParser $parser, PaymentMatcher $matcher, Invoices $invoices): JsonResponse
    {
        $hash = Setting::get('sms_device_token');

        abort_if(! is_string($hash) || $hash === '' || ! Hash::check($token, $hash), 404);

        Setting::putQuietly('sms_last_seen', now()->toIso8601String());

        $sender = (string) $this->first($request, ['from', 'sender', 'address', 'phone', 'number']);
        $body = trim((string) $this->first($request, ['text', 'message', 'body', 'content', 'msg', 'sms']));

        if ($body === '') {
            return response()->json(['status' => 'heartbeat']);
        }

        $parsed = $parser->parse($sender, $body);
        $receivedAt = $this->timestamp($this->first($request, ['receivedStamp', 'sentStamp', 'timestamp', 'received_at', 'date']));

        // Money the wallet sent back to a number that paid: that payment's refund.
        if ($parsed === null && ($sent = $parser->parseSent($sender, $body)) !== null) {
            $refunded = $this->refund($sent, $sender, $body, $receivedAt, $invoices);

            if ($refunded !== null) {
                return response()->json(['status' => 'refund', 'invoice' => $refunded]);
            }
        }

        // Not money coming in (an OTP, an offer, money going out): not kept at all.
        if ($parsed === null) {
            return response()->json(['status' => 'ignored']);
        }

        $sms = PaymentSms::firstOrCreate(
            ['provider' => $parsed['provider'], 'trx_id' => $parsed['trx_id']],
            [
                'sender' => mb_substr($sender, 0, 60),
                'body' => mb_substr($body, 0, 2000),
                'amount' => $parsed['amount'],
                'payer_number' => $parsed['payer_number'],
                'status' => 'unmatched',
                'received_at' => $receivedAt,
            ],
        );

        $matched = $sms->wasRecentlyCreated && $matcher->forSms($sms);

        // Not taken, but an open payment waits for exactly this amount (the client gave another
        // number than the one they paid from): the Owner checks it and matches it by hand.
        if ($sms->wasRecentlyCreated && ! $matched && ($near = $matcher->nearMatch($sms)) !== null) {
            app(Notifier::class)->send(null, new AgencyAlert('sms_check', self::CHECK_TITLE, '{amount} · {from} · Invoice {number} — client অন্য নম্বর দিয়েছিলেন ({given})', [ // i18n
                'amount' => Money::bdt((int) $sms->amount), 'from' => $sms->payer_number ?? '—',
                'number' => $near->invoice->number, 'given' => $near->sender ?? '—',
            ], ['url' => '/invoices', 'important' => true]));
        }
        // The phone's list says which invoice it paid.
        $invoice = $matched ? InvoicePayment::with('invoice:id,number')->find($sms->fresh()?->invoice_payment_id)?->invoice?->number : null;

        return response()->json(array_filter([
            'status' => $matched ? 'matched' : ($sms->wasRecentlyCreated ? 'stored' : 'duplicate'),
            'invoice' => $invoice,
        ]));
    }

    /**
     * A Send Money back to the number a confirmed payment came from, of what it paid (with or
     * without its charge), within a month: that payment is refunded. Returns its invoice number.
     *
     * @param  array{provider: string, amount: int, to: string, fee: int, trx_id: string}  $sent
     */
    private function refund(array $sent, string $sender, string $body, CarbonImmutable $at, Invoices $invoices): ?string
    {
        if (PaymentSms::where('provider', $sent['provider'])->where('trx_id', $sent['trx_id'])->exists()) {
            return null;
        }

        $to = substr(preg_replace('/\D+/', '', $sent['to']) ?? '', -10);
        $payment = InvoicePayment::with('invoice:id,number')
            ->where('status', 'verified')
            ->where('method', $sent['provider'])
            ->where('verified_at', '>=', now()->subDays(30))
            ->where(fn ($q) => $q->where('amount', $sent['amount'])->orWhereRaw('amount - charge = ?', [$sent['amount']]))
            ->latest('verified_at')
            ->get()
            ->first(fn (InvoicePayment $p) => substr(preg_replace('/\D+/', '', (string) $p->sender) ?? '', -10) === $to);

        if ($payment === null || ! $invoices->refund($payment, $sent['fee'], $at->toDateString(), $sent['trx_id'])) {
            return null;
        }

        PaymentSms::create([
            'provider' => $sent['provider'],
            'trx_id' => $sent['trx_id'],
            'sender' => mb_substr($sender, 0, 60),
            'body' => mb_substr($body, 0, 2000),
            'amount' => $sent['amount'],
            'payer_number' => $sent['to'],
            'status' => 'refund',
            'invoice_payment_id' => $payment->id,
            'received_at' => $at,
        ]);

        return $payment->invoice?->number;
    }

    /** @param  list<string>  $keys */
    private function first(Request $request, array $keys): mixed
    {
        foreach ($keys as $key) {
            if (filled($request->input($key))) {
                return $request->input($key);
            }
        }

        return null;
    }

    private function timestamp(mixed $value): CarbonImmutable
    {
        if (is_numeric($value)) {
            $seconds = (int) $value > 9_999_999_999 ? intdiv((int) $value, 1000) : (int) $value;
            $time = CarbonImmutable::createFromTimestamp($seconds, config('app.timezone'));

            return $time->isFuture() || $time->lt(now()->subDays(30)) ? CarbonImmutable::now() : $time;
        }

        return CarbonImmutable::now();
    }
}
