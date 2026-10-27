<?php

use App\Http\Middleware\EnsureAccountIsUsable;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\KeepClientsInPortal;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetLocale;
use App\Http\Middleware\TickOnTraffic;
use App\Notifications\AgencyAlert;
use App\Services\Notifier;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Foundation\Events\DiagnosingHealth;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        // Uptime check: a plain "OK" with our security headers, not Laravel's default health
        // page (that one pulls a script from a CDN onto this site, outside our CSP).
        then: function (): void {
            Route::get('/up', function () {
                Event::dispatch(new DiagnosingHealth);

                return response('OK', 200, ['Content-Type' => 'text/plain; charset=UTF-8', 'Cache-Control' => 'no-store']);
            })->middleware(SecurityHeaders::class);
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        // The uptime check keeps answering while the site is in maintenance (as Laravel's own did).
        $middleware->preventRequestsDuringMaintenance(except: ['/up']);

        // The SMS forwarder phone has no session; its secret URL is its key.
        $middleware->preventRequestForgery(except: ['hooks/*']);

        $middleware->web(append: [
            SecurityHeaders::class,
            SetLocale::class,
            EnsureAccountIsUsable::class,
            KeepClientsInPortal::class,
            HandleAppearance::class,
            HandleInertiaRequests::class,
            TickOnTraffic::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Anything that breaks on the server reaches the Owners' phones: the same error once an
        // hour, never a wrong page address or a form someone filled in wrong.
        $exceptions->report(function (Throwable $e): void {
            if ($e instanceof HttpExceptionInterface || ! app()->isProduction()) {
                return;
            }
            // Pilot being slow or away has its own alert (pilot_down, after it repeats): not every timeout.
            if ($e instanceof ConnectionException || str_starts_with($e->getMessage(), 'Klok Pilot:')) {
                return;
            }
            if (! Cache::add('system-error:'.md5($e::class.$e->getFile().$e->getLine()), 1, now()->addHour())) {
                return;
            }
            rescue(fn () => app(Notifier::class)->send(null, new AgencyAlert(
                'system_error',
                'Server-এ একটা সমস্যা হয়েছে',
                '{error}',
                ['error' => Str::limit(class_basename($e).': '.$e->getMessage(), 180)],
                ['url' => '/admin/errors', 'important' => true],
            )), report: false);
        });
    })->create();
