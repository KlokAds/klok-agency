<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use App\Support\Access;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

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
 * @property CarbonImmutable|null $deleted_at
 */
class Invoice extends Model
{
    use Audited, SoftDeletes;

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
        'allow_partial', 'sent_at', 'paid_at', 'voided_at', 'overdue_notified_at', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'issue_date' => 'date', 'due_date' => 'date', 'sent_at' => 'datetime', 'paid_at' => 'datetime',
            'voided_at' => 'datetime', 'overdue_notified_at' => 'datetime', 'deleted_at' => 'datetime',
            'total' => 'integer', 'usd' => 'integer', 'paid' => 'integer', 'seq' => 'integer', 'allow_partial' => 'boolean',
        ];
    }

    /** Every invoice gets its own code for its admin address. */
    protected static function booted(): void
    {
        static::creating(function (Invoice $invoice): void {
            $invoice->uid ??= strtolower((string) Str::ulid());
        });
    }

    /** /invoices/{uid}: the address does not show how many invoices there are. */
    public function getRouteKeyName(): string
    {
        return 'uid';
    }

    /**
     * The code, or (for links in older notifications) the plain number.
     *
     * @param  mixed  $value
     * @param  string|null  $field
     */
    public function resolveRouteBinding($value, $field = null): ?Model
    {
        $value = (string) $value;

        return $field === null && ctype_digit($value)
            ? static::query()->whereKey((int) $value)->first()
            : static::query()->where($field ?? 'uid', $value)->first();
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

    /**
     * Can still be changed (a mistake fixed): nothing paid on it yet, also once the client has the
     * link — their page shows the new figures.
     */
    public function isEditable(): bool
    {
        return $this->status === 'sent' && (int) $this->paid === 0;
    }

    /** Made by the client's own person on the portal (a top-up, a renewal, paying what is owed), not by us. */
    public function madeByClient(): bool
    {
        return $this->author !== null && (int) $this->author->client_id === (int) $this->client_id;
    }

    /**
     * Who may delete it: one with money on it, or one the client made, only an Owner; the rest
     * anyone allowed to delete clients' things.
     */
    public function deletableBy(?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        return (int) $this->paid > 0 || $this->madeByClient()
            ? $user->hasRole(Access::OWNER)
            : $user->can('clients.delete');
    }

    /**
     * What the client has been given: shared with them (sent by link, WhatsApp or email) or paid.
     * One made but never sent stays the agency's own until it is.
     *
     * @param  Builder<Invoice>  $query
     */
    public function scopeShared(Builder $query): void
    {
        $query->where('status', '!=', 'draft')->where(fn (Builder $q) => $q->whereNotNull('sent_at')->orWhere('paid', '>', 0));
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
        // A deleted one waiting in Trash keeps its number: never given again.
        return (int) static::withTrashed()->max('seq') + 1;
    }

    /**
     * The number an invoice shows: the client's code and their own count — KA-GFFS-01, KA-GFFS-02 —
     * so a client's invoices read in order; without a client, the plain running number.
     */
    public static function numberFor(int $seq, ?Client $client = null): string
    {
        if ($client === null) {
            return self::PREFIX.str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
        }
        $code = self::codeFor($client);
        $n = (int) static::withTrashed()->where('client_id', $client->id)->count() + 1;
        do {
            $number = self::PREFIX.$code.'-'.str_pad((string) $n, 2, '0', STR_PAD_LEFT);
            $n++;
        } while (static::withTrashed()->where('number', $number)->exists());

        return $number;
    }

    /** "GFFS" for GFFS, "MD-ZABED" for Md Zabed: letters and digits of the name, at most twelve. */
    public static function codeFor(Client $client): string
    {
        $code = strtoupper(trim((string) preg_replace('/[^A-Za-z0-9]+/', '-', Str::ascii((string) $client->name)), '-'));

        return $code !== '' ? Str::limit($code, 12, '') : 'C'.$client->id;
    }
}
