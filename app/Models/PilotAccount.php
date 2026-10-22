<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Support\LogOptions;

/**
 * An ad account connected in Klok Pilot (Meta, Google, TikTok), and the
 * client it is run for. The list comes from Pilot; only the client link is
 * ours.
 *
 * @property int $pilot_id
 * @property int|null $client_id
 * @property CarbonImmutable|null $seen_at
 */
class PilotAccount extends Model
{
    use Audited;

    protected $fillable = ['pilot_id', 'name', 'external_id', 'platform', 'currency', 'shop', 'client_id', 'seen_at'];

    protected function casts(): array
    {
        return [
            'pilot_id' => 'integer',
            'client_id' => 'integer',
            'seen_at' => 'datetime',
        ];
    }

    /** Only who it belongs to is worth a log line; the rest is copied from Pilot. */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['name', 'client_id'])->logOnlyDirty()->dontLogEmptyChanges()->useLogName('PilotAccount');
    }

    /** @return BelongsTo<Client, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function label(): string
    {
        return $this->name.($this->external_id ? ' · '.$this->external_id : '');
    }
}
