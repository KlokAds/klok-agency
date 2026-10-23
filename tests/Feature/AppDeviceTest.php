<?php

use App\Http\Controllers\Invoices\SmsHookController;
use App\Models\AppDevice;
use App\Models\Setting;
use App\Models\User;
use App\Support\Access;
use Database\Seeders\AccessSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

const PHONE_TOKEN = 'fcm-token-of-the-owners-phone-0123456789';

beforeEach(function () {
    $this->seed(AccessSeeder::class);

    $this->owner = User::factory()->create();
    $this->owner->assignRole(Access::OWNER);
    $this->accountant = User::factory()->create();
    $this->accountant->assignRole('Accountant');
});

test('the app hands over its token under whoever is signed in', function () {
    $this->actingAs($this->accountant)->postJson(route('app.device'), ['token' => PHONE_TOKEN, 'version' => 3])->assertNoContent();
    $this->actingAs($this->owner)->postJson(route('app.device'), ['token' => PHONE_TOKEN, 'version' => 3])->assertNoContent();

    expect(AppDevice::count())->toBe(1)
        ->and(AppDevice::first()->user_id)->toBe($this->owner->id);
});

test('only the owner can make a phone the SMS forwarder', function () {
    $this->actingAs($this->accountant)->postJson(route('app.device'), ['token' => PHONE_TOKEN]);
    $this->actingAs($this->accountant)->postJson(route('app.sms-forwarder'), ['token' => PHONE_TOKEN])->assertForbidden();
    expect(Setting::get('sms_device_token'))->toBeNull();

    AppDevice::query()->delete();
    $this->actingAs($this->owner)->postJson(route('app.device'), ['token' => PHONE_TOKEN]);
    $url = $this->actingAs($this->owner)->postJson(route('app.sms-forwarder'), ['token' => PHONE_TOKEN])->assertOk()->json('url');

    expect(AppDevice::first()->sms_forwarder)->toBeTrue();

    // The URL it got works; an SMS from an ordinary number is not money.
    $this->postJson($url, ['from' => '+8801712345678', 'text' => 'You have received Tk 500.00 from 01712345678. TrxID FAKE123456'])
        ->assertJson(['status' => 'ignored']);
});

test('a payment SMS no invoice takes tells the owners', function () {
    Setting::put('sms_device_token', Hash::make('device-secret-0123456789'));

    $this->postJson(route('hooks.sms', 'device-secret-0123456789'), [
        'from' => 'bKash',
        'text' => 'You have received Tk 777.00 from 01712345678. TrxID NOMATCH777 at 20/10/2026 14:23',
    ])->assertOk();

    expect($this->owner->notifications()->count())->toBe(1)
        ->and($this->owner->notifications()->first()->data['title'] ?? null)->toBe(SmsHookController::UNMATCHED_TITLE)
        ->and($this->accountant->notifications()->count())->toBe(0);
});

test('signing in in one place signs that person out everywhere else', function () {
    $this->owner->forceFill(['remember_token' => 'old-remember-token'])->save();
    DB::table('sessions')->insert([
        'id' => 'the-other-browser', 'user_id' => $this->owner->id, 'ip_address' => '127.0.0.1',
        'user_agent' => 'x', 'payload' => '', 'last_activity' => time(),
    ]);

    $this->post(route('login.store'), ['email' => $this->owner->email, 'password' => 'password'])->assertRedirect();

    expect(DB::table('sessions')->where('id', 'the-other-browser')->exists())->toBeFalse()
        ->and($this->owner->fresh()->remember_token)->not->toBe('old-remember-token');
});
