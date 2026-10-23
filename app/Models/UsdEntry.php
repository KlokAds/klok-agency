<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use App\Models\Concerns\LedgerEntry;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One movement of USD. amount is signed cents.
 *
 * client_spend and business_spend use up USD (FIFO from the batches);
 * card_charge is the platform actually taking money off the card.
 *
 * @property CarbonImmutable|null $entry_date
 * @property int $amount
 * @property int $sequence
 * @property string|null $source pilot when the row comes from campaign spend
 * @property string|null $source_key
 */
class UsdEntry extends Model
{
    use Audited, LedgerEntry, SoftDeletes;

    public const CATEGORIES = [
        'deposit' => ['label' => 'USD কেনা (Deposit)', 'sign' => 1], // i18n
        'client_spend' => ['label' => 'Client-এর ad খরচ', 'sign' => -1], // i18n
        'business_spend' => ['label' => 'নিজের খরচ (KlokAds)', 'sign' => -1], // i18n
        'card_charge' => ['label' => 'Card থেকে কাটা', 'sign' => -1], // i18n
        'transfer' => ['label' => 'Transfer', 'sign' => 0], // i18n
        'adjustment' => ['label' => 'Adjustment', 'sign' => 0], // i18n
    ];

    /** Categories that consume USD from the FIFO pool. */
    public const CONSUMING = ['client_spend', 'business_spend'];

    public const STATUSES = ['pending' => 'Pending', 'settled' => 'Settled', 'na' => '—'];

    protected $fillable = [
        'entry_date', 'sequence', 'description', 'category', 'account_id', 'client_id', 'amount',
        'status', 'fx_batch_id', 'settled_by_id', 'pair_key', 'notes', 'review_note', 'created_by', 'updated_by', 'allocations',
    ];

    protected function casts(): array
    {
        return ['entry_date' => 'date', 'amount' => 'integer', 'sequence' => 'integer', 'allocations' => 'array'];
    }

    /** @return BelongsTo<Account, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /** @return BelongsTo<Client, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /** @return BelongsTo<FxBatch, $this> */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(FxBatch::class, 'fx_batch_id');
    }

    /** @return BelongsTo<BdtEntry, $this> */
    public function settledBy(): BelongsTo
    {
        return $this->belongsTo(BdtEntry::class, 'settled_by_id');
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
