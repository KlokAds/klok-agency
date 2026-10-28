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
 * @property string|null $last4
 * @property string|null $app_package
 * @property int|null $topup_from_id
 */
class Account extends Model
{
    use Audited;

    public const KINDS = ['card' => 'Card', 'holding' => 'Holding', 'platform' => 'Ad platform'];

    protected $fillable = ['code', 'name', 'kind', 'last4', 'app_package', 'topup_from_id', 'is_active', 'sort'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /** @return HasMany<UsdEntry, $this> */
    public function entries(): HasMany
    {
        return $this->hasMany(UsdEntry::class);
    }

    /** The main card: where bought dollars land and what pays when no card is named. */
    public static function card(): self
    {
        return static::where('kind', 'card')->orderBy('sort')->firstOrFail();
    }

    /** One of our cards by its last four digits (NSave main or its copy), else none. */
    public static function cardByDigits(?string $last4): ?self
    {
        return $last4 === null ? null : static::where('kind', 'card')->where('last4', $last4)->first();
    }
}
