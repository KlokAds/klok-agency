<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A card or bank app on the forwarding phone (NSave, Fasset, PlasmaOne, …) whose notifications
 * showed a dollar move. Pending until the Owner approves it (as a new account, or joined to one
 * already in the books); off stops it, and on starts it again.
 *
 * @property string $package
 * @property string $name
 * @property string $status
 * @property Carbon|null $seen_at
 */
class MoneyApp extends Model
{
    public const STATUSES = ['pending', 'on', 'off'];

    protected $fillable = ['package', 'name', 'status', 'seen_at'];

    protected function casts(): array
    {
        return ['seen_at' => 'datetime'];
    }

    /** @return HasMany<Account, $this> */
    public function accounts(): HasMany
    {
        return $this->hasMany(Account::class, 'app_package', 'package');
    }

    /** @return HasMany<CardEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(CardEvent::class, 'package', 'package');
    }
}
