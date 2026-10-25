<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Throwable;

/**
 * Keeps the in-app timer (agency:keep-alive) running on a host with no cron: any request checks
 * that it beat in the last few minutes and, if not, starts it in the background.
 */
class KeepAlive
{
    /** How often the timer ticks. */
    public const EVERY_SECONDS = 60;

    /**
     * How often it wakes Klok Pilot (whose own timer runs on requests): every minute, since its
     * "every five minutes" jobs only run when its scheduler runs on such a minute.
     */
    public const PILOT_EVERY_SECONDS = 60;

    /** One process lives this long, then hands over to a fresh one. */
    public const LIFETIME_SECONDS = 3300;

    /** No beat for this long and the timer is taken to be gone. */
    public const STALE_SECONDS = 180;

    private const BEAT_KEY = 'keep-alive:beat';

    public static function enabled(): bool
    {
        return (bool) config('agency.keep_alive');
    }

    public function beat(): void
    {
        Cache::put(self::BEAT_KEY, now()->toIso8601String(), 3600);
    }

    public function lastBeat(): ?CarbonImmutable
    {
        $beat = Cache::get(self::BEAT_KEY);

        return is_string($beat) ? CarbonImmutable::parse($beat) : null;
    }

    public function running(): bool
    {
        $last = $this->lastBeat();

        return $last !== null && $last->gt(now()->subSeconds(self::STALE_SECONDS));
    }

    /** Starts the timer unless it is running (or someone just started it). */
    public function ensure(): void
    {
        if (self::enabled() && ! $this->running() && Cache::add('keep-alive:starting', true, 120)) {
            $this->start();
        }
    }

    /** A detached `php artisan agency:keep-alive`, its output to the log. */
    public function start(bool $force = false): void
    {
        if (! $force && ! self::enabled()) {
            return;
        }

        $command = sprintf('cd %s && nohup %s artisan agency:keep-alive >> %s 2>&1 &',
            escapeshellarg(base_path()), escapeshellarg((string) config('agency.php_cli')), escapeshellarg(storage_path('logs/keep-alive.log')));

        try {
            Process::timeout(10)->run(['sh', '-c', $command]);
        } catch (Throwable $e) {
            Log::warning('The in-app timer could not start: '.$e->getMessage());
        }
    }

    /** Pilot's address without its MCP path: a plain request there runs its scheduler. */
    public static function pilotWakeUrl(): string
    {
        $parts = parse_url(PilotClient::url());

        return ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? 'pilot.klokads.com').'/up';
    }
}
