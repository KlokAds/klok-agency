<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Reconciliation extends Model
{
    use Audited;

    protected $fillable = ['checked_on', 'app_balance', 'system_balance', 'note', 'created_by'];

    protected function casts(): array
    {
        return ['checked_on' => 'date', 'app_balance' => 'integer', 'system_balance' => 'integer'];
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
