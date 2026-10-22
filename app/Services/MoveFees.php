<?php

namespace App\Services;

use App\Models\BdtEntry;
use App\Models\ExpenseHead;
use App\Models\Setting;
use App\Models\Wallet;

/**
 * What moving BDT costs (Settings → খাতার নিয়ম): cashing out of MFS takes a
 * share of the amount (1% by default), a bank transfer a fixed fee on top
 * (৳10 by default). The fee is a "Bank charge" expense paid from the place
 * the money left.
 */
class MoveFees
{
    public static function mfsPercent(): float
    {
        return (float) Setting::get('mfs_out_percent', '1');
    }

    /** Poisha per bank transfer. */
    public static function bankFee(): int
    {
        return (int) round((float) Setting::get('bank_transfer_fee', '10') * 100);
    }

    /**
     * The usual fee for moving $amount from one place to another, and
     * whether it comes out of the amount (MFS) or on top of it (bank).
     *
     * @return array{fee: int, inside: bool}
     */
    public static function forMove(string $from, int $amount): array
    {
        return match (Wallet::typeOf($from)) {
            'mfs' => ['fee' => (int) round($amount * self::mfsPercent() / 100), 'inside' => true],
            'bank' => ['fee' => self::bankFee(), 'inside' => false],
            default => ['fee' => 0, 'inside' => false],
        };
    }

    /** Books a fee as a Bank charge expense, paid from $wallet. */
    public static function book(int $fee, string $wallet, string $date, string $description): ?BdtEntry
    {
        if ($fee <= 0) {
            return null;
        }

        return BdtEntry::create([
            'entry_date' => $date,
            'description' => $description,
            'category' => 'expense',
            'expense_head_id' => ExpenseHead::firstOrCreate(['name' => 'Bank charge'])->id,
            'wallet' => $wallet,
            'amount' => -$fee,
        ]);
    }
}
