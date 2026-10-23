<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One Meta payment receipt from the receipts mailbox.
 *
 * @property int $id
 * @property string $message_id
 * @property string|null $transaction_id
 * @property string|null $account_ref Meta's ad account id on the receipt
 * @property int|null $pilot_account_id
 * @property int|null $amount cents
 * @property string|null $currency
 * @property CarbonImmutable|null $charged_at
 * @property string|null $card_last4
 * @property array<int, array{name: string, cents: int, campaign_id: ?int}>|null $campaigns
 * @property string|null $subject
 * @property string|null $body
 * @property int|null $usd_entry_id
 * @property string $status booked|duplicate|unread|ignored
 * @property string|null $problem
 */
class MetaReceipt extends Model
{
    public const STATUSES = [
        'booked' => 'খাতায় উঠেছে', // i18n
        'duplicate' => 'আগেই উঠেছিল', // i18n
        'unread' => 'পড়া যায়নি', // i18n
        'ignored' => 'Receipt নয়', // i18n
    ];

    protected $fillable = [
        'message_id', 'transaction_id', 'account_ref', 'pilot_account_id', 'amount', 'currency', 'charged_at', 'card_last4',
        'campaigns', 'subject', 'body', 'usd_entry_id', 'status', 'problem',
    ];

    protected function casts(): array
    {
        return ['amount' => 'integer', 'charged_at' => 'datetime', 'campaigns' => 'array'];
    }

    /** @return BelongsTo<UsdEntry, $this> */
    public function entry(): BelongsTo
    {
        return $this->belongsTo(UsdEntry::class, 'usd_entry_id');
    }

    /** @return BelongsTo<PilotAccount, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(PilotAccount::class, 'pilot_account_id');
    }
}
