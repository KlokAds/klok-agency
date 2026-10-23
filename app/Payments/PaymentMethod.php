<?php

namespace App\Payments;

use App\Models\Setting;
use App\Models\Wallet;

/**
 * How a client paid an invoice, and where that money lands in the books:
 * wallets (bKash, Nagad, Rocket) sit in the bKash place, Bangla QR and bank
 * transfers in the bank, cash in hand.
 */
enum PaymentMethod: string
{
    case Bkash = 'bkash';
    case Nagad = 'nagad';
    case Rocket = 'rocket';
    case BanglaQr = 'qr';
    case Bank = 'bank';
    case Cash = 'cash';

    public function label(): string
    {
        return match ($this) {
            self::Bkash => 'bKash',
            self::Nagad => 'Nagad',
            self::Rocket => 'Rocket',
            self::BanglaQr => 'Bangla QR',
            self::Bank => 'Bank / NPSB',
            self::Cash => 'Cash',
        };
    }

    /** BdtEntry::PAY_VIA */
    public function payVia(): string
    {
        return match ($this) {
            self::Bkash, self::Nagad, self::Rocket => 'mfs',
            self::BanglaQr => 'qr',
            self::Bank => 'bank',
            self::Cash => 'cash',
        };
    }

    /**
     * The money place this way of paying lands in: as chosen in Settings ›
     * Payment (Bangla QR → the Islami Bank CellFin account, bank transfer →
     * UCB…), else the place of the same name, else the old shared place.
     */
    public function wallet(): string
    {
        $keys = Wallet::keys();
        $chosen = Setting::get("pay_wallet_{$this->value}");
        $own = match ($this) {
            self::Bkash => 'bkash',
            self::Nagad => 'nagad',
            self::Rocket => 'rocket',
            self::BanglaQr => 'ibbl',
            self::Bank => 'bank',
            self::Cash => 'cash',
        };
        $shared = match ($this) {
            self::Bkash, self::Nagad, self::Rocket => 'bkash',
            self::BanglaQr, self::Bank => 'bank',
            self::Cash => 'cash',
        };

        foreach ([$chosen, $own, $shared] as $key) {
            if (is_string($key) && in_array($key, $keys, true)) {
                return $key;
            }
        }

        return $shared;
    }

    /** Methods paid through a short session the wallet's SMS can confirm. */
    public function isSession(): bool
    {
        return in_array($this, [self::Bkash, self::Nagad, self::Rocket, self::BanglaQr], true);
    }

    /** @return array<string, string> */
    public static function labels(): array
    {
        $labels = [];

        foreach (self::cases() as $case) {
            $labels[$case->value] = $case->label();
        }

        return $labels;
    }
}
