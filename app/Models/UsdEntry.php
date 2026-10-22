<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use App\Models\Concerns\LedgerEntry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One movement of USD. amount is signed cents.
 *
 * client_spend and business_spend use up USD (FIFO from the batches);
 * card_charge is the platform actually taking money off the card.
 */
class UsdEntry extends Model
{
    use Audited, LedgerEntry, SoftDeletes;

    public const CATEGORIES = [
        'deposit' => ['label' => 'USD কেনা (Deposit)', 'sign' => 1],
        'client_spend' => ['label' => 'Client-এর ad খরচ', 'sign' => -1],
        'business_spend' => ['label' => 'নিজের খরচ (KlokAds)', 'sign' => -1],
        'card_charge' => ['label' => 'Card থেকে কাটা', 'sign' => -1],
        'transfer' => ['label' => 'Transfer', 'sign' => 0],
        'adjustment' => ['label' => 'Adjustment', 'sign' => 0],
    ];

    /** Categories that consume USD from the FIFO pool. */
    public const CONSUMING = ['client_spend', 'business_spend'];

    public const STATUSES = ['pending' => 'Pending', 'settled' => 'Settled', 'na' => '—'];

    protected $fillable = [
        'entry_date', 'sequence', 'description', 'category', 'account_id', 'client_id', 'amount',
        'status', 'fx_batch_id', 'settled_by_id', 'pair_key', 'notes', 'review_note', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return ['entry_date' => 'date', 'amount' => 'integer', 'sequence' => 'integer'];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(FxBatch::class, 'fx_batch_id');
    }

    public function settledBy(): BelongsTo
    {
        return $this->belongsTo(BdtEntry::class, 'settled_by_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
