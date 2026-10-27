<?php

namespace App\Http\Controllers\Agency;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Services\DailySummary;
use App\Services\PilotClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * Settings → Reminders & Pilot: when the daily summary goes out, the
 * Klok Pilot connection, and the secret URL a pinger calls.
 */
class AgencySettingsController extends Controller
{
    public function index(): Response
    {
        $token = (string) Setting::get('tick_token');

        return Inertia::render('admin/Agency', [
            'settings' => [
                'summary_time' => (string) (Setting::get('summary_time') ?: '21:00'),
                'pilot_url' => PilotClient::url(),
                'pilot_connected' => PilotClient::connected(),
                'credit_guard' => Setting::get('credit_guard', '1') === '1',
                'credit_guard_error' => Setting::get('credit_guard_error'),
            ],
            'tick' => [
                'url' => $token !== '' ? route('agency.tick', $token) : null,
                'last' => Setting::get('last_tick_at'),
            ],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'summary_time' => ['required', 'date_format:H:i'],
            'pilot_url' => ['required', 'url:https', 'max:255'],
            'credit_guard' => ['required', 'boolean'],
        ]);

        // The agency token goes to this URL on every call, so it may only be Klok Pilot's
        // own host (any host while building locally).
        $host = strtolower((string) parse_url($data['pilot_url'], PHP_URL_HOST));
        if (! app()->isLocal() && $host !== 'klokads.com' && ! str_ends_with($host, '.klokads.com')) {
            throw ValidationException::withMessages(['pilot_url' => __('Klok Pilot-এর URL klokads.com-এর হতে হবে।')]);
        }

        // A new URL drops the saved token: it was given for the old one, and is typed
        // again for the new one, so whoever changes the URL cannot send it elsewhere.
        $moved = rtrim($data['pilot_url'], '/') !== rtrim(PilotClient::url(), '/') && PilotClient::connected();

        Setting::put('summary_time', $data['summary_time']);
        Setting::put('pilot_url', $data['pilot_url']);
        Setting::put('credit_guard', $data['credit_guard'] ? '1' : '0');
        if ($moved) {
            Setting::put('pilot_token', null);
        }
        $this->toast($moved ? __('সংরক্ষণ হয়েছে। Pilot-এর URL বদলেছে, তাই token মুছে দেওয়া হয়েছে — নতুন URL-এর token আবার দিন।') : __('সংরক্ষণ হয়েছে।'));

        return back();
    }

    /** The Pilot token is kept encrypted and never sent back to the browser. */
    public function pilotToken(Request $request): RedirectResponse
    {
        $data = $request->validate(['pilot_token' => ['nullable', 'string', 'max:2000']]);

        Setting::put('pilot_token', filled($data['pilot_token']) ? Crypt::encryptString(trim($data['pilot_token'])) : null);
        $this->toast(filled($data['pilot_token']) ? __('Klok Pilot-এর token রাখা হয়েছে (encrypted)।') : __('Klok Pilot-এর token মুছে দেওয়া হয়েছে।'));

        return back();
    }

    /** Tries the connection and lists the ad accounts the token can see. */
    public function pilotTest(PilotClient $pilot): JsonResponse
    {
        try {
            return response()->json(['ok' => true, 'accounts' => $pilot->adAccounts()]);
        } catch (RuntimeException $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()]);
        }
    }

    public function tickToken(): RedirectResponse
    {
        Setting::put('tick_token', Str::random(40));
        $this->toast(__('নতুন reminder URL তৈরি হয়েছে — cron-job.org-এ এটাই বসান।'));

        return back();
    }

    public function summaryNow(DailySummary $summary): RedirectResponse
    {
        $count = $summary->send();
        $this->toast(__('আজকের সারসংক্ষেপ :n জনকে পাঠানো হলো।', ['n' => $count]));

        return back();
    }
}
