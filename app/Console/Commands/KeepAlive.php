<?php

namespace App\Console\Commands;

use App\Services\KeepAlive as KeepAliveService;
use App\Services\Ticker;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * The app's own timer, for a host with no cron: every minute it runs the tick (reminders, spend,
 * card charges, alerts…) and wakes Klok Pilot, so both stay current with
 * nobody visiting. Before the hour is up it starts its own successor and stops, so no process
 * lives long; a visit, the bell or the SMS phone's heartbeat start it again if the host ended it.
 */
#[Signature('agency:keep-alive')]
#[Description('Run the tick every minute and keep Klok Pilot awake (the in-app cron)')]
class KeepAlive extends Command
{
    public function handle(Ticker $ticker, KeepAliveService $keepAlive): int
    {
        $started = time();
        $lastPilot = 0;

        while (time() - $started < KeepAliveService::LIFETIME_SECONDS) {
            $keepAlive->beat();

            try {
                $ticker->run();
            } catch (Throwable $e) {
                report($e);
            }

            if (time() - $lastPilot >= KeepAliveService::PILOT_EVERY_SECONDS) {
                $lastPilot = time();
                // Pilot's scheduler starts on any request when it has no cron of its own.
                rescue(fn () => Http::timeout(10)->get(KeepAliveService::pilotWakeUrl()), report: false);
            }

            sleep(KeepAliveService::EVERY_SECONDS);
        }

        // The successor takes over before this one goes.
        $keepAlive->start(force: true);

        return self::SUCCESS;
    }
}
