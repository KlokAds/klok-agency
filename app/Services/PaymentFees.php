<?php

namespace App\Services;

use App\Models\BdtEntry;
use App\Models\ExpenseHead;
use App\Models\Setting;

/**
 * Takes the MFS fee (1% by default, Settings → Payment fee) off every client
 * payment that came by bKash, Nagad or the like, as its own expense row, so
 * the BDT balance shows what really arrived and the P&L shows the fee. Bank
 * transfers and cash carry no fee.
 */
class PaymentFees
{
    /** The sheet import turns this off until it has checked its totals. */
    public static bool $paused = false;

    public static function percent(): float
    {
        return (float) Setting::get('payment_fee_percent', '1');
    }

    /** Payments dated before this day were written down as what arrived, so they carry no fee. */
    public static function from(): ?string
    {
        $from = Setting::get('payment_fee_from');

        return is_string($from) && $from !== '' ? $from : null;
    }

    public static function appliesTo(BdtEntry $entry): bool
    {
        $from = self::from();

        if ($from !== null && ($entry->entry_date === null || $entry->entry_date->toDateString() < $from)) {
            return false;
        }

        return in_array($entry->category, ['client_payment', 'service_income'], true)
            && $entry->client_id !== null
            && $entry->pay_via === 'mfs'
            && $entry->amount > 0
            && $entry->fee_for_id === null;
    }

    /** Creates, updates or removes the fee row that belongs to a payment. */
    public static function sync(BdtEntry $payment): void
    {
        if (self::$paused || $payment->fee_for_id !== null) {
            return;
        }

        $fee = BdtEntry::withTrashed()->where('fee_for_id', $payment->id)->first();
        $amount = (int) round($payment->amount * self::percent() / 100);

        if (! self::appliesTo($payment) || $amount <= 0 || $payment->trashed()) {
            if ($fee && ! $fee->trashed()) {
                $fee->delete();
            }

            return;
        }

        $values = [
            'entry_date' => $payment->entry_date,
            'description' => 'MFS fee '.self::label().' — '.$payment->description,
            'category' => 'expense',
            'client_id' => null,
            'wallet' => $payment->wallet,
            'expense_head_id' => ExpenseHead::firstOrCreate(['name' => 'Payment fee'])->id,
            'amount' => -$amount,
        ];

        if ($fee) {
            if ($fee->trashed()) {
                $fee->restore();
            }

            $fee->fill($values);

            if ($fee->isDirty()) {
                $fee->save();
            }

            return;
        }

        BdtEntry::create($values + [
            'fee_for_id' => $payment->id,
            // Right after its payment in the ledger.
            'sequence' => $payment->sequence,
        ]);
    }

    /**
     * Brings every client payment's fee row in line with the current rate
     * and how the money came in (adds, changes or removes it).
     */
    public static function backfill(): int
    {
        $count = 0;

        BdtEntry::whereIn('category', ['client_payment', 'service_income'])
            ->whereNotNull('client_id')
            ->whereNull('fee_for_id')
            ->orderBy('id')
            ->each(function (BdtEntry $payment) use (&$count) {
                self::sync($payment);
                $count++;
            });

        return $count;
    }

    public static function label(): string
    {
        return rtrim(rtrim(number_format(self::percent(), 2), '0'), '.').'%';
    }
}
