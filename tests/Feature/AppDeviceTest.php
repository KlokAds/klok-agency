<?php

use App\Http\Controllers\Invoices\SmsHookController;
use App\Models\AppDevice;
use App\Models\Setting;
use App\Models\User;
use App\Services\AppPush;
use App\Support\Access;
use Database\Seeders\AccessSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

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

test('the app settings page shows the phones and keeps the Firebase key encrypted', function () {
    AppDevice::create(['user_id' => $this->owner->id, 'token_hash' => hash('sha256', PHONE_TOKEN), 'token' => PHONE_TOKEN, 'device' => 'Mozilla/5.0 (Linux; Android 14; SM-A155F)', 'app_version' => 1]);

    $this->actingAs($this->owner)->get('/admin/app')->assertOk()
        ->assertInertia(fn ($page) => $page->component('admin/App')->has('devices', 1)->where('firebase', null));

    $this->actingAs($this->owner)->put('/admin/app/firebase', ['key' => '{"type":"nope"}'])->assertSessionHasErrors('key');

    $key = json_encode(['type' => 'service_account', 'project_id' => 'p1', 'client_email' => 'a@p1.iam.gserviceaccount.com', 'private_key' => 'k']);
    $this->actingAs($this->owner)->put('/admin/app/firebase', ['key' => $key])->assertRedirect();

    expect(Setting::get(AppPush::SETTING))->not->toContain('service_account')
        ->and(app(AppPush::class)->account()['project_id'])->toBe('p1');

    $this->actingAs($this->accountant)->put('/admin/app/firebase', ['key' => $key])->assertForbidden();
});

test('a test notification goes to the phones of whoever asks', function () {
    Http::fake(['oauth2.googleapis.com/*' => Http::response(['access_token' => 'tok']), 'fcm.googleapis.com/*' => Http::response(['name' => 'm1'])]);
    Cache::put('fcm.access_token', 'tok', 600);
    $pem = 'k';
    Setting::put(AppPush::SETTING, Crypt::encryptString(json_encode(['project_id' => 'p1', 'client_email' => 'a@p1', 'private_key' => $pem])));
    AppDevice::create(['user_id' => $this->owner->id, 'token_hash' => hash('sha256', PHONE_TOKEN), 'token' => PHONE_TOKEN, 'app_version' => 1]);

    $this->actingAs($this->owner)->post('/admin/app/test', ['urgent' => true])->assertRedirect();

    Http::assertSent(fn ($r) => str_contains($r->url(), 'fcm.googleapis.com')
        && $r['message']['token'] === PHONE_TOKEN
        && $r['message']['android']['notification']['channel_id'] === 'finance_urgent'
        && $r['message']['android']['notification']['sound'] === 'default');
});

test('signing out in the app stops that phone, but never the SMS phone', function () {
    AppDevice::create(['user_id' => $this->owner->id, 'token_hash' => hash('sha256', PHONE_TOKEN), 'token' => PHONE_TOKEN, 'app_version' => 1]);
    $this->postJson(route('app.device.forget'), ['token' => PHONE_TOKEN])->assertNoContent();
    expect(AppDevice::count())->toBe(0);

    AppDevice::create(['user_id' => $this->owner->id, 'token_hash' => hash('sha256', PHONE_TOKEN), 'token' => PHONE_TOKEN, 'app_version' => 1, 'sms_forwarder' => true]);
    $this->postJson(route('app.device.forget'), ['token' => PHONE_TOKEN])->assertNoContent();
    expect(AppDevice::count())->toBe(1);
});

test('GitHub hands over a new APK and the app finds it', function () {
    Storage::fake('local');
    Setting::put('app_release_token', Hash::make('release-secret-0123456789'));

    $this->post(route('hooks.app-release', 'wrong'), [])->assertNotFound();
    $this->post(route('hooks.app-release', 'release-secret-0123456789'), [
        'version' => 2, 'name' => '1.0.2', 'apk' => UploadedFile::fake()->create('app.apk', 100),
    ])->assertOk();

    $this->actingAs($this->accountant)->getJson(route('app.latest'))->assertJson(['version' => 2, 'name' => '1.0.2']);
    $url = $this->actingAs($this->accountant)->getJson(route('app.latest'))->json('url');
    $this->get($url)->assertOk();
    $this->get(route('app.download'))->assertForbidden();

    // The login page offers it to anyone.
    auth()->logout();
    $this->get('/login')->assertInertia(fn ($page) => $page->where('app', '1.0.2'));
    $this->get(route('app.get'))->assertRedirect();
});
