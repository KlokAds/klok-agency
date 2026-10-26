<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property CarbonImmutable|null $joined_on
 * @property CarbonImmutable|null $left_on
 * @property int $monthly_salary
 */
class Employee extends Model
{
    use Audited, SoftDeletes;

    public const STATUSES = ['active' => 'Active', 'left' => 'Left'];

    protected $fillable = ['user_id', 'name', 'designation', 'phone', 'monthly_salary', 'joined_on', 'left_on', 'status', 'notes'];

    /**
     * Payroll's people are the team: every active team member (never a client's person) gets an
     * employee row the first time payroll is opened, so "whose pay?" lists them all. A row made
     * here keeps its own name, salary and dates once the team edits them.
     *
     * @return int how many were added
     */
    public static function syncTeam(): int
    {
        $added = 0;
        $users = User::query()->where('is_active', true)->with('roles')->get()->reject(fn (User $u) => $u->isClient());
        $linked = static::query()->whereNotNull('user_id')->pluck('user_id')->all();

        foreach ($users as $user) {
            if (in_array($user->id, $linked, true)) {
                continue;
            }
            // A row written by hand with the same name is the same person: link it.
            $byName = static::query()->whereNull('user_id')->where('name', $user->name)->first();
            if ($byName) {
                $byName->forceFill(['user_id' => $user->id])->save();

                continue;
            }
            static::create(['user_id' => $user->id, 'name' => $user->name, 'phone' => $user->phone, 'monthly_salary' => 0, 'joined_on' => $user->created_at?->toDateString(), 'status' => 'active']);
            $added++;
        }

        return $added;
    }

    protected function casts(): array
    {
        return ['joined_on' => 'date', 'left_on' => 'date', 'monthly_salary' => 'integer'];
    }

    /** @return HasMany<BdtEntry, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(BdtEntry::class)->where('category', 'salary');
    }
}
