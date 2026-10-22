<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Spatie\Activitylog\Support\LogOptions;

class Setting extends Model
{
    use Audited;

    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['key', 'value'];

    /** Keys whose value is a secret: the log notes the change, never the value. */
    public const SECRETS = ['pilot_token', 'tick_token', 'sms_device_token'];

    public function getActivitylogOptions(): LogOptions
    {
        $options = LogOptions::defaults()->logAll()->logOnlyDirty()->useLogName('Setting');

        if (in_array($this->key, self::SECRETS, true)) {
            return $options->logExcept(['value', 'created_at', 'updated_at'])->logEmptyChanges();
        }

        return $options->logExcept(['created_at', 'updated_at'])->dontLogEmptyChanges();
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $all = Cache::rememberForever('settings', fn () => static::pluck('value', 'key')->all());

        return $all[$key] ?? $default;
    }

    public static function put(string $key, mixed $value): void
    {
        static::updateOrCreate(['key' => $key], ['value' => $value]);
        Cache::forget('settings');
    }

    /** For the system's own bookkeeping (last run times), which would only clutter the log. */
    public static function putQuietly(string $key, mixed $value): void
    {
        static::query()->upsert([['key' => $key, 'value' => $value, 'created_at' => now(), 'updated_at' => now()]], ['key'], ['value', 'updated_at']);
        Cache::forget('settings');
    }
}
