<?php

namespace App\Support;

/**
 * Converts between what people type ("1,250.50") and integer minor units.
 */
class Money
{
    /** Validation rule for a positive amount with up to two decimals. */
    public const RULE = 'regex:/^\d{1,3}(,?\d{3})*(\.\d{1,2})?$|^\d+(\.\d{1,2})?$/';

    public static function toMinor(string|int|float|null $value): int
    {
        if ($value === null || $value === '') {
            return 0;
        }

        $clean = str_replace([',', ' ', "\u{09F3}", '$'], '', (string) $value);
        [$whole, $fraction] = array_pad(explode('.', $clean, 2), 2, '0');
        $fraction = substr(str_pad($fraction, 2, '0'), 0, 2);
        $negative = str_starts_with($whole, '-');
        $minor = abs((int) $whole) * 100 + (int) $fraction;

        return $negative ? -$minor : $minor;
    }

    public static function toInput(int $minor): string
    {
        return number_format(abs($minor) / 100, 2, '.', '');
    }
}
