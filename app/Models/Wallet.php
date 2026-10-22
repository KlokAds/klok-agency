<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * A place BDT sits in: a bank account, an MFS number (bKash, Nagad…), cash,
 * or money held aside. BDT rows and moves name it by its key. The type
 * decides the fee for moving money out of it.
 *
 * @property string $key
 * @property string $name
 * @property string $type bank|mfs|cash|hold
 * @property string|null $detail account or phone number
 * @property int $opening_balance poisha, as of the "counting from" day
 * @property bool $is_active
 */
class Wallet extends Model
{
    use Audited;

    public const TYPES = ['bank' => 'Bank account', 'mfs' => 'MFS (bKash / Nagad)', 'cash' => 'Cash', 'hold' => 'Hold (আটকে রাখা)']; // i18n

    protected $fillable = ['key', 'name', 'type', 'detail', 'opening_balance', 'is_active', 'sort'];

    /** @var Collection<int, Wallet>|null */
    private static ?Collection $all = null;

    protected function casts(): array
    {
        return ['opening_balance' => 'integer', 'is_active' => 'boolean', 'sort' => 'integer'];
    }

    protected static function booted(): void
    {
        static::saved(fn () => self::$all = null);
        static::deleted(fn () => self::$all = null);
    }

    /**
     * Every place, in the owner's order (cached for the request).
     *
     * @return Collection<int, Wallet>
     */
    public static function places(): Collection
    {
        return self::$all ??= Wallet::query()->orderBy('sort')->orderBy('id')->get();
    }

    /**
     * Keys a row may be given (active places only).
     *
     * @return array<int, string>
     */
    public static function keys(): array
    {
        return self::places()->where('is_active', true)->pluck('key')->values()->all();
    }

    public static function nameOf(?string $key): string
    {
        return self::places()->firstWhere('key', $key)->name ?? (string) $key;
    }

    public static function typeOf(?string $key): ?string
    {
        return self::places()->firstWhere('key', $key)?->type;
    }
}
