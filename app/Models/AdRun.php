<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One paid ad for a client: how much, from when to when, and how it did.
 * Ending dates drive the renew reminder; the result note drives scaling.
 *
 * @property CarbonImmutable $start_on
 * @property CarbonImmutable $end_on
 * @property CarbonImmutable|null $result_at
 * @property int $budget_usd
 * @property int|null $daily_usd
 * @property int|null $cost_per_result
 */
class AdRun extends Model
{
    use Audited, SoftDeletes;

    public const STATUSES = ['running' => 'চলছে', 'ended' => 'শেষ', 'renewed' => 'Renew হয়েছে', 'stopped' => 'বন্ধ করা']; // i18n

    public const RESULTS = ['good' => 'ভালো', 'ok' => 'মাঝারি', 'bad' => 'খারাপ']; // i18n

    protected $attributes = [
        'status' => 'running',
        'remind_days_before' => 1,
    ];

    protected $fillable = [
        'client_id', 'account_id', 'title', 'budget_usd', 'daily_usd', 'days', 'start_on', 'end_on', 'remind_days_before',
        'usd_entry_id', 'payment_id', 'renewed_from_id', 'assigned_to', 'status', 'result', 'results', 'cost_per_result',
        'result_note', 'result_at', 'notes', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'start_on' => 'date',
            'end_on' => 'date',
            'result_at' => 'datetime',
            'budget_usd' => 'integer',
            'daily_usd' => 'integer',
            'cost_per_result' => 'integer',
        ];
    }

    /** @return BelongsTo<Client, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /** @return BelongsTo<Account, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /** @return BelongsTo<User, $this> */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /** @return BelongsTo<AdRun, $this> */
    public function renewedFrom(): BelongsTo
    {
        return $this->belongsTo(AdRun::class, 'renewed_from_id');
    }

    /** @return HasMany<Task, $this> */
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    public function daysLeft(): int
    {
        return (int) now()->startOfDay()->diffInDays($this->end_on->startOfDay(), false);
    }

    /** Who hears about this run: its owner, else the client's manager. */
    public function responsibleId(): ?int
    {
        return $this->assigned_to ?? $this->client?->manager_id;
    }
}
