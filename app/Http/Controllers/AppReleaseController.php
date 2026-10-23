<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Support\Access;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The Android app's own updates. The pages inside the app come from the
 * site, so they are always new; only a change to the phone side (SMS,
 * lock, notifications) needs a new APK. GitHub builds it and sends it here,
 * the app asks once a day whether there is a newer one, and offers it.
 *
 * Two kinds, one code: "plain" for everyone (the login page offers it), and
 * "sms" — the same app that also forwards payment SMS — only for the
 * Owner's phone. Android's Play Protect blocks any downloaded app that
 * reads SMS, so the people who need no SMS get an app without it.
 */
class AppReleaseController extends Controller
{
    public const FLAVORS = ['plain', 'sms'];

    public static function file(string $flavor): string
    {
        return $flavor === 'sms' ? 'app/klok-finance-sms.apk' : 'app/klok-finance.apk';
    }

    private static function key(string $flavor, string $what): string
    {
        return $flavor === 'sms' ? "app_sms_$what" : "app_$what";
    }

    public static function version(string $flavor): int
    {
        return (int) Setting::get(self::key($flavor, 'version'), 0);
    }

    public static function versionName(string $flavor): ?string
    {
        $name = Setting::get(self::key($flavor, 'version_name'));

        return is_string($name) ? $name : null;
    }

    /** From the GitHub build: one kind of APK and its version number. */
    public function upload(Request $request, string $token): JsonResponse
    {
        $hash = (string) Setting::get('app_release_token');
        abort_unless($hash !== '' && Hash::check($token, $hash), 404);

        $data = $request->validate([
            'flavor' => ['nullable', Rule::in(self::FLAVORS)],
            'version' => ['required', 'integer', 'min:1', 'max:65000'],
            'name' => ['required', 'string', 'max:30'],
            'apk' => ['required', 'file', 'max:204800'],
        ]);
        $flavor = $data['flavor'] ?? 'plain';

        Storage::disk('local')->putFileAs('app', $data['apk'], basename(self::file($flavor)));
        Setting::put(self::key($flavor, 'version'), (string) $data['version']);
        Setting::put(self::key($flavor, 'version_name'), $data['name']);
        Setting::put(self::key($flavor, 'released_at'), now()->toIso8601String());
        activity('Setting')->log("app {$data['name']} ($flavor) uploaded");

        return response()->json(['ok' => true]);
    }

    /**
     * What the app asks: is there a newer APK of my kind? The first app
     * (1.0.1) asks without saying its kind; it was the SMS one.
     */
    public function latest(Request $request): JsonResponse
    {
        $flavor = in_array($request->query('flavor'), self::FLAVORS, true) ? (string) $request->query('flavor') : 'sms';

        return response()->json([
            'version' => self::version($flavor),
            'name' => self::versionName($flavor),
            'url' => self::downloadUrl($flavor),
            // The SMS app turns forwarding on by itself only for the Owner.
            'owner' => (bool) $request->user()?->hasRole(Access::OWNER),
        ]);
    }

    /** A link that works for half an hour, also in the phone's browser (which is not signed in). */
    public static function downloadUrl(string $flavor = 'plain'): ?string
    {
        return self::version($flavor) > 0 && Storage::disk('local')->exists(self::file($flavor))
            ? URL::temporarySignedRoute('app.download', now()->addMinutes(30), ['flavor' => $flavor])
            : null;
    }

    /** The login page's button: the plain app; anyone may have it, it is useless without signing in. */
    public function get(): RedirectResponse
    {
        $url = self::downloadUrl('plain');
        abort_if($url === null, 404);

        return redirect()->away($url);
    }

    public function download(Request $request): StreamedResponse
    {
        $flavor = $request->query('flavor') === 'sms' ? 'sms' : 'plain';
        abort_unless(Storage::disk('local')->exists(self::file($flavor)), 404);

        return Storage::disk('local')->download(self::file($flavor), basename(self::file($flavor)), [
            'Content-Type' => 'application/vnd.android.package-archive',
        ]);
    }
}
