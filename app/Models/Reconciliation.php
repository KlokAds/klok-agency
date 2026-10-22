<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property CarbonImmutable $checked_on
 * @property int $app_balance
 * @property int $system_balance
 */
class Reconciliation extends Model
{
    use Audited;

    protected $fillable = ['checked_on', 'app_balance', 'system_balance', 'note', 'created_by'];

    protected function casts(): array
    {
        return ['checked_on' => 'date', 'app_balance' => 'integer', 'system_balance' => 'integer'];
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
