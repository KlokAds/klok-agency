<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\Access;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Settings › সমস্যা: what went wrong on the server (Klok Pilot not
 * answering, Firebase refusing, a page failing) and crashes the phone app
 * reported, newest first. Read from the logs; nothing is stored twice.
 */
class ErrorLogController extends Controller
{
    private const SHOW = 60;

    public function index(Request $request): Response
    {
        abort_unless($request->user()->hasRole(Access::OWNER), 403);

        $entries = [];
        $files = collect(File::glob(storage_path('logs/*.log')))
            ->sortByDesc(fn (string $f) => filemtime($f))
            ->take(6);

        foreach ($files as $file) {
            $source = str_starts_with(basename($file), 'app-crash') ? 'app' : 'server';
            foreach (self::read($file) as $entry) {
                if ($source === 'app' || in_array($entry['level'], ['ERROR', 'CRITICAL', 'ALERT', 'EMERGENCY', 'WARNING'], true)) {
                    $entries[] = [...$entry, 'source' => $source];
                }
            }
        }

        usort($entries, fn ($a, $b) => strcmp($b['at'], $a['at']));

        return Inertia::render('admin/Errors', ['entries' => array_slice($entries, 0, self::SHOW)]);
    }

    /**
     * The last entries of one log file: "[2026-10-23 19:49:40] production.ERROR: message {…}".
     *
     * @return list<array{at: string, level: string, message: string, detail: string}>
     */
    private static function read(string $file): array
    {
        // The end of the file is enough: only the newest entries are shown.
        $size = filesize($file) ?: 0;
        $handle = fopen($file, 'r');
        if ($handle === false) {
            return [];
        }
        fseek($handle, max(0, $size - 400_000));
        $text = (string) stream_get_contents($handle);
        fclose($handle);

        preg_match_all('/^\[(\d{4}-\d\d-\d\d[ T]\d\d:\d\d:\d\d)[^\]]*\] \w+\.(\w+): (.*?)(?=^\[\d{4}-\d\d-\d\d[ T]|\z)/ms', $text, $m, PREG_SET_ORDER);

        $out = [];
        foreach ($m as $match) {
            $body = trim($match[3]);
            $firstLine = strtok($body, "\n") ?: '';
            // The message without the JSON context and the long stack trace.
            $message = trim((string) preg_replace('/\s\{"(exception|userId)".*$/s', '', $firstLine));
            $where = preg_match('/at (\/[^\s)]+:\d+)/', $body, $w) ? str_replace(base_path().'/', '', $w[1]) : '';
            $out[] = [
                'at' => str_replace('T', ' ', $match[1]),
                'level' => strtoupper($match[2]),
                'message' => mb_substr($message, 0, 400),
                'detail' => mb_substr($where !== '' ? $where : trim((string) substr($body, strlen($firstLine))), 0, 1500),
            ];
        }

        return $out;
    }
}
