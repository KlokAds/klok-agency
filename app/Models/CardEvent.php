<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One NSave app notification the phone forwarded: what it says (kind, amount, shop, card)
 * and what the books did with it (booked, a duplicate of a charge already there, held for
 * the Owner, or ignored as not money).
 *
 * @property int|null $amount
 * @property string $kind
 * @property string $status
 * @property string|null $currency
 * @property string|null $merchant
 * @property string|null $last4
 * @property string|null $problem
 * @property string $body
 * @property Carbon|null $posted_at
 */
class CardEvent extends Model
{
    public const KINDS = ['charge', 'refund', 'topup', 'declined', 'other'];

    protected $fillable = [
        'source', 'package', 'body', 'hash', 'posted_at', 'kind', 'amount', 'currency', 'merchant',
        'last4', 'status', 'problem', 'card_id', 'usd_entry_id',
    ];

    protected function casts(): array
    {
        return ['posted_at' => 'datetime', 'amount' => 'integer'];
    }

    /** @return BelongsTo<Account, $this> */
    public function card(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'card_id');
    }

    /** @return BelongsTo<UsdEntry, $this> */
    public function entry(): BelongsTo
    {
        return $this->belongsTo(UsdEntry::class, 'usd_entry_id');
    }
}
