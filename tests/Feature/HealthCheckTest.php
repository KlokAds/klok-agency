<?php

use Illuminate\Foundation\Events\DiagnosingHealth;
use Illuminate\Support\Facades\Event;

it('answers the uptime check with a plain OK and no third-party script', function () {
    Event::fake([DiagnosingHealth::class]);

    $response = $this->get('/up');

    $response->assertOk();
    expect($response->getContent())->toBe('OK');
    expect($response->headers->get('Content-Type'))->toStartWith('text/plain');
    expect($response->getContent())->not->toContain('<script');
    Event::assertDispatched(DiagnosingHealth::class);
});

it('sends the security headers on the uptime check', function () {
    $response = $this->get('/up');

    expect($response->headers->get('Content-Security-Policy'))->toContain("default-src 'self'");
    $response->assertHeader('X-Frame-Options', 'DENY');
    $response->assertHeader('X-Content-Type-Options', 'nosniff');
});

it('does not start a session for the uptime check', function () {
    $this->get('/up')->assertCookieMissing(config('session.cookie'));
});

it('keeps answering the uptime check during maintenance', function () {
    // Kept in the array cache, not storage/framework/down, so other test runs are not affected.
    config(['app.maintenance.driver' => 'cache', 'app.maintenance.store' => 'array']);
    app()->maintenanceMode()->activate([]);

    $this->get('/up')->assertOk();
    $this->get('/login')->assertStatus(503);
});
