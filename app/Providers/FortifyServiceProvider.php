<?php

namespace App\Providers;

use App\Actions\Fortify\ResetUserPassword;
use App\Http\Controllers\AppReleaseController;
use App\Models\User;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password as PasswordBroker;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Laravel\Fortify\Contracts\FailedPasswordResetLinkRequestResponse;
use Laravel\Fortify\Features;
use Laravel\Fortify\Fortify;
use Laravel\Fortify\Http\Responses\SuccessfulPasswordResetLinkRequestResponse;

class FortifyServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Forgot password answers the same for every address — known, unknown or asked too
        // often — so the form never tells anyone which emails have an account here.
        $this->app->bind(FailedPasswordResetLinkRequestResponse::class, fn () => new SuccessfulPasswordResetLinkRequestResponse(PasswordBroker::RESET_LINK_SENT));
    }

    /** A bcrypt hash of nothing anyone uses: checked when no account matches, so a wrong email takes as long as a wrong password. */
    private const DUMMY_HASH = '$2y$12$M27ecKbGGAz/8tOgkF8pF.hRd6rmUAevPtRtzMfFNrOzm8PkJ9BR6';

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureActions();
        $this->configureViews();
        $this->configureRateLimiting();
        $this->configureAuthentication();
        $this->configureEmailVerification();
    }

    public const VERIFY_TITLE = 'KlokOne-এ আপনার email confirm করুন'; // i18n

    /**
     * The link a portal sign-up gets to confirm its email: in the app's own mail design and the
     * person's language, sent through the mailbox set in Settings like every other mail (MailSettings).
     */
    private function configureEmailVerification(): void
    {
        VerifyEmail::toMailUsing(function (object $notifiable, string $url): MailMessage {
            $lang = (string) ($notifiable->locale ?? 'bn');
            $minutes = (int) config('auth.verification.expire', 60);

            return (new MailMessage)
                ->subject(__(self::VERIFY_TITLE, [], $lang))
                ->markdown('notifications::email', ['kicker' => __('নিরাপত্তা', [], $lang)])
                ->greeting(__(self::VERIFY_TITLE, [], $lang))
                ->line(__(':name, KlokOne-এ client account খুলতে এই email দেওয়া হয়েছে। নিচের button চাপলে email-টা আপনার বলে confirm হবে।', ['name' => (string) ($notifiable->name ?? '')], $lang))
                ->action(__('Email confirm করুন', [], $lang), $url)
                ->line(__('Link-টা :minutes মিনিট চলবে। আপনি account না খুলে থাকলে কিছু করতে হবে না।', ['minutes' => $minutes], $lang))
                ->salutation(__('শুভেচ্ছান্তে,', [], $lang));
        });
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
            // The cookie carries a keyed hash of the password hash, as Laravel's own does, never
            // the bcrypt hash itself.
            Cookie::queue(Cookie::make(
                $guard->getRecallerName(),
                $user->getAuthIdentifier().'|'.$token.'|'.$guard->hashPasswordForCookie($user->getAuthPassword()),
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

            // The password is always checked, even with no such account, so the answer takes
            // the same time and does not give away which emails exist.
            $matches = Hash::check((string) $request->input('password'), $user->password ?? self::DUMMY_HASH);

            if ($user && $user->is_active && $matches) {
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
            // The Android app's newest version, when there is one to download.
            'app' => AppReleaseController::downloadUrl('plain') !== null ? AppReleaseController::versionName('plain') : null,
        ]));

        Fortify::resetPasswordView(fn (Request $request) => Inertia::render('auth/ResetPassword', [
            'email' => $request->email,
            'token' => $request->route('token'),
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
        ]));

        Fortify::requestPasswordResetLinkView(fn (Request $request) => Inertia::render('auth/ForgotPassword', [
            'status' => $request->session()->get('status'),
        ]));

        Fortify::verifyEmailView(function (Request $request) {
            $user = $request->user();

            // Only a portal self sign-up has to prove its email. Team accounts are made by the Owner,
            // who vouches for the address, so one without the mark (an older account) is confirmed
            // here and goes on, instead of being locked out of the books.
            if ($user instanceof User && $user->roles()->exists() && ! $user->isClient()) {
                $user->markEmailAsVerified();
                activity('Auth')->causedBy($user)->log('email confirmed (team account)');

                return redirect()->intended(Fortify::redirects('email-verification'));
            }

            return Inertia::render('auth/VerifyEmail', [
                'status' => $request->session()->get('status'),
                'email' => $user?->email,
            ]);
        });

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

        // Forgot password and the new-password form: a few tries a minute from one place, and a
        // few a day for one address, so nobody can flood an inbox or use up the mail quota.
        RateLimiter::for('password-reset', fn (Request $request) => [
            Limit::perMinute(5)->by('ip:'.$request->ip()),
            Limit::perDay(10)->by('email:'.Str::lower((string) $request->input('email'))),
        ]);

        // Typing the password again while signed in: a few guesses a minute per person.
        RateLimiter::for('password-confirm', fn (Request $request) => Limit::perMinute(5)->by('user:'.($request->user()->id ?? $request->ip())));

        // Sending the email-confirm link again (and opening it): a few a minute and a dozen an hour
        // per person, so a sign-up cannot flood someone's inbox through our mailbox.
        RateLimiter::for('verification', fn (Request $request) => [
            Limit::perMinute(3)->by('user:'.($request->user()->id ?? $request->ip())),
            Limit::perHour(12)->by('user-hour:'.($request->user()->id ?? $request->ip())),
        ]);

        RateLimiter::for('passkeys', function (Request $request) {
            return Limit::perMinute(10)->by(
                ($request->input('credential.id') ?: $request->session()->getId()).'|'.$request->ip(),
            );
        });
    }
}
