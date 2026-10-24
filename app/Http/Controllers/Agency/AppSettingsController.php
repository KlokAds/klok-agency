<?php

namespace App\Http\Controllers\Agency;

use App\Http\Controllers\AppReleaseController;
use App\Http\Controllers\Controller;
use App\Models\AppDevice;
use App\Models\Setting;
use App\Services\AppPush;
use App\Services\Ticker;
use App\Support\Access;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Settings › App: the Firebase key notifications go out with, the phones
 * running the app, a test ring, and when the card counts as low.
 */
class AppSettingsController extends Controller
{
    public function index(Request $request, AppPush $push): Response
    {
        $account = $push->account();
        $owner = $request->user()->hasRole(Access::OWNER);

        return Inertia::render('admin/App', [
            'firebase' => $account ? ['project' => $account['project_id'], 'email' => $account['client_email']] : null,
            'cardLow' => (string) (Setting::get('card_low_usd') ?: Ticker::CARD_LOW_DEFAULT),
            // The plain app for everyone; the one that forwards payment SMS only for the Owner.
            'releases' => collect(AppReleaseController::FLAVORS)
                ->reject(fn (string $flavor) => $flavor === 'sms' && ! $owner)
                ->map(fn (string $flavor) => [
                    'flavor' => $flavor,
                    'version' => AppReleaseController::versionName($flavor),
                    'at' => Setting::get($flavor === 'sms' ? 'app_sms_released_at' : 'app_released_at'),
                    'url' => AppReleaseController::downloadUrl($flavor),
                ])->values(),
            'devices' => AppDevice::with('user:id,name')->latest('last_seen_at')->get()
                ->map(fn (AppDevice $d) => [
                    'id' => $d->id,
                    'user' => $d->user?->name,
                    'device' => $d->device,
                    'version' => $d->app_version,
                    'sms' => $d->sms_forwarder,
                    'failures' => $d->failures,
                    'last_seen_at' => $d->last_seen_at?->toIso8601String(),
                    'last_sent_at' => $d->last_sent_at?->toIso8601String(),
                ])->values(),
            'smsLastSeen' => Setting::get('sms_last_seen'),
        ]);
    }

    /** The Firebase service-account key (JSON), kept encrypted; empty removes it. */
    public function firebase(Request $request): RedirectResponse
    {
        $data = $request->validate(['key' => ['nullable', 'string', 'max:10000']]);
        $key = trim((string) ($data['key'] ?? ''));

        if ($key === '') {
            Setting::put(AppPush::SETTING, null);
            $this->toast(__('Firebase key সরানো হয়েছে।'));

            return back();
        }

        $json = json_decode($key, true);
        if (! is_array($json) || ($json['type'] ?? null) !== 'service_account' || ! isset($json['project_id'], $json['client_email'], $json['private_key'])) {
            throw ValidationException::withMessages(['key' => __('এটা Firebase-এর service account JSON নয়।')]);
        }

        Setting::put(AppPush::SETTING, Crypt::encryptString((string) json_encode([
            'project_id' => $json['project_id'],
            'client_email' => $json['client_email'],
            'private_key' => $json['private_key'],
        ])));
        activity('Setting')->causedBy($request->user())->log('firebase key set for '.$json['project_id']);
        $this->toast(__('Firebase key রাখা হয়েছে।'));

        return back();
    }

    public function cardLow(Request $request): RedirectResponse
    {
        $data = $request->validate(['card_low_usd' => ['required', 'numeric', 'min:0', 'max:100000']]);
        Setting::put('card_low_usd', (string) $data['card_low_usd']);
        $this->toast(__('সংরক্ষণ হয়েছে।'));

        return back();
    }

    /** Rings every phone of the person asking, the way a real alert would. */
    public function test(Request $request, AppPush $push): RedirectResponse
    {
        $devices = AppDevice::where('user_id', $request->user()->id)->get();

        if (! $push->ready()) {
            $this->toast(__('আগে Firebase key দিন।'), 'error');
        } elseif ($devices->isEmpty()) {
            $this->toast(__('আপনার কোনো phone-এ app নেই।'), 'error');
        } else {
            $reached = $push->send($devices, [
                'title' => __('KlokOne — test notification'),
                'body' => __('শব্দ হলো? তাহলে সব ঠিক আছে।'),
                'level' => $request->boolean('urgent') ? 'urgent' : 'normal',
                'url' => '/admin/app',
                'tag' => 'test',
            ]);
            $this->toast(__(':n টা phone-এ পাঠানো হলো।', ['n' => $reached]), $reached > 0 ? 'success' : 'error');
        }

        return back();
    }

    public function destroyDevice(Request $request, AppDevice $device): RedirectResponse
    {
        if ($device->sms_forwarder) {
            // That phone no longer forwards: its secret URL stops working.
            Setting::put('sms_device_token', null);
        }
        $device->delete();
        activity('Setting')->causedBy($request->user())->log('app phone removed');
        $this->toast(__('Phone সরানো হয়েছে।'));

        return back();
    }
}
