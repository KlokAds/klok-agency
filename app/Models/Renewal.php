<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The link a client gets two days before a campaign ends: pay for more days
 * or end the contract. A paid renewal waits for an admin's OK, which moves
 * the campaign's end on Meta.
 *
 * @property int $id
 * @property int $campaign_id
 * @property int $client_id
 * @property string $token
 * @property CarbonImmutable $ends_on
 * @property int|null $budget cents a day when the link was made
 * @property int|null $days
 * @property int|null $usd cents
 * @property int|null $invoice_id
 * @property string $status open|declined|invoiced|paid|approved|expired
 * @property CarbonImmutable|null $new_end
 * @property CarbonImmutable|null $decided_at
 * @property CarbonImmutable|null $paid_at
 * @property CarbonImmutable|null $approved_at
 * @property int|null $approved_by
 * @property string|null $error
 */
class Renewal extends Model
{
    use Audited;

    public const STATUSES = [
        'open' => 'Client-এর উত্তরের অপেক্ষা', // i18n
        'declined' => 'Contract শেষ', // i18n
        'invoiced' => 'Invoice হয়েছে — টাকার অপেক্ষা', // i18n
        'paid' => 'টাকা এসেছে — approve করুন', // i18n
        'approved' => 'Extend হয়েছে', // i18n
        'expired' => 'মেয়াদ পার', // i18n
    ];

    /** Day choices on the client's page. */
    public const DAY_CHOICES = [3, 7, 15, 30];

    protected $fillable = [
        'campaign_id', 'client_id', 'token', 'ends_on', 'budget', 'days', 'usd', 'invoice_id', 'status', 'new_end',
        'decided_at', 'paid_at', 'approved_at', 'approved_by', 'error',
    ];

    protected function casts(): array
    {
        return [
            'ends_on' => 'date', 'new_end' => 'date', 'decided_at' => 'datetime', 'paid_at' => 'datetime', 'approved_at' => 'datetime',
            'budget' => 'integer', 'days' => 'integer', 'usd' => 'integer',
        ];
    }

    /** @return BelongsTo<Campaign, $this> */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    /** @return BelongsTo<Client, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class)->withTrashed();
    }

    /** @return BelongsTo<Invoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function isOpen(): bool
    {
        return $this->status === 'open';
    }

    public function publicUrl(): string
    {
        return route('renewal.public', $this->token);
    }

    /** The admin's page for it (the approve button is there, behind the login). */
    public function adminUrl(): string
    {
        return route('renewals.show', $this->id);
    }
}
