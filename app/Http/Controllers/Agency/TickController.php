<?php

namespace App\Http\Controllers\Agency;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Services\Ticker;
use Illuminate\Http\JsonResponse;

/**
 * A secret URL for cron-job.org (or any pinger) to call every few minutes,
 * so reminders go out on time even when nobody has the app open.
 */
class TickController extends Controller
{
    public function __invoke(string $token, Ticker $ticker): JsonResponse
    {
        $secret = (string) Setting::get('tick_token');
        abort_unless($secret !== '' && hash_equals($secret, $token), 404);

        return response()->json(['ok' => true] + $ticker->run());
    }
}
