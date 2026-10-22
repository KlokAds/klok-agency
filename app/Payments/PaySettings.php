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
        'pay_qr_merchant', 'pay_bank_name', 'pay_bank_branch', 'pay_bank_account_name', 'pay_bank_account_number',
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
     * @return array<string, array<string, string|null>>
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

        if (self::on('pay_qr_on') && Setting::get('pay_qr_image')) {
            $methods['qr'] = ['label' => PaymentMethod::BanglaQr->label(), 'image' => route('pay.media', 'qr'), 'merchant' => self::text('pay_qr_merchant')];
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

    /** @return list<string> */
    public static function bankSenders(): array
    {
        $list = preg_split('/[,\n]+/', strtolower(self::text('pay_bank_sms_senders'))) ?: [];

        return array_values(array_filter(array_map(fn (string $s) => preg_replace('/\s+/', '', $s) ?? '', $list)));
    }
}
