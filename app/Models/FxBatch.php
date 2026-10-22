<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One purchase of USD. usd is in cents, bdt in poisha.
 */
class FxBatch extends Model
{
    use Audited, SoftDeletes;

    protected $fillable = ['number', 'purchased_on', 'description', 'usd', 'bdt', 'notes', 'created_by'];

    protected function casts(): array
    {
        return ['purchased_on' => 'date', 'usd' => 'integer', 'bdt' => 'integer'];
    }

    /** BDT per USD. */
    public function rate(): float
    {
        return $this->usd > 0 ? $this->bdt / $this->usd : 0.0;
    }

    public function usdEntry(): HasOne
    {
        return $this->hasOne(UsdEntry::class);
    }

    public function bdtEntry(): HasOne
    {
        return $this->hasOne(BdtEntry::class);
    }
}
