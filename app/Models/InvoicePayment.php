<?php

namespace App\Models;

use App\Payments\PaymentMethod;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Money paid, or about to be paid, against an invoice. A wallet session is
 * pending with an exact amount and an expiry; a claim is pending with the
 * client's TrxID or bank reference; a manual entry is verified at once.
 *
 * @property int $id
 * @property int $invoice_id
 * @property PaymentMethod $method
 * @property int $amount poisha
 * @property string $status pending|verified|rejected|expired
 * @property string|null $reference
 * @property string|null $sender
 * @property CarbonImmutable|null $expires_at
 * @property CarbonImmutable|null $paid_on
 * @property string|null $wallet
 * @property int|null $usd_credit
 * @property int|null $bdt_entry_id
 * @property int|null $fee_entry_id
 * @property CarbonImmutable|null $verified_at
 * @property int|null $verified_by
 * @property string|null $note
 * @property CarbonImmutable $created_at
 */
class InvoicePayment extends Model
{
    public const STATUSES = [
        'pending' => 'যাচাই বাকি', // i18n
        'verified' => 'পাওয়া গেছে', // i18n
        'rejected' => 'বাতিল', // i18n
        'expired' => 'সময় শেষ', // i18n
    ];

    protected $fillable = [
        'invoice_id', 'method', 'amount', 'status', 'reference', 'sender', 'expires_at', 'paid_on', 'wallet', 'usd_credit',
        'bdt_entry_id', 'fee_entry_id', 'verified_at', 'verified_by', 'note',
    ];

    protected function casts(): array
    {
        return [
            'method' => PaymentMethod::class, 'amount' => 'integer', 'usd_credit' => 'integer',
            'expires_at' => 'datetime', 'paid_on' => 'date', 'verified_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Invoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /** @return BelongsTo<User, $this> */
    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    /** @return HasOne<PaymentSms, $this> */
    public function sms(): HasOne
    {
        return $this->hasOne(PaymentSms::class);
    }

    /** An open pay popup waiting for its SMS. */
    public function isOpenSession(): bool
    {
        return $this->status === 'pending' && $this->expires_at !== null && $this->expires_at->isFuture();
    }

    /** A TrxID or bank reference the client sent that still has to be confirmed. */
    public function isClaim(): bool
    {
        return $this->status === 'pending' && $this->expires_at === null;
    }
}
