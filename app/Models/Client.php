<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Client extends Model
{
    use Audited, SoftDeletes;

    /** Every one is a client (the old "associate" kind was only a label and is gone). */
    public const TYPES = ['client' => 'Client'];

    public const STATUSES = ['active' => 'Active', 'inactive' => 'Inactive'];

    /** How their dollar is priced: the FX batch plus a commission, or a fixed taka rate. */
    public const RATE_MODES = ['commission' => 'Commission', 'fixed' => 'Fixed rate'];

    protected $fillable = ['name', 'type', 'status', 'manager_id', 'renew_days', 'rate_mode', 'fixed_rate', 'commission_percent', 'phone', 'email', 'company', 'notes'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['fixed_rate' => 'float', 'commission_percent' => 'float'];
    }

    /** Their fixed taka per dollar, when they are priced that way. */
    public function fixedRate(): ?float
    {
        return $this->rate_mode === 'fixed' && $this->fixed_rate > 0 ? (float) $this->fixed_rate : null;
    }

    /** @return HasMany<PilotAccount, $this> */
    public function pilotAccounts(): HasMany
    {
        return $this->hasMany(PilotAccount::class);
    }

    /** @return HasMany<UsdEntry, $this> */
    public function usdEntries(): HasMany
    {
        return $this->hasMany(UsdEntry::class);
    }

    /** @return HasMany<Invoice, $this> */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    /** @return HasMany<BdtEntry, $this> */
    public function bdtEntries(): HasMany
    {
        return $this->hasMany(BdtEntry::class);
    }
}
