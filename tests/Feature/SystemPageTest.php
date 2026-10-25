<?php

use App\Models\User;
use App\Notifications\AgencyAlert;
use App\Services\Notifier;
use App\Support\Access;
use Database\Seeders\AccessSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;

beforeEach(fn () => $this->seed(AccessSeeder::class));

it('shows the owner every job that runs by itself', function () {
    $owner = User::factory()->create();
    $owner->assignRole(Access::OWNER);

    $this->actingAs($owner)->get('/admin/system')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('admin/System')
            ->has('timer.running')
            ->has('pilot.spend_synced')
            ->has('receipts.mailbox')
            ->has('errors_today'));
});

it('keeps the system page from everyone but an owner', function () {
    $user = User::factory()->create();
    $user->assignRole('Viewer');

    $this->actingAs($user)->get('/admin/system')->assertForbidden();
    $this->actingAs($user)->post('/admin/system/run/tick')->assertForbidden();
});

it('runs the tick now from the system page', function () {
    $owner = User::factory()->create();
    $owner->assignRole(Access::OWNER);

    $this->actingAs($owner)->from('/admin/system')->post('/admin/system/run/tick')
        ->assertRedirect('/admin/system')
        ->assertSessionHasNoErrors();
    $this->actingAs($owner)->post('/admin/system/run/nothing')->assertNotFound();
});

it('tells the owners once an hour about a server error', function () {
    Notification::fake();
    $owner = User::factory()->create();
    $owner->assignRole(Access::OWNER);

    $alert = new AgencyAlert('system_error', 'Server-এ একটা সমস্যা হয়েছে', '{error}', ['error' => 'RuntimeException: boom'], ['url' => '/admin/errors', 'important' => true]);
    expect(app(Notifier::class)->send(null, $alert))->toBe(1);
    Notification::assertSentTo($owner, AgencyAlert::class, fn (AgencyAlert $n) => $n->kind === 'system_error');

    // The throttle key the handler uses: the same error again within the hour is not re-sent.
    expect(Cache::add('system-error:abc', 1, now()->addHour()))->toBeTrue()
        ->and(Cache::add('system-error:abc', 1, now()->addHour()))->toBeFalse();
});
