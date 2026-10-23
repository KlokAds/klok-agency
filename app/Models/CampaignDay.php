<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One day of one campaign's spend, as Klok Pilot reports it (cents of the
 * ad account's currency).
 *
 * @property int $campaign_id
 * @property CarbonImmutable $day
 * @property int $spend
 * @property int|null $budget the campaign's daily budget that day, when known
 */
class CampaignDay extends Model
{
    public $timestamps = false;

    protected $fillable = ['campaign_id', 'day', 'spend', 'budget', 'results', 'clicks'];

    protected function casts(): array
    {
        return ['day' => 'date', 'spend' => 'integer', 'budget' => 'integer', 'results' => 'integer', 'clicks' => 'integer'];
    }

    /** @return BelongsTo<Campaign, $this> */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }
}
