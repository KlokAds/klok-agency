<?php

namespace App\Http\Controllers;

use App\Models\AppDevice;
use App\Models\Setting;
use App\Payments\PaySettings;
use App\Support\Access;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * What the Klok Finance Android app tells the server: this phone's Firebase
 * token under whoever is signed in (so their notifications reach it), and —
 * on the Owner's phone holding the agency's wallet SIM — that it forwards
 * the payment SMS. Clients never run this app: they pay on the invoice page.
 */
class AppDeviceController extends Controller
{
    /** The app hands over its token after each sign-in; the phone follows whoever signs in. */
    public function store(Request $request): Response
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'min:20', 'max:4096'],
            'version' => ['nullable', 'integer', 'min:0', 'max:65000'],
        ]);

        AppDevice::updateOrCreate(['token_hash' => hash('sha256', $data['token'])], [
            'user_id' => $request->user()->id,
            'token' => $data['token'],
            'device' => mb_substr((string) $request->userAgent(), 0, 250),
            'app_version' => (int) ($data['version'] ?? 0),
            'failures' => 0,
            'last_seen_at' => now(),
        ]);

        return response()->noContent();
    }

    /**
     * Signing out in the app: this phone stops getting that person's
     * notifications. The token itself is the proof; no session is needed.
     */
    public function forget(Request $request): Response
    {
        $data = $request->validate(['token' => ['required', 'string', 'min:20', 'max:4096']]);
        AppDevice::where('token_hash', hash('sha256', $data['token']))->where('sms_forwarder', false)->delete();

        return response()->noContent();
    }

    /**
     * Makes this phone the SMS forwarder: a fresh secret URL the app keeps
     * and posts each wallet SMS to (any older forwarder stops at once).
     */
    public function smsForwarder(Request $request): JsonResponse
    {
        // Only the Owner's own phone, the one holding the agency's wallet SIM.
        abort_unless($request->user()->hasRole(Access::OWNER), 403);

        $data = $request->validate(['token' => ['required', 'string', 'min:20', 'max:4096']]);
        $device = AppDevice::where('token_hash', hash('sha256', $data['token']))->where('user_id', $request->user()->id)->firstOrFail();

        $secret = Str::random(40);
        Setting::put('sms_device_token', Hash::make($secret));
        Setting::putQuietly('sms_last_seen', null);
        AppDevice::where('sms_forwarder', true)->update(['sms_forwarder' => false]);
        $device->forceFill(['sms_forwarder' => true])->save();
        activity('Setting')->causedBy($request->user())->log('sms forwarder set to the app on '.($device->device ?? 'a phone'));

        // The phone forwards only these senders; everyone else's SMS never leave it.
        return response()->json([
            'url' => route('hooks.sms', $secret),
            'senders' => PaySettings::senderForms(),
        ]);
    }
}
