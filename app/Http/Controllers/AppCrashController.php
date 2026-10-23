<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * The Android app reports its own crashes here (it cannot be signed in when
 * it crashes), so a crash on someone's phone can be fixed without a cable.
 * Only the error and the phone's model are sent; kept in the app-crash log.
 */
class AppCrashController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'where' => ['required', 'string', 'max:20'],
            'error' => ['required', 'string', 'max:20000'],
            'version' => ['nullable', 'string', 'max:30'],
            'device' => ['nullable', 'string', 'max:120'],
        ]);

        Log::build(['driver' => 'daily', 'path' => storage_path('logs/app-crash.log'), 'days' => 30])
            ->error("[{$data['where']}] v".($data['version'] ?? '?').' · '.($data['device'] ?? '?')."\n".$data['error']);

        return response()->json(['ok' => true]);
    }
}
