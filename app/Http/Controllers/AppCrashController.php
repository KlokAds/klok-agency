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
            // The app sends up to 18000 characters of stack; it is cut here, not turned away.
            'error' => ['required', 'string', 'max:20000'],
            'version' => ['nullable', 'string', 'max:30'],
            'device' => ['nullable', 'string', 'max:120'],
        ]);

        Log::build(['driver' => 'daily', 'path' => storage_path('logs/app-crash.log'), 'days' => 30])
            ->error('['.$this->line($data['where']).'] v'.$this->line($data['version'] ?? '?').' · '.$this->line($data['device'] ?? '?')
                .' · '.mb_substr($this->line($data['error']), 0, 4000));

        return response()->json(['ok' => true]);
    }

    /** One log line: breaks become ⏎ and other control characters a space, so nobody can add a fake entry. */
    private function line(string $text): string
    {
        return (string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', (string) preg_replace('/[\r\n]+/', ' ⏎ ', $text));
    }
}
