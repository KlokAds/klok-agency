<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Any piece of agency work with a time on it: a to-do, a client meeting,
 * a follow-up, or a reminder the system made from an ad run.
 *
 * @property 'none'|'daily'|'weekly'|'monthly' $repeat
 * @property CarbonImmutable|null $due_at
 * @property CarbonImmutable|null $remind_at
 * @property CarbonImmutable|null $notified_at
 * @property CarbonImmutable|null $done_at
 */
class Task extends Model
{
    use Audited, SoftDeletes;

    public const KINDS = [
        'task' => 'কাজ',
        'meeting' => 'Meeting',
        'follow_up' => 'Follow-up',
        'renewal' => 'Renew-এর SMS',
        'result' => 'ফলাফল লেখা',
        'scale' => 'Scale / optimize',
    ]; // i18n

    public const REPEATS = ['none' => 'একবার', 'daily' => 'প্রতিদিন', 'weekly' => 'প্রতি সপ্তাহে', 'monthly' => 'প্রতি মাসে']; // i18n

    protected $fillable = [
        'title', 'notes', 'kind', 'priority', 'client_id', 'ad_run_id', 'assigned_to', 'due_at', 'remind_at', 'repeat',
        'notified_at', 'done_at', 'done_by', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return ['due_at' => 'datetime', 'remind_at' => 'datetime', 'notified_at' => 'datetime', 'done_at' => 'datetime'];
    }

    /** @return BelongsTo<Client, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /** @return BelongsTo<AdRun, $this> */
    public function adRun(): BelongsTo
    {
        return $this->belongsTo(AdRun::class);
    }

    /** @return BelongsTo<User, $this> */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('done_at');
    }

    /**
     * Reminders whose time has come and that nobody has been told about yet.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeDue(Builder $query): Builder
    {
        return $query->open()->whereNull('notified_at')->where('remind_at', '<=', now());
    }

    public function responsibleId(): ?int
    {
        return $this->assigned_to ?? $this->client->manager_id ?? $this->adRun?->responsibleId();
    }
}
