<?php

namespace App\Models;

use App\Support\Access;
use Carbon\CarbonImmutable;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Traits\HasRoles;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property bool $is_active
 * @property bool $is_hidden the agency's own super admin: left out of lists and pickers
 * @property array<string, array{on?: bool, push?: bool, email?: bool}>|null $notify_prefs how each notification group comes (Notifier)
 * @property bool $must_change_password
 * @property int|null $client_id the client a portal user belongs to (none: waiting for the Owner)
 * @property string|null $phone
 * @property string|null $company
 * @property-read Client|null $client
 * @property CarbonImmutable|null $last_login_at
 * @property-read Collection<int, Role> $roles
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'email', 'password', 'is_active', 'must_change_password', 'email_verified_at', 'locale', 'client_id', 'phone', 'company'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmail, PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    protected $attributes = [
        'is_active' => true,
        'is_hidden' => false,
        'must_change_password' => false,
    ];

    /**
     * Everyone but hidden accounts, for lists and "who" pickers.
     *
     * @param  Builder<User>  $query
     */
    public function scopeVisible(Builder $query): void
    {
        $query->where('is_hidden', false);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
            'last_login_at' => 'datetime',
            'is_active' => 'boolean',
            'is_hidden' => 'boolean',
            'notify_prefs' => 'array',
            'must_change_password' => 'boolean',
        ];
    }

    /** @return BelongsTo<Client, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /** A client's own person, who sees only the portal. */
    public function isClient(): bool
    {
        return $this->hasRole(Access::CLIENT);
    }
}
