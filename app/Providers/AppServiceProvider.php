<?php

namespace App\Providers;

use App\Services\ActivityAlerts;
use App\Support\Access;
use App\Support\MailSettings;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Spatie\Activitylog\Models\Activity;

class AppServiceProvider extends ServiceProvider
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
        $this->configureDefaults();
        $this->configureRateLimits();

        // The mailbox saved in Settings, once something is about to send mail.
        $this->app->resolving('mail.manager', fn () => rescue(fn () => MailSettings::apply(), report: false));

        // Every change a person makes is in the activity log; the ones worth knowing become
        // quiet notifications for whoever follows them (see ActivityAlerts).
        Activity::created(fn (Activity $activity) => app(ActivityAlerts::class)->handle($activity));

        // The Owner role can do everything; other roles go through permissions.
        Gate::before(fn ($user) => $user->hasRole(Access::OWNER) ? true : null);

        // English text lives in lang/en.json plus one file per area under lang/parts/*/en.json,
        // keyed by the বাংলা source text. The same files feed the Vue side.
        foreach (glob(lang_path('parts/*'), GLOB_ONLYDIR) ?: [] as $dir) {
            app('translator')->addJsonPath($dir);
        }
    }

    /**
     * Limits for the public invoice/renewal routes (routes/web.php). Each route gets its own
     * counter per person (or IP for guests): a bare throttle:N,M shares one counter across
     * every route, so the invoice page's status poll used to lock out pay and claim.
     */
    protected function configureRateLimits(): void
    {
        $perRoute = fn (Request $request): string => ($request->route()?->getName() ?? $request->path())
            .'|'.($request->user()?->getAuthIdentifier() ?? $request->ip());

        RateLimiter::for('public-view', fn (Request $request) => Limit::perMinute(60)->by($perRoute($request)));
        RateLimiter::for('public-action', fn (Request $request) => Limit::perMinute(12)->by($perRoute($request)));
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        // Eight characters, letters and numbers: short enough for a client to type on a phone.
        // On the live site it must also not be one already leaked somewhere.
        Password::defaults(fn (): Password => app()->isProduction()
            ? Password::min(8)->letters()->numbers()->uncompromised()
            : Password::min(8)->letters()->numbers(),
        );

        Model::preventLazyLoading(! app()->isProduction());
        Model::preventSilentlyDiscardingAttributes(! app()->isProduction());
    }
}
