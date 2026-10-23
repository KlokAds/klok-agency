<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A bill to a client. The ad budget lines are USD at the client's rate;
 * the agency's margin is not on it — the books work that out from the FX
 * batches. Money is poisha (usd: cents of ad budget on it).
 *
 * @property int $id
 * @property int $seq
 * @property string $number
 * @property int $client_id
 * @property CarbonImmutable $issue_date
 * @property CarbonImmutable|null $due_date
 * @property string $status sent|partial|paid|void (past its due date is shown, not a status)
 * @property string|null $notes
 * @property string $public_token
 * @property int $total
 * @property int $usd
 * @property int $paid
 * @property CarbonImmutable|null $sent_at
 * @property CarbonImmutable|null $paid_at
 * @property CarbonImmutable|null $voided_at
 * @property CarbonImmutable|null $overdue_notified_at
 * @property int|null $created_by
 * @property CarbonImmutable|null $created_at
 */
class Invoice extends Model
{
    use Audited;

    public const STATUSES = [
        'sent' => 'Sent',
        'partial' => 'Partial',
        'paid' => 'Paid',
        'void' => 'Cancel',
    ];

    /** Statuses a client can still pay against. */
    public const PAYABLE = ['sent', 'partial'];

    public const PREFIX = 'KA-';

    protected $fillable = [
        'seq', 'number', 'client_id', 'issue_date', 'due_date', 'status', 'notes', 'public_token', 'total', 'usd', 'paid',
        'sent_at', 'paid_at', 'voided_at', 'overdue_notified_at', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'issue_date' => 'date', 'due_date' => 'date', 'sent_at' => 'datetime', 'paid_at' => 'datetime',
            'voided_at' => 'datetime', 'overdue_notified_at' => 'datetime',
            'total' => 'integer', 'usd' => 'integer', 'paid' => 'integer', 'seq' => 'integer',
        ];
    }

    /** @return BelongsTo<Client, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class)->withTrashed();
    }

    /** @return HasMany<InvoiceItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class)->orderBy('position')->orderBy('id');
    }

    /** @return HasMany<InvoicePayment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(InvoicePayment::class);
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isPayable(): bool
    {
        return in_array($this->status, self::PAYABLE, true) && $this->due() > 0;
    }

    /** Poisha still to pay. */
    public function due(): int
    {
        return max(0, $this->total - $this->paid);
    }

    /** Can still be changed: not shared with the client yet, and nothing paid. */
    public function isEditable(): bool
    {
        return $this->status === 'sent' && $this->sent_at === null && (int) $this->paid === 0;
    }

    /** Still to pay, and its due date has passed. */
    public function isOverdue(): bool
    {
        return in_array($this->status, self::PAYABLE, true) && $this->due_date !== null && $this->due_date->lt(now()->startOfDay());
    }

    public function publicUrl(): string
    {
        return route('invoice.public', $this->public_token);
    }

    public static function nextSeq(): int
    {
        return (int) static::max('seq') + 1;
    }

    public static function numberFor(int $seq): string
    {
        return self::PREFIX.str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
    }
}
