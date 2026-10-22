<?php

namespace App\Models\Concerns;

use App\Models\Setting;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Shared behaviour of the USD and BDT ledgers: ledger order, a running
 * sequence for rows entered on the same day, and the book lock date.
 */
trait LedgerEntry
{
    public static function bootLedgerEntry(): void
    {
        static::creating(function ($entry) {
            if (! $entry->sequence) {
                $entry->sequence = (int) static::withTrashed()->max('sequence') + 1;
            }
        });

        foreach (['creating', 'updating', 'deleting', 'restoring'] as $event) {
            static::$event(function ($entry) {
                $entry->guardLockedPeriod();
            });
        }
    }

    /**
     * Undated rows (from the old sheet) first, then by date, then entry order.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeLedgerOrder(Builder $query): Builder
    {
        return $query->orderByRaw('entry_date is null desc')->orderBy('entry_date')->orderBy('sequence');
    }

    public static function categoryLabel(string $category): string
    {
        return isset(static::CATEGORIES[$category]) ? __(static::CATEGORIES[$category]['label']) : $category;
    }

    protected function guardLockedPeriod(): void
    {
        $lock = Setting::get('lock_date');

        // Artisan jobs (the sheet import) work below the lock; tests and web requests do not.
        if (! $lock || (app()->runningInConsole() && ! app()->runningUnitTests())) {
            return;
        }

        $dates = [$this->entry_date];

        if ($this->exists) {
            $dates[] = $this->getOriginal('entry_date');
        }

        foreach ($dates as $date) {
            if ($date === null || Carbon::parse($date)->lte($lock)) {
                throw ValidationException::withMessages([
                    'entry_date' => __('খাতা :date পর্যন্ত বন্ধ (locked) — এই সময়ের বা তারিখ ছাড়া entry বদলানো যাবে না।', ['date' => $lock]),
                ]);
            }
        }
    }
}
