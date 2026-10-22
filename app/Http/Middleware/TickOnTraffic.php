<?php

namespace App\Http\Middleware;

use App\Services\Ticker;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * The live server has no cron, so reminders also go out when someone uses
 * the app: after the response is sent, at most once every five minutes.
 */
class TickOnTraffic
{
    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        if (! $request->user() || app()->runningUnitTests() || ! Cache::add('agency:tick:recent', true, 300)) {
            return;
        }

        try {
            app(Ticker::class)->run();
        } catch (Throwable $e) {
            report($e);
        }
    }
}
