<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One phone running the Klok Finance app, for one person.
 *
 * @property int $id
 * @property int $user_id
 * @property string $token_hash
 * @property string $token Firebase Cloud Messaging token
 * @property string|null $device
 * @property int $app_version
 * @property bool $sms_forwarder
 * @property int $failures
 * @property CarbonImmutable|null $last_sent_at
 * @property CarbonImmutable|null $last_seen_at
 * @property CarbonImmutable|null $created_at
 */
class AppDevice extends Model
{
    protected $fillable = ['user_id', 'token_hash', 'token', 'device', 'app_version', 'sms_forwarder', 'failures', 'last_sent_at', 'last_seen_at'];

    protected $hidden = ['token'];

    protected function casts(): array
    {
        return [
            'app_version' => 'integer',
            'sms_forwarder' => 'boolean',
            'failures' => 'integer',
            'last_sent_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
