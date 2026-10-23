<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The Android app's own updates. The pages inside the app come from the
 * site, so they are always new; only a change to the phone side (SMS,
 * lock, notifications) needs a new APK. GitHub builds it and sends it here,
 * the app asks once a day whether there is a newer one, and offers it.
 */
class AppReleaseController extends Controller
{
    public const FILE = 'app/klok-finance.apk';

    /** From the GitHub build: the new APK and its version number. */
    public function upload(Request $request, string $token): JsonResponse
    {
        $hash = (string) Setting::get('app_release_token');
        abort_unless($hash !== '' && Hash::check($token, $hash), 404);

        $data = $request->validate([
            'version' => ['required', 'integer', 'min:1', 'max:65000'],
            'name' => ['required', 'string', 'max:30'],
            'apk' => ['required', 'file', 'max:204800'],
        ]);

        Storage::disk('local')->putFileAs('app', $data['apk'], 'klok-finance.apk');
        Setting::put('app_version', (string) $data['version']);
        Setting::put('app_version_name', $data['name']);
        Setting::put('app_released_at', now()->toIso8601String());
        activity('Setting')->log('app '.$data['name'].' uploaded');

        return response()->json(['ok' => true]);
    }

    /** What the app asks: is there a newer APK than mine? */
    public function latest(): JsonResponse
    {
        $version = (int) Setting::get('app_version', 0);

        return response()->json([
            'version' => $version,
            'name' => Setting::get('app_version_name'),
            'url' => self::downloadUrl(),
        ]);
    }

    /** A link that works for half an hour, also in the phone's browser (which is not signed in). */
    public static function downloadUrl(): ?string
    {
        return (int) Setting::get('app_version', 0) > 0 && Storage::disk('local')->exists(self::FILE)
            ? URL::temporarySignedRoute('app.download', now()->addMinutes(30))
            : null;
    }

    /** The login page's button: anyone may have the app; it is useless without signing in. */
    public function get(): RedirectResponse
    {
        $url = self::downloadUrl();
        abort_if($url === null, 404);

        return redirect()->away($url);
    }

    public function download(): StreamedResponse
    {
        abort_unless(Storage::disk('local')->exists(self::FILE), 404);

        return Storage::disk('local')->download(self::FILE, 'klok-finance.apk', [
            'Content-Type' => 'application/vnd.android.package-archive',
        ]);
    }
}
