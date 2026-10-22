<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Client extends Model
{
    use Audited, SoftDeletes;

    public const TYPES = ['client' => 'Client', 'associate' => 'Associate'];

    public const STATUSES = ['active' => 'Active', 'inactive' => 'Inactive'];

    protected $fillable = ['name', 'type', 'status', 'manager_id', 'pilot_ad_account_id', 'phone', 'email', 'company', 'notes'];

    /** @return HasMany<UsdEntry, $this> */
    public function usdEntries(): HasMany
    {
        return $this->hasMany(UsdEntry::class);
    }

    /** @return HasMany<BdtEntry, $this> */
    public function bdtEntries(): HasMany
    {
        return $this->hasMany(BdtEntry::class);
    }
}
