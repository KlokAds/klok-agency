<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class Setting extends Model
{
    use Audited;

    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['key', 'value'];

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
