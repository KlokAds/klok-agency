<?php

use App\Models\User;
use App\Support\Access;
use Database\Seeders\AccessSeeder;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * The phone app's lock and the site's idle sign-out (review findings 19, 36, 50, 53). The app
 * side is Dart / Kotlin / a build script with no test runner here, so those are checked in
 * their source: a regression there would quietly reopen the hole.
 */

function appSource(string $path): string
{
    return (string) file_get_contents(base_path($path));
}

test('inside the app the page still gets the idle limit, and an idle sign-out works', function () {
    $this->seed(AccessSeeder::class);
    $owner = User::factory()->create();
    $owner->assignRole(Access::OWNER);
    $app = ['User-Agent' => 'Mozilla/5.0 (Linux; Android 14) KlokFinanceApp/99'];

    $this->actingAs($owner)->withHeaders($app)->get('/dashboard')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('idleMinutes', 30));

    $this->withHeaders($app)->post('/logout')->assertRedirect();
    $this->assertGuest();
});

test('idle sign-out clears the kept pages and skips only when the app says its lock is on', function () {
    $idle = appSource('resources/js/composables/useIdleLogout.ts');

    expect($idle)
        ->toContain("import { forgetPages } from '@/lib/offline'")
        ->toContain('forgetPages();')
        ->toContain("callHandler('appLock')")
        // An app from before the handler (answers null) keeps the old way until the phone updates.
        ->toContain('return answer === true || answer === null || answer === undefined;')
        // No blanket skip for the app's user agent before the timer starts.
        ->not->toMatch('/KlokFinanceApp\/\.test\(navigator\.userAgent\)\)\s*\{\s*return;/');

    $dart = appSource('mobile/lib/main.dart');

    expect($dart)
        ->toContain("addJavaScriptHandler(handlerName: 'appLock'")
        ->toContain('await ours() && await Lock.isOn()');
});

test('the fingerprint lock does not open by itself when the sensor is unavailable', function () {
    $dart = appSource('mobile/lib/main.dart');

    // NotAvailable used to be a pass; now only a phone with no screen lock gets in without a prompt.
    expect($dart)
        ->not->toContain("return e.code == 'NotAvailable'")
        ->toContain('return !await phoneLocked() || await phonePin(reason);')
        ->toContain("invokeMethod<bool>('deviceSecure') ?? true");

    expect(appSource('mobile/android-src/MainActivity.kt'))
        ->toContain('"deviceSecure" ->')
        ->toContain('keyguard.isDeviceSecure');
});

test('backups and phone-to-phone moves leave out the whole app data folder', function () {
    $patch = appSource('mobile/tool/patch_android.py');

    expect($patch)
        ->toContain("for d in ('root', 'sharedpref', 'database', 'file', 'device_root')")
        ->toContain('<cloud-backup>{nothing}</cloud-backup>')
        ->toContain('<device-transfer>{nothing}</device-transfer>')
        ->toContain('android:allowBackup="false"');
});
