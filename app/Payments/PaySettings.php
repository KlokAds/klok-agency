<?php

namespace App\Payments;

use App\Models\Setting;

/**
 * Settings → Payment: the agency's details printed on invoices and where
 * clients send money. A method is offered only when it is switched on and
 * its details (number, QR image, account) are filled in.
 */
class PaySettings
{
    public const TEXT_KEYS = [
        'pay_business_name', 'pay_business_address', 'pay_business_phone', 'pay_business_email',
        'pay_bkash_number', 'pay_bkash_type', 'pay_nagad_number', 'pay_nagad_type', 'pay_rocket_number',
        'pay_qr_merchant', 'pay_qr_payload', 'pay_bank_name', 'pay_bank_branch', 'pay_bank_account_name', 'pay_bank_account_number',
        'pay_bank_routing', 'pay_bank_sms_senders', 'qr_fee_percent', 'commission_percent',
    ];

    public const SWITCHES = ['pay_bkash_on', 'pay_nagad_on', 'pay_rocket_on', 'pay_qr_on', 'pay_bank_on'];

    public const WALLET_TYPES = ['personal' => 'Personal', 'agent' => 'Agent', 'merchant' => 'Merchant'];

    public static function text(string $key, string $default = ''): string
    {
        return trim((string) (Setting::get($key) ?? $default));
    }

    public static function on(string $key): bool
    {
        return Setting::get($key) === '1';
    }

    public static function qrFeePercent(): float
    {
        return (float) (Setting::get('qr_fee_percent') ?? '1');
    }

    /** @return array{name: string, address: string, phone: string, email: string, logo: string|null} */
    public static function business(): array
    {
        return [
            'name' => self::text('pay_business_name', 'Klok Ads') ?: 'Klok Ads',
            'address' => self::text('pay_business_address'),
            'phone' => self::text('pay_business_phone'),
            'email' => self::text('pay_business_email'),
            'logo' => Setting::get('pay_logo') ? route('pay.media', 'logo') : null,
        ];
    }

    /**
     * What the client sees for each method that is ready to use.
     *
     * @return array<string, array<string, string|bool|null>>
     */
    public static function methods(): array
    {
        $methods = [];

        foreach ([PaymentMethod::Bkash, PaymentMethod::Nagad, PaymentMethod::Rocket] as $wallet) {
            $number = self::text("pay_{$wallet->value}_number");

            if (self::on("pay_{$wallet->value}_on") && $number !== '') {
                $methods[$wallet->value] = [
                    'label' => $wallet->label(),
                    'number' => $number,
                    'type' => $wallet === PaymentMethod::Rocket ? 'personal' : (self::text("pay_{$wallet->value}_type") ?: 'personal'),
                ];
            }
        }

        // With the QR's own text the page draws a QR per payment, amount filled in and locked;
        // with only a picture the payer types the amount.
        $qr = self::banglaQr();
        if (self::on('pay_qr_on') && ($qr !== null || Setting::get('pay_qr_image'))) {
            $methods['qr'] = [
                'label' => PaymentMethod::BanglaQr->label(),
                'image' => $qr === null ? route('pay.media', 'qr') : null,
                'dynamic' => $qr !== null,
                'merchant' => self::text('pay_qr_merchant') ?: ($qr?->merchant() ?? ''),
            ];
        }

        if (self::on('pay_bank_on') && self::text('pay_bank_account_number') !== '') {
            $methods['bank'] = [
                'label' => PaymentMethod::Bank->label(),
                'bank' => self::text('pay_bank_name'),
                'branch' => self::text('pay_bank_branch'),
                'account_name' => self::text('pay_bank_account_name'),
                'account_number' => self::text('pay_bank_account_number'),
                'routing' => self::text('pay_bank_routing'),
            ];
        }

        return $methods;
    }

    /** Whether the wallet phone is set up to confirm payments by itself. */
    public static function autoVerify(): bool
    {
        return filled(Setting::get('sms_device_token'));
    }

    /** The agency's Bangla QR from its text in Settings, or null when there is none (or it is broken). */
    public static function banglaQr(): ?BanglaQr
    {
        $payload = self::text('pay_qr_payload');

        try {
            return $payload !== '' ? new BanglaQr($payload) : null;
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    /** @return list<string> */
    public static function bankSenders(): array
    {
        $list = preg_split('/[,\n]+/', strtolower(self::text('pay_bank_sms_senders'))) ?: [];

        return array_values(array_unique(array_filter(array_map(fn (string $s) => self::sender($s), $list))));
    }

    /**
     * One way of writing a sender: no spaces, lower case, and a phone number
     * as 01XXXXXXXXX whether it came as +8801…, 8801… or 01844-242613 (some
     * banks, Islami Bank among them, send their alerts from an ordinary number).
     */
    public static function sender(string $sender): string
    {
        $plain = strtolower(preg_replace('/\s+/', '', $sender) ?? '');

        if (preg_match('/^\+?[\d-]{5,}$/', $plain)) {
            $digits = preg_replace('/\D/', '', $plain) ?? '';

            return str_starts_with($digits, '880') ? substr($digits, 2) : $digits;
        }

        return $plain;
    }

    /**
     * The same senders in every form a phone may show them, for the phone
     * app's own filter (it compares them as they come: +8801…, 01…).
     *
     * @return list<string>
     */
    public static function senderForms(): array
    {
        $forms = [];
        foreach ([...array_merge(...array_values(SmsParser::SENDERS)), ...self::bankSenders()] as $sender) {
            $forms[] = $sender;
            if (preg_match('/^0\d{10}$/', $sender)) {
                $forms[] = '+88'.$sender;
                $forms[] = '88'.$sender;
            }
        }

        return array_values(array_unique($forms));
    }
}
