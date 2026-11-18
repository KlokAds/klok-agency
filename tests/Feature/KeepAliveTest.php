<?php

use App\Models\Setting;
use App\Services\KeepAlive;
use App\Services\Ticker;
use Illuminate\Support\Facades\Process;

it('starts the in-app timer when it has not beaten lately, once, and not when it is running', function () {
    config(['agency.keep_alive' => true, 'agency.php_cli' => '/opt/alt/php85/usr/bin/php']);
    Process::fake();
    $keepAlive = app(KeepAlive::class);

    $keepAlive->ensure();
    $keepAlive->ensure(); // a second visit a moment later starts nothing more

    Process::assertRanTimes(fn ($process) => str_contains(implode(' ', (array) $process->command), 'agency:keep-alive'), 1);

    // Beating: nothing to start.
    cache()->forget('keep-alive:starting');
    $keepAlive->beat();
    $keepAlive->ensure();
    Process::assertRanTimes(fn ($process) => str_contains(implode(' ', (array) $process->command), 'agency:keep-alive'), 1);

    // Off where a real cron runs.
    config(['agency.keep_alive' => false]);
    cache()->flush();
    $keepAlive->ensure();
    Process::assertRanTimes(fn ($process) => str_contains(implode(' ', (array) $process->command), 'agency:keep-alive'), 1);
});

it('wakes Pilot at its own address, not the MCP path', function () {
    expect(KeepAlive::pilotWakeUrl())->toBe('https://pilot.klokads.com/up');
});

it('does nothing once retired: KlokOne runs the clients and ads now', function () {
    config(['agency.keep_alive' => true, 'agency.retired' => true, 'agency.php_cli' => '/opt/alt/php85/usr/bin/php']);
    Process::fake();

    app(KeepAlive::class)->ensure();
    app(KeepAlive::class)->start(force: true);
    Process::assertNothingRan();

    expect(app(Ticker::class)->run())->toBe(['ended' => 0, 'reminders' => 0, 'summary' => false])
        ->and(Setting::get('last_tick_at'))->toBeNull();
    $this->artisan('agency:keep-alive')->assertSuccessful();
});
