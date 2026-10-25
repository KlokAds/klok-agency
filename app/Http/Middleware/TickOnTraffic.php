<?php

namespace App\Http\Middleware;

use App\Services\KeepAlive;
use App\Services\Ticker;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * The live server has no cron, so reminders go out whenever the site is
 * visited (signed in or not, and every minute while the app is open thanks
 * to the bell): after the response is sent, at most once every five minutes.
 */
class TickOnTraffic
{
    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        // The in-app timer: started again by any visit if the host ended it.
        rescue(fn () => app(KeepAlive::class)->ensure());

        if (app()->runningUnitTests() || ! Cache::add('agency:tick:recent', true, 300)) {
            return;
        }

        try {
            app(Ticker::class)->run();
        } catch (Throwable $e) {
            report($e);
        }
    }
}
