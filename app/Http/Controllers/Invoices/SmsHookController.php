<?php

namespace App\Http\Controllers\Invoices;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\InvoicePayment;
use App\Models\PaymentSms;
use App\Models\Setting;
use App\Notifications\AgencyAlert;
use App\Payments\PaymentMatcher;
use App\Payments\PaySettings;
use App\Payments\SmsParser;
use App\Services\CardEvents;
use App\Services\Invoices;
use App\Services\Notifier;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Spatie\Activitylog\Models\Activity;

/**
 * Receives SMS forwarded by the Android phone holding the agency's wallet
 * SIM. Accepts the field names used by common SMS-forwarder apps
 * (from/sender/address, text/message/body); an empty body is a heartbeat.
 *
 * Only money SMS are kept, and only to pay invoices: one no invoice takes
 * sends no notification and is deleted after a day (App\Services\Ticker),
 * long enough for a TrxID the client types in a little later.
 *
 * A sender name is easy to forge, so an SMS must also come through a service centre seen
 * before for that sender, or show the balance the last one did plus this money; one that does
 * neither is held for the Owner and pays nothing by itself.
 */
class SmsHookController extends Controller
{
    public const CHECK_TITLE = 'পেমেন্ট এসেছে, কিন্তু নম্বর মেলেনি — মিলিয়ে দিন'; // i18n

    /** How long a money SMS no invoice took stays on the server. */
    public const KEEP_UNMATCHED_HOURS = 24;

    public const REFUND_CHECK_TITLE = 'Wallet থেকে client-এর নম্বরে টাকা গেছে — ফেরত কি?'; // i18n

    public const HELD_TITLE = 'পেমেন্ট SMS আসল কিনা যাচাই করুন'; // i18n

    public function __invoke(Request $request, string $token, SmsParser $parser, PaymentMatcher $matcher, Invoices $invoices): JsonResponse
    {
        abort_unless($this->tokenOk($token), 404);

        Setting::putQuietly('sms_last_seen', now()->toIso8601String());

        $sender = (string) $this->first($request, ['from', 'sender', 'address', 'phone', 'number']);
        $body = trim((string) $this->first($request, ['text', 'message', 'body', 'content', 'msg', 'sms']));

        if ($body === '') {
            return response()->json(['status' => 'heartbeat']);
        }

        // NSave's app notification (the same phone sends it): a move on one of our cards.
        if (strcasecmp(trim($sender), 'NSave') === 0) {
            $event = app(CardEvents::class)->take($body, $this->timestamp($this->first($request, ['receivedStamp', 'timestamp'])), $request->string('package')->toString() ?: null);

            return response()->json(['status' => 'card_'.$event->status]);
        }

        $parsed = $parser->parse($sender, $body);
        $sent = $parsed === null ? $parser->parseSent($sender, $body) : null;
        $receivedAt = $this->timestamp($this->first($request, ['receivedStamp', 'sentStamp', 'timestamp', 'received_at', 'date']));

        // The service centre (SMSC) that delivered it, sent by app builds that know it; "" is none.
        $centre = filled($request->input('serviceCentre')) ? mb_substr(PaySettings::sender((string) $request->input('serviceCentre')), 0, 40) : null;
        $balance = $parser->balance($body);

        // A payment sender's SMS — any of them, OTPs and offers too — is checked against the
        // centre and balance its earlier ones showed; a forged one is held for the Owner.
        $change = match (true) {
            $parsed !== null => [$parsed['amount'] - $parser->fee($body), $parsed['amount']],
            $sent !== null => [-($sent['amount'] + $sent['fee']), -$sent['amount']],
            default => null,
        };
        $genuine = $parser->providerFor($sender) !== null && $matcher->genuine($sender, $centre, $balance, $change, $receivedAt);

        // Money the wallet sent back to a number that paid: that payment's refund.
        if ($sent !== null) {
            $refunded = $this->refund($sent, $sender, $body, $receivedAt, $invoices, $genuine);

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
                'service_centre' => $centre,
                'balance' => $balance,
                'status' => 'unmatched',
                'held' => ! $genuine,
                'received_at' => $receivedAt,
            ],
        );

        $matched = $sms->wasRecentlyCreated && $matcher->forSms($sms);

        if ($sms->wasRecentlyCreated && $sms->held) {
            // Not like this sender's earlier SMS: it pays nothing until the Owner checks the wallet.
            app(Notifier::class)->send(null, new AgencyAlert('sms_check', self::HELD_TITLE, '{amount} · {sender} · TrxID {trx} — অচেনা পথে এসেছে; wallet-এ টাকা দেখে তবেই মেলান', [ // i18n
                'amount' => Money::bdt((int) $sms->amount), 'sender' => $sms->sender, 'trx' => $sms->trx_id ?? '—',
            ], ['url' => '/invoices', 'important' => true]));
        } elseif ($sms->wasRecentlyCreated && ! $matched && ($claim = $matcher->claimFor($sms)) !== null && ! $matcher->claimOk($claim, $sms)) {
            // A client typed this TrxID, but the money came from someone else's number (or the claim
            // asked for far less): the Owner decides whose it is.
            app(Notifier::class)->send(null, new AgencyAlert('sms_check', self::CHECK_TITLE, '{amount} · {from} · Invoice {number} — এই TrxID দাবি করা হয়েছে, কিন্তু নম্বর বা টাকা মেলেনি', [ // i18n
                'amount' => Money::bdt((int) $sms->amount), 'from' => $sms->payer_number ?? '—', 'number' => $claim->invoice->number,
            ], ['url' => '/invoices', 'important' => true]));
        } elseif ($sms->wasRecentlyCreated && ! $matched && ($near = $matcher->nearMatch($sms)) !== null) {
            // Not taken, but an open payment waits for exactly this amount (the client gave another
            // number than the one they paid from): the Owner checks it and matches it by hand.
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
    private function refund(array $sent, string $sender, string $body, CarbonImmutable $at, Invoices $invoices, bool $genuine): ?string
    {
        if (PaymentSms::where('provider', $sent['provider'])->where('trx_id', $sent['trx_id'])->exists()) {
            return null;
        }

        $to = substr(preg_replace('/\D+/', '', $sent['to']) ?? '', -10);
        $payment = InvoicePayment::with('invoice:id,number,uid,client_id')
            ->where('status', 'verified')
            ->where('method', $sent['provider'])
            ->where('verified_at', '>=', now()->subDays(30))
            ->where(fn ($q) => $q->where('amount', $sent['amount'])->orWhereRaw('amount - charge = ?', [$sent['amount']]))
            ->latest('verified_at')
            ->get()
            ->first(fn (InvoicePayment $p) => substr(preg_replace('/\D+/', '', (string) $p->sender) ?? '', -10) === $to);

        if ($payment === null) {
            return null;
        }

        // Only a refund the client asked for (the portal's request, within 30 days), in an SMS that
        // looks genuine, is booked by itself; any other Send Money to a payer's number is just told
        // to the Owner.
        $asked = $genuine && Activity::where('description', 'refund asked')
            ->where('subject_type', (new Client)->getMorphClass())->where('subject_id', $payment->invoice->client_id)
            ->where('created_at', '>=', now()->subDays(30))->exists();

        if (! $asked) {
            app(Notifier::class)->send(null, new AgencyAlert('refund_check', self::REFUND_CHECK_TITLE, '{amount} → {to} · Invoice {number} — ফেরত হলে payment-এ "Refund" দিন', [ // i18n
                'amount' => Money::bdt($sent['amount']), 'to' => $sent['to'], 'number' => $payment->invoice->number,
            ], ['url' => '/invoices/'.$payment->invoice->uid]));

            return null;
        }

        if (! $invoices->refund($payment, $sent['fee'], $at->toDateString(), $sent['trx_id'])) {
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

    /**
     * The phone's token. bcrypt only the first time: a match then stores a cheap HMAC of it, tied
     * to that bcrypt hash, so later requests — and anyone guessing — cost one SHA-256 and a
     * constant-time compare. A new token has a new hash, so its first request uses bcrypt again.
     */
    private function tokenOk(string $token): bool
    {
        $hash = Setting::get('sms_device_token');

        if (! is_string($hash) || $hash === '' || strlen($token) > 200) {
            return false;
        }

        $for = hash('sha256', $hash);
        [$storedFor, $storedMac] = array_pad(explode(':', (string) Setting::get('sms_device_token_mac', ''), 2), 2, '');

        if (hash_equals($for, $storedFor)) {
            return hash_equals($storedMac, hash_hmac('sha256', $token, $hash));
        }

        if (! Hash::check($token, $hash)) {
            return false;
        }

        Setting::putQuietly('sms_device_token_mac', $for.':'.hash_hmac('sha256', $token, $hash));

        return true;
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
