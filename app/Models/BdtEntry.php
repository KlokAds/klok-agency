<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use App\Models\Concerns\LedgerEntry;
use App\Services\CampaignBilling;
use App\Services\PaymentFees;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One movement of BDT. amount is signed poisha.
 *
 * @property CarbonImmutable|null $entry_date
 * @property int $amount
 * @property int $sequence
 * @property string|null $pay_via mfs|qr|bank|cash
 * @property string|null $wallet bank|bkash|cash
 * @property int|null $usd_credit cents of ad credit a client's advance payment bought
 */
class BdtEntry extends Model
{
    use Audited, LedgerEntry, SoftDeletes;

    public const CATEGORIES = [
        'capital' => ['label' => 'Capital জমা', 'sign' => 1, 'group' => 'owner'], // i18n
        'owner_drawing' => ['label' => 'Owner উত্তোলন', 'sign' => -1, 'group' => 'owner'], // i18n
        'owner_loan' => ['label' => 'Owner নিল (ফেরত দেবে)', 'sign' => -1, 'group' => 'owner'], // i18n
        'owner_repay' => ['label' => 'Owner ফেরত দিল', 'sign' => 1, 'group' => 'owner'], // i18n
        'usd_purchase' => ['label' => 'USD কেনা', 'sign' => -1, 'group' => 'fx'], // i18n
        'client_payment' => ['label' => 'Client পেমেন্ট', 'sign' => 1, 'group' => 'revenue'], // i18n
        'service_income' => ['label' => 'Service আয়', 'sign' => 1, 'group' => 'revenue'], // i18n
        'other_income' => ['label' => 'অন্যান্য আয়', 'sign' => 1, 'group' => 'revenue'], // i18n
        // Unspent advance given back: the money and the client's dollars both come down.
        'client_refund' => ['label' => 'Client-কে ফেরত', 'sign' => -1, 'group' => 'revenue'], // i18n
        'expense' => ['label' => 'ব্যবসার খরচ', 'sign' => -1, 'group' => 'expense'], // i18n
        'salary' => ['label' => 'বেতন', 'sign' => -1, 'group' => 'expense'], // i18n
    ];

    public const SALARY_KINDS = ['salary' => 'বেতন', 'advance' => 'Advance', 'bonus' => 'Bonus']; // i18n

    public const REVENUE = ['client_payment', 'service_income', 'other_income', 'client_refund'];

    public const EXPENSE = ['expense', 'salary'];

    /** The places a new install starts with; the owner's list lives in the wallets table (App\Models\Wallet). */
    public const WALLETS = ['bank' => 'Bank', 'bkash' => 'bKash', 'cash' => 'Cash'];

    /** How a client's money came in; only MFS carries the payment fee (Bangla QR's fee is booked with its invoice payment). */
    public const PAY_VIA = ['mfs' => 'MFS (bKash / Nagad)', 'qr' => 'Bangla QR', 'bank' => 'Bank', 'cash' => 'Cash'];

    protected $fillable = [
        'entry_date', 'sequence', 'description', 'category', 'client_id', 'pay_via', 'wallet', 'expense_head_id', 'employee_id',
        'salary_month', 'salary_kind', 'fx_batch_id', 'fee_for_id', 'amount', 'usd_credit', 'notes', 'review_note', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return ['entry_date' => 'date', 'amount' => 'integer', 'sequence' => 'integer'];
    }

    protected static function booted(): void
    {
        // A client payment carries its transfer fee row along with it.
        static::saved(fn (BdtEntry $entry) => PaymentFees::sync($entry));
        static::deleted(fn (BdtEntry $entry) => PaymentFees::sync($entry));
        static::restored(fn (BdtEntry $entry) => PaymentFees::sync($entry));

        // An advance payment's credit funds the client's campaign spend.
        foreach (['saved', 'deleted', 'restored'] as $event) {
            static::$event(function (BdtEntry $entry) {
                if ($entry->client_id && ($entry->usd_credit || $entry->getOriginal('usd_credit'))) {
                    app(CampaignBilling::class)->fund($entry->client_id);
                }
            });
        }
    }

    /**
     * The fee row taken off this payment.
     *
     * @return HasOne<BdtEntry, $this>
     */
    public function fee(): HasOne
    {
        return $this->hasOne(BdtEntry::class, 'fee_for_id');
    }

    /** @return BelongsTo<BdtEntry, $this> */
    public function feeFor(): BelongsTo
    {
        return $this->belongsTo(BdtEntry::class, 'fee_for_id');
    }

    /** @return BelongsTo<Client, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /** @return BelongsTo<ExpenseHead, $this> */
    public function head(): BelongsTo
    {
        return $this->belongsTo(ExpenseHead::class, 'expense_head_id');
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** @return BelongsTo<FxBatch, $this> */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(FxBatch::class, 'fx_batch_id');
    }

    /** @return HasMany<UsdEntry, $this> */
    public function settles(): HasMany
    {
        return $this->hasMany(UsdEntry::class, 'settled_by_id');
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
