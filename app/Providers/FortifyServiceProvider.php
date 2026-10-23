<?php

namespace App\Providers;

use App\Actions\Fortify\ResetUserPassword;
use App\Models\User;
use Illuminate\Auth\Events\Login;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Laravel\Fortify\Features;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureActions();
        $this->configureViews();
        $this->configureRateLimiting();
        $this->configureAuthentication();
    }

    /**
     * One place at a time: signing in (in the app or a browser) ends every
     * other session of that person, and makes their other "remember this
     * device" cookies useless, so the other place is at the sign-in page on
     * its next click. This device keeps its own remember cookie.
     */
    public static function signOutElsewhere(User $user, bool $remember): void
    {
        DB::table((string) config('session.table', 'sessions'))
            ->where('user_id', $user->id)
            ->where('id', '!=', session()->getId())
            ->delete();

        $token = Str::random(60);
        $user->forceFill(['remember_token' => $token])->saveQuietly();

        if ($remember) {
            $guard = Auth::guard('web');
            Cookie::queue(Cookie::make(
                $guard->getRecallerName(),
                $user->getAuthIdentifier().'|'.$token.'|'.$user->getAuthPassword(),
                60 * 24 * 365 * 5,
            ));
        }

        activity('Auth')->causedBy($user)->log('signed out everywhere else');
    }

    /**
     * Deactivated accounts cannot sign in, and every sign-in is recorded.
     */
    private function configureAuthentication(): void
    {
        Fortify::authenticateUsing(function (Request $request) {
            $user = User::where('email', Str::lower((string) $request->input('email')))->first();

            if ($user && $user->is_active && Hash::check((string) $request->input('password'), $user->password)) {
                return $user;
            }

            // Same answer for a wrong password and a disabled account.
            return null;
        });

        Event::listen(Login::class, function (Login $event) {
            // Only app users sign in; anything else has no login columns to stamp.
            if (! $event->user instanceof User) {
                return;
            }

            $event->user->forceFill([
                'last_login_at' => now(),
                'last_login_ip' => request()->ip(),
            ])->saveQuietly();

            self::signOutElsewhere($event->user, $event->remember);

            activity('Auth')->causedBy($event->user)->withProperties([
                'ip' => request()->ip(),
                'agent' => Str::limit((string) request()->userAgent(), 200),
            ])->log('login');
        });
    }

    /**
     * Configure Fortify actions.
     */
    private function configureActions(): void
    {
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
    }

    /**
     * Configure Fortify views.
     */
    private function configureViews(): void
    {
        Fortify::loginView(fn (Request $request) => Inertia::render('auth/Login', [
            'canResetPassword' => Features::enabled(Features::resetPasswords()),
            'status' => $request->session()->get('status'),
        ]));

        Fortify::resetPasswordView(fn (Request $request) => Inertia::render('auth/ResetPassword', [
            'email' => $request->email,
            'token' => $request->route('token'),
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
        ]));

        Fortify::requestPasswordResetLinkView(fn (Request $request) => Inertia::render('auth/ForgotPassword', [
            'status' => $request->session()->get('status'),
        ]));

        Fortify::verifyEmailView(fn (Request $request) => Inertia::render('auth/VerifyEmail', [
            'status' => $request->session()->get('status'),
        ]));

        Fortify::twoFactorChallengeView(fn () => Inertia::render('auth/TwoFactorChallenge'));

        Fortify::confirmPasswordView(fn () => Inertia::render('auth/ConfirmPassword'));
    }

    /**
     * Configure rate limiting.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('two-factor', function (Request $request) {
            return Limit::perMinute(5)->by($request->session()->get('login.id'));
        });

        RateLimiter::for('login', function (Request $request) {
            $throttleKey = Str::transliterate(Str::lower($request->input(Fortify::username())).'|'.$request->ip());

            return Limit::perMinute(5)->by($throttleKey);
        });

        RateLimiter::for('passkeys', function (Request $request) {
            return Limit::perMinute(10)->by(
                ($request->input('credential.id') ?: $request->session()->getId()).'|'.$request->ip(),
            );
        });
    }
}
