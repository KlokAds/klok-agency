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

    protected $fillable = ['name', 'type', 'status', 'phone', 'email', 'company', 'notes'];

    public function usdEntries(): HasMany
    {
        return $this->hasMany(UsdEntry::class);
    }

    public function bdtEntries(): HasMany
    {
        return $this->hasMany(BdtEntry::class);
    }
}
