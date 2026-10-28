<?php

use App\Models\CardEvent;
use App\Models\PaymentSms;
use App\Models\User;
use App\Support\Access;
use Database\Seeders\AccessSeeder;

beforeEach(function () {
    $this->seed(AccessSeeder::class);
    $this->owner = User::factory()->create();
    $this->owner->assignRole(Access::OWNER);
    $this->sms = PaymentSms::create(['provider' => 'bkash', 'sender' => 'bKash', 'body' => 'You have received Tk 500.00', 'amount' => 50000, 'trx_id' => 'T1', 'status' => 'unmatched', 'received_at' => now()]);
    $this->other = PaymentSms::create(['provider' => 'bkash', 'sender' => 'bKash', 'body' => 'You have received Tk 700.00', 'amount' => 70000, 'trx_id' => 'T2', 'status' => 'matched', 'received_at' => now()]);
    $this->note = CardEvent::create(['package' => 'com.nsave.app', 'app_name' => 'NSave', 'body' => 'Paid $1.00 at Elevenlabs.io', 'hash' => 'h1', 'posted_at' => now(), 'kind' => 'charge', 'amount' => 100, 'currency' => 'USD', 'status' => 'booked']);
});

it('shows the money SMS and the card apps\' notifications on the web', function () {
    $this->actingAs($this->owner)->get('/admin/sms')->assertOk()
        ->assertInertia(fn ($page) => $page->component('admin/Sms')->where('kind', 'sms')->has('sms', 2));
    $this->actingAs($this->owner)->get('/admin/sms?kind=app')->assertOk()
        ->assertInertia(fn ($page) => $page->where('kind', 'app')->has('notes', 1)->where('notes.0.app', 'NSave'));
});

it('clears one line, or the whole list, from view while the record stays', function () {
    $this->actingAs($this->owner)->post("/admin/sms/sms/{$this->sms->id}/clear")->assertRedirect();
    $this->actingAs($this->owner)->get('/admin/sms')->assertInertia(fn ($page) => $page->has('sms', 1));
    expect(PaymentSms::find($this->sms->id))->not->toBeNull();

    $this->actingAs($this->owner)->post('/admin/sms/clear', ['kind' => 'app'])->assertRedirect();
    $this->actingAs($this->owner)->get('/admin/sms?kind=app')->assertInertia(fn ($page) => $page->has('notes', 0));
    expect($this->note->fresh()->status)->toBe('booked');
});

it('lets only the Owner see or clear them', function () {
    $team = User::factory()->create();
    $team->assignRole('Accountant');

    $this->actingAs($team)->get('/admin/sms')->assertForbidden();
    $this->actingAs($team)->post("/admin/sms/sms/{$this->sms->id}/clear")->assertForbidden();
    expect($this->sms->fresh()->cleared_at)->toBeNull();
});
