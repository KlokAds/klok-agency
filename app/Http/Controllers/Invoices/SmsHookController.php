<?php

namespace App\Http\Controllers\Invoices;

use App\Http\Controllers\Controller;
use App\Models\PaymentSms;
use App\Models\Setting;
use App\Payments\PaymentMatcher;
use App\Payments\SmsParser;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/**
 * Receives SMS forwarded by the Android phone holding the agency's wallet
 * SIM. Accepts the field names used by common SMS-forwarder apps
 * (from/sender/address, text/message/body); an empty body is a heartbeat.
 */
class SmsHookController extends Controller
{
    public function __invoke(Request $request, string $token, SmsParser $parser, PaymentMatcher $matcher): JsonResponse
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

        if ($parsed === null) {
            PaymentSms::create([
                'provider' => $parser->providerFor($sender) ?? 'unknown',
                'sender' => mb_substr($sender ?: '?', 0, 60),
                'body' => mb_substr($body, 0, 2000),
                'status' => 'ignored',
                'received_at' => $receivedAt,
            ]);

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

        return response()->json(['status' => $matched ? 'matched' : ($sms->wasRecentlyCreated ? 'stored' : 'duplicate')]);
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
