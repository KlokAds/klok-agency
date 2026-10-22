<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Support\LogOptions;

/**
 * A campaign in a Klok Pilot ad account and the client it is run for. Its
 * spend from bill_from on is billed to that client. Name, status and
 * spend are copied from Pilot; only the client and bill_from are ours.
 *
 * @property int $pilot_campaign_id
 * @property int $pilot_account_id
 * @property int|null $client_id
 * @property int|null $daily_budget cents
 * @property int $spend_total cents
 * @property int $spend_7d cents
 * @property CarbonImmutable|null $start_at
 * @property CarbonImmutable|null $stop_at
 * @property CarbonImmutable|null $last_spend_day
 * @property CarbonImmutable|null $bill_from
 */
class Campaign extends Model
{
    use Audited;

    public const LIVE = ['ACTIVE', 'ENABLED', 'ENABLE'];

    protected $fillable = [
        'pilot_campaign_id', 'pilot_account_id', 'external_id', 'name', 'status', 'objective', 'daily_budget',
        'start_at', 'stop_at', 'spend_total', 'spend_7d', 'last_spend_day', 'client_id', 'bill_from',
    ];

    protected function casts(): array
    {
        return [
            'pilot_campaign_id' => 'integer',
            'pilot_account_id' => 'integer',
            'client_id' => 'integer',
            'daily_budget' => 'integer',
            'spend_total' => 'integer',
            'spend_7d' => 'integer',
            'start_at' => 'datetime',
            'stop_at' => 'datetime',
            'last_spend_day' => 'date',
            'bill_from' => 'date',
        ];
    }

    /** Only who it is billed to, and from when, is worth a log line. */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['name', 'client_id', 'bill_from'])->logOnlyDirty()->dontLogEmptyChanges()->useLogName('Campaign');
    }

    /** @return BelongsTo<PilotAccount, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(PilotAccount::class, 'pilot_account_id');
    }

    /** @return BelongsTo<Client, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /** @return HasMany<CampaignDay, $this> */
    public function days(): HasMany
    {
        return $this->hasMany(CampaignDay::class);
    }

    public function isLive(): bool
    {
        return in_array(strtoupper((string) $this->status), self::LIVE, true);
    }
}
