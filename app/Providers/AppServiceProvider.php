<?php

namespace App\Providers;

use App\Support\Access;
use App\Support\MailSettings;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

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

        // The mailbox saved in Settings, once something is about to send mail.
        $this->app->resolving('mail.manager', fn () => rescue(fn () => MailSettings::apply(), report: false));

        // The Owner role can do everything; other roles go through permissions.
        Gate::before(fn ($user) => $user->hasRole(Access::OWNER) ? true : null);

        // English text lives in lang/en.json plus one file per area under lang/parts/*/en.json,
        // keyed by the বাংলা source text. The same files feed the Vue side.
        foreach (glob(lang_path('parts/*'), GLOB_ONLYDIR) ?: [] as $dir) {
            app('translator')->addJsonPath($dir);
        }
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

        Password::defaults(fn (): Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : Password::min(10)->letters()->numbers(),
        );

        Model::preventLazyLoading(! app()->isProduction());
        Model::preventSilentlyDiscardingAttributes(! app()->isProduction());
    }
}
