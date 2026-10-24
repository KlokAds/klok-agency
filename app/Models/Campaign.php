<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
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
 * @property int|null $budget cents a day, as Meta last said (set_campaign_limits)
 * @property int|null $spend_cap cents, all time, on Meta
 * @property int|null $spent_before cents spent on Meta before bill_from
 * @property CarbonImmutable|null $limits_at
 * @property string|null $limits_error
 * @property CarbonImmutable|null $paused_for_credit_at
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
        'budget', 'spend_cap', 'spent_before', 'limits_at', 'limits_error', 'paused_for_credit_at',
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
            'budget' => 'integer',
            'spend_cap' => 'integer',
            'spent_before' => 'integer',
            'limits_at' => 'datetime',
            'paused_for_credit_at' => 'datetime',
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

    /** @return HasMany<Renewal, $this> */
    public function renewals(): HasMany
    {
        return $this->hasMany(Renewal::class);
    }

    /** Cents a day: what Meta last said, else what Pilot's list showed. */
    public function dailyBudget(): ?int
    {
        return $this->budget ?? $this->daily_budget;
    }

    /**
     * The last day it runs (app time zone). Meta often ends a campaign at
     * midnight, "26 Oct 00:00", which means it runs through 25 Oct.
     */
    public function lastDay(): ?CarbonImmutable
    {
        return $this->stop_at === null
            ? null
            : CarbonImmutable::parse($this->stop_at)->setTimezone(config('app.timezone'))->subSecond()->startOfDay();
    }

    /**
     * When it stops, said so nobody misreads it: Meta's "26 Oct 12:00 AM" is the night of
     * 25 Oct, so a midnight end reads "25 Oct রাত শেষে (রাত 12টায়) বন্ধ"; any other time is
     * given as it is.
     */
    public function stopsText(): ?string
    {
        if ($this->stop_at === null) {
            return null;
        }

        $stop = CarbonImmutable::parse($this->stop_at)->setTimezone(config('app.timezone'));

        return $stop->format('H:i') === '00:00'
            ? __(':date রাত শেষে (রাত 12টায়) বন্ধ', ['date' => $stop->subDay()->format('j M')])
            : __(':date, :time-এ বন্ধ', ['date' => $stop->format('j M'), 'time' => $stop->format('g:i A')]);
    }

    /** Switched on and not past its end (Meta keeps an ended campaign ACTIVE). */
    public function isLive(): bool
    {
        return in_array(strtoupper((string) $this->status), self::LIVE, true)
            && ($this->stop_at === null || CarbonImmutable::parse($this->stop_at)->isFuture());
    }

    /**
     * Full days it still runs after today: ending 26 Oct 12:00 AM, on 23 Oct
     * that is 2 (the 24th and the 25th). Null when it has no end.
     */
    public function daysLeft(): ?int
    {
        $last = $this->lastDay();

        if (! $this->isLive() || $last === null) {
            return $this->isLive() ? null : 0;
        }

        return max(0, (int) CarbonImmutable::now()->startOfDay()->diffInDays($last, false));
    }

    /** Ended on Meta's clock, whatever its status says. */
    public function hasEnded(): bool
    {
        return $this->stop_at !== null && CarbonImmutable::parse($this->stop_at)->isPast();
    }

    /**
     * Campaigns running now: switched on and not past their end.
     *
     * @param  Builder<Campaign>  $query
     */
    public function scopeRunning(Builder $query): void
    {
        $query->whereIn('status', self::LIVE)->where(fn ($q) => $q->whereNull('stop_at')->orWhere('stop_at', '>', now()));
    }
}
