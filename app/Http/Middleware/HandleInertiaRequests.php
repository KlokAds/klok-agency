<?php

namespace App\Http\Middleware;

use App\Models\Setting;
use App\Models\UsdEntry;
use App\Models\User;
use App\Support\Access;
use App\Support\Lookups;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Only what the screen needs about the user goes to the browser —
     * never the whole model.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'locale' => app()->getLocale(),
            'auth' => [
                'user' => $user ? [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->roles->first()?->name,
                    'two_factor_enabled' => $user->two_factor_confirmed_at !== null,
                    'must_change_password' => $user->must_change_password,
                ] : null,
                'can' => $user ? $this->permissions($user) : [],
            ],
            'notice' => fn () => $request->session()->get('notice'),
            'idleMinutes' => fn () => $user ? (int) Setting::get('idle_minutes', 30) : null,
            'unread' => fn () => $user ? $user->unreadNotifications()->count() : 0,
            // Loaded only when the quick-entry panel opens (partial reload).
            'lookups' => Inertia::optional(fn () => $user ? [
                // Inactive clients stay pickable: they can still owe money.
                'clients' => Lookups::clients(),
                'accounts' => Lookups::accounts(),
                'heads' => Lookups::heads(),
                'wallets' => Lookups::wallets(),
                // Campaign spend is settled by the client's credit, not picked by hand.
                'pending' => UsdEntry::where('category', 'client_spend')->where('status', 'pending')->whereNull('source')
                    ->ledgerOrder()->get(['id', 'client_id', 'entry_date', 'description', 'amount'])
                    ->map(fn ($e) => [
                        'id' => $e->id, 'client_id' => $e->client_id, 'date' => $e->entry_date?->toDateString(),
                        'description' => $e->description, 'amount' => -$e->amount,
                    ]),
            ] : null),
        ];
    }

    /** @return array<string, bool> */
    private function permissions(User $user): array
    {
        $names = $user->hasRole(Access::OWNER) ? Access::all() : $user->getAllPermissions()->pluck('name')->all();

        return array_fill_keys($names, true);
    }
}
