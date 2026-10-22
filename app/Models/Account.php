<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A place USD sits or is spent through: the card, a holding account,
 * or an ad platform (Meta, Google, TikTok).
 *
 * @property bool $is_active
 */
class Account extends Model
{
    use Audited;

    public const KINDS = ['card' => 'Card', 'holding' => 'Holding', 'platform' => 'Ad platform'];

    protected $fillable = ['code', 'name', 'kind', 'is_active', 'sort'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /** @return HasMany<UsdEntry, $this> */
    public function entries(): HasMany
    {
        return $this->hasMany(UsdEntry::class);
    }

    public static function card(): self
    {
        return static::where('kind', 'card')->orderBy('sort')->firstOrFail();
    }
}
