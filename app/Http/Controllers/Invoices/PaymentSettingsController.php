<?php

namespace App\Http\Controllers\Invoices;

use App\Http\Controllers\Controller;
use App\Models\PaymentSms;
use App\Models\Setting;
use App\Payments\BanglaQr;
use App\Payments\PaySettings;
use App\Payments\SmsParser;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Settings → Payment: the agency's details on invoices, where clients send
 * money, and the SMS-forwarding phone that lets payments confirm themselves.
 * The forwarder's token is shown once; only its hash is kept.
 */
class PaymentSettingsController extends Controller
{
    private const NUMBER = 'regex:/^01[3-9]\d{8,9}$/';

    public function index(Request $request): Response
    {
        $values = [];

        foreach (PaySettings::TEXT_KEYS as $key) {
            $values[$key] = PaySettings::text($key);
        }

        foreach (PaySettings::SWITCHES as $key) {
            $values[$key] = PaySettings::on($key);
        }

        $values['qr_fee_percent'] = PaySettings::text('qr_fee_percent') ?: '1';
        $values['pay_business_name'] = PaySettings::text('pay_business_name') ?: 'Klok Ads';
        $values['commission_percent'] = PaySettings::text('commission_percent') ?: '20';

        return Inertia::render('admin/Payment', [
            'settings' => $values,
            'images' => [
                'logo' => Setting::get('pay_logo') ? route('pay.media', 'logo').'?v='.substr(md5((string) Setting::get('pay_logo')), 0, 8) : null,
                'qr' => Setting::get('pay_qr_image') ? route('pay.media', 'qr').'?v='.substr(md5((string) Setting::get('pay_qr_image')), 0, 8) : null,
            ],
            'forwarder' => [
                'connected' => PaySettings::autoVerify(),
                'last_seen' => Setting::get('sms_last_seen'),
                // Only right after it was made; never again.
                'url' => $request->session()->get('sms_hook_url'),
                'unmatched' => PaymentSms::where('status', 'unmatched')->count(),
            ],
            'options' => [
                'types' => collect(PaySettings::WALLET_TYPES)->map(fn ($label, $value) => ['value' => $value, 'label' => $label])->values()->all(),
            ],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'pay_business_name' => ['required', 'string', 'max:120'],
            'pay_business_address' => ['nullable', 'string', 'max:500'],
            'pay_business_phone' => ['nullable', 'string', 'max:40'],
            'pay_business_email' => ['nullable', 'email', 'max:255'],
            'pay_bkash_on' => ['boolean'],
            'pay_bkash_number' => ['nullable', 'required_if_accepted:pay_bkash_on', self::NUMBER],
            'pay_bkash_type' => ['nullable', Rule::in(array_keys(PaySettings::WALLET_TYPES))],
            'pay_nagad_on' => ['boolean'],
            'pay_nagad_number' => ['nullable', 'required_if_accepted:pay_nagad_on', self::NUMBER],
            'pay_nagad_type' => ['nullable', Rule::in(array_keys(PaySettings::WALLET_TYPES))],
            'pay_rocket_on' => ['boolean'],
            'pay_rocket_number' => ['nullable', 'required_if_accepted:pay_rocket_on', self::NUMBER],
            'pay_qr_on' => ['boolean'],
            'pay_qr_merchant' => ['nullable', 'string', 'max:120'],
            // The text inside the bank's QR (a QR reader shows it): checked before it is kept.
            'pay_qr_payload' => ['nullable', 'string', 'max:600', function (string $attribute, mixed $value, \Closure $fail): void {
                try {
                    BanglaQr::parse(trim((string) $value));
                } catch (\InvalidArgumentException $e) {
                    $fail(__('এটা ঠিক Bangla QR-এর লেখা নয়।').' '.$e->getMessage());
                }
            }],
            'pay_bank_on' => ['boolean'],
            'pay_bank_name' => ['nullable', 'required_if_accepted:pay_bank_on', 'string', 'max:120'],
            'pay_bank_branch' => ['nullable', 'string', 'max:120'],
            'pay_bank_account_name' => ['nullable', 'required_if_accepted:pay_bank_on', 'string', 'max:120'],
            'pay_bank_account_number' => ['nullable', 'required_if_accepted:pay_bank_on', 'string', 'max:40'],
            'pay_bank_routing' => ['nullable', 'string', 'max:20'],
            'pay_bank_sms_senders' => ['nullable', 'string', 'max:200'],
            'qr_fee_percent' => ['required', 'numeric', 'min:0', 'max:10'],
            'commission_percent' => ['required', 'numeric', 'min:0', 'max:200'],
            'logo' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:1024'],
            'qr' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:2048'],
        ], [
            '*.regex' => __('১১ সংখ্যার নম্বর দিন, যেমন 01712345678।'),
            '*.required_if_accepted' => __('চালু করলে এটা লাগবে।'),
        ]);

        foreach (PaySettings::TEXT_KEYS as $key) {
            $value = isset($data[$key]) ? trim((string) $data[$key]) : '';

            if ($value !== PaySettings::text($key)) {
                Setting::put($key, $value !== '' ? $value : null);
            }
        }

        foreach (PaySettings::SWITCHES as $key) {
            $value = ! empty($data[$key]) ? '1' : '0';

            if ($value !== (string) Setting::get($key, '0')) {
                Setting::put($key, $value);
            }
        }

        foreach (['logo' => 'pay_logo', 'qr' => 'pay_qr_image'] as $field => $key) {
            if ($request->hasFile($field)) {
                $this->replaceImage($key, (string) $request->file($field)?->store('payments', 'public'));
            }
        }

        if (($request->hasFile('qr') || filled($data['pay_qr_payload'] ?? null)) && ! PaySettings::on('pay_qr_on')) {
            Setting::put('pay_qr_on', '1');
        }

        $this->toast(__('Payment-এর তথ্য সংরক্ষণ হয়েছে।'));

        return back();
    }

    public function removeImage(string $kind): RedirectResponse
    {
        abort_unless(in_array($kind, ['logo', 'qr'], true), 404);

        $this->replaceImage($kind === 'logo' ? 'pay_logo' : 'pay_qr_image', null);
        $this->toast(__('ছবি সরানো হয়েছে।'));

        return back();
    }

    /**
     * What the system would read from one SMS, without keeping it: is the
     * sender trusted, is it money coming in, how much, which reference.
     */
    public function smsTest(Request $request, SmsParser $parser): JsonResponse
    {
        $data = $request->validate([
            'from' => ['required', 'string', 'max:60'],
            'text' => ['required', 'string', 'max:2000'],
        ]);

        $provider = $parser->providerFor($data['from']);
        $parsed = $parser->parse($data['from'], $data['text']);

        return response()->json([
            'trusted' => $provider !== null,
            'provider' => $provider,
            'incoming' => $parsed !== null,
            'amount' => $parsed ? Money::bdt($parsed['amount']) : null,
            'trx_id' => $parsed['trx_id'] ?? null,
            'made_up_id' => $parsed !== null && str_starts_with($parsed['trx_id'], 'SMS'),
            'payer' => $parsed['payer_number'] ?? null,
        ]);
    }

    /** A fresh forwarder URL; the old one stops working at once. */
    public function token(Request $request): RedirectResponse
    {
        $token = Str::random(40);
        Setting::put('sms_device_token', Hash::make($token));
        Setting::putQuietly('sms_last_seen', null);
        activity('Setting')->causedBy($request->user())->log('sms forwarder token created');

        $this->toast(__('নতুন forwarder URL তৈরি — এখনই phone-এ বসান, এটা আর দেখানো হবে না।'));

        return back()->with('sms_hook_url', route('hooks.sms', $token));
    }

    public function disconnect(Request $request): RedirectResponse
    {
        Setting::put('sms_device_token', null);
        activity('Setting')->causedBy($request->user())->log('sms forwarder turned off');
        $this->toast(__('SMS forwarder বন্ধ করা হয়েছে।'));

        return back();
    }

    /** The logo and Bangla QR, served from the public disk (no storage link needed). */
    public function media(string $kind): StreamedResponse
    {
        $path = Setting::get(match ($kind) {
            'logo' => 'pay_logo',
            'qr' => 'pay_qr_image',
            default => abort(404),
        });

        abort_unless(is_string($path) && $path !== '' && Storage::disk('public')->exists($path), 404);

        return Storage::disk('public')->response($path, null, ['Cache-Control' => 'public, max-age=3600']);
    }

    private function replaceImage(string $key, ?string $path): void
    {
        $old = Setting::get($key);
        Setting::put($key, $path);

        if (is_string($old) && $old !== '' && $old !== $path) {
            Storage::disk('public')->delete($old);
        }
    }
}
