<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use App\Models\Concerns\LedgerEntry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One movement of BDT. amount is signed poisha.
 */
class BdtEntry extends Model
{
    use Audited, LedgerEntry, SoftDeletes;

    public const CATEGORIES = [
        'capital' => ['label' => 'Capital জমা', 'sign' => 1, 'group' => 'owner'],
        'owner_drawing' => ['label' => 'Owner উত্তোলন', 'sign' => -1, 'group' => 'owner'],
        'usd_purchase' => ['label' => 'USD কেনা', 'sign' => -1, 'group' => 'fx'],
        'client_payment' => ['label' => 'Client পেমেন্ট', 'sign' => 1, 'group' => 'revenue'],
        'service_income' => ['label' => 'Service আয়', 'sign' => 1, 'group' => 'revenue'],
        'other_income' => ['label' => 'অন্যান্য আয়', 'sign' => 1, 'group' => 'revenue'],
        'expense' => ['label' => 'ব্যবসার খরচ', 'sign' => -1, 'group' => 'expense'],
        'salary' => ['label' => 'বেতন', 'sign' => -1, 'group' => 'expense'],
    ];

    public const SALARY_KINDS = ['salary' => 'বেতন', 'advance' => 'Advance', 'bonus' => 'Bonus'];

    public const REVENUE = ['client_payment', 'service_income', 'other_income'];

    public const EXPENSE = ['expense', 'salary'];

    protected $fillable = [
        'entry_date', 'sequence', 'description', 'category', 'client_id', 'expense_head_id', 'employee_id',
        'salary_month', 'salary_kind', 'fx_batch_id', 'amount', 'notes', 'review_note', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return ['entry_date' => 'date', 'amount' => 'integer', 'sequence' => 'integer'];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function head(): BelongsTo
    {
        return $this->belongsTo(ExpenseHead::class, 'expense_head_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(FxBatch::class, 'fx_batch_id');
    }

    public function settles(): HasMany
    {
        return $this->hasMany(UsdEntry::class, 'settled_by_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
