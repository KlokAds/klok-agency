<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\PasswordUpdateRequest;
use App\Http\Requests\Settings\TwoFactorAuthenticationRequest;
use App\Notifications\AccountChanged;
use App\Notifications\AgencyAlert;
use App\Providers\FortifyServiceProvider;
use App\Services\Notifier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Fortify\Features;

class SecurityController extends Controller
{
    /**
     * Show the user's security settings page.
     */
    public function edit(TwoFactorAuthenticationRequest $request): Response
    {
        $props = [
            'canManageTwoFactor' => Features::canManageTwoFactorAuthentication(),
            'canManagePasskeys' => Features::canManagePasskeys(),
            'passkeys' => Features::canManagePasskeys()
                ? $request->user()
                    ->passkeys()
                    ->select(['id', 'name', 'credential', 'created_at', 'last_used_at'])
                    ->latest()
                    ->get()
                    ->map(fn ($passkey) => [
                        'id' => $passkey->id,
                        'name' => $passkey->name,
                        'authenticator' => $passkey->authenticator,
                        'created_at_diff' => $passkey->created_at->diffForHumans(),
                        'last_used_at_diff' => $passkey->last_used_at?->diffForHumans(),
                    ])
                    ->values()
                    ->all()
                : [],
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
        ];

        if (Features::canManageTwoFactorAuthentication()) {
            $request->ensureStateIsValid();

            $props['twoFactorEnabled'] = $request->user()->hasEnabledTwoFactorAuthentication();
            $props['requiresConfirmation'] = Features::optionEnabled(Features::twoFactorAuthentication(), 'confirm');
        }

        return Inertia::render('settings/Security', $props);
    }

    /**
     * Update the user's password.
     */
    public function update(PasswordUpdateRequest $request): RedirectResponse
    {
        $user = $request->user();
        $user->update([
            'password' => $request->password,
            'must_change_password' => false,
        ]);

        // Any other device signed in with the old password is signed out; this one stays, and
        // its "keep me signed in" cookie is made again with the new password — the old one would
        // no longer sign it in, so an app update (which restarts the app) signed it out.
        FortifyServiceProvider::signOutElsewhere($user, true);
        activity('Auth')->causedBy($user)->log('password changed');

        // The person hears of it on every device and by email, in case it was not them.
        rescue(fn () => $user->notify(AccountChanged::alert($user, [__('Password')])));

        if ($user->isClient()) {
            app(Notifier::class)->send(null, new AgencyAlert('portal_profile', ProfileController::CLIENT_CHANGED_TITLE, '{name} · {client} · password', [
                'name' => $user->name, 'client' => $user->client->name ?? '—',
            ], ['url' => $user->client_id ? '/clients/'.$user->client_id : '/clients']));
        }

        $this->toast(__('Password বদলানো হয়েছে। অন্য সব device থেকে sign out করা হলো।'));

        return back();
    }
}
