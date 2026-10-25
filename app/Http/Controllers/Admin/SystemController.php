<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\AppReleaseController;
use App\Http\Controllers\Controller;
use App\Models\AppDevice;
use App\Models\Setting;
use App\Services\CampaignBilling;
use App\Services\CampaignLimits;
use App\Services\KeepAlive;
use App\Services\MetaReceipts;
use App\Services\PilotClient;
use App\Services\Ticker;
use App\Support\Access;
use App\Support\MailSettings;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * Settings → System: every job that runs by itself, when it last ran, and a button to run it
 * now — so the Owner sees the whole machine and never needs the server.
 */
class SystemController extends Controller
{
    public function index(Request $request, KeepAlive $keepAlive): Response
    {
        abort_unless($request->user()->hasRole(Access::OWNER), 403);

        $phone = AppDevice::where('sms_forwarder', true)->latest('last_seen_at')->first();
        $errorsToday = 0;
        foreach (File::glob(storage_path('logs/laravel-'.now()->toDateString().'.log')) as $file) {
            $errorsToday += substr_count((string) File::get($file), '.ERROR:');
        }

        return Inertia::render('admin/System', [
            'timer' => [
                'on' => KeepAlive::enabled(),
                'every_seconds' => KeepAlive::EVERY_SECONDS,
                'lifetime_minutes' => intdiv(KeepAlive::LIFETIME_SECONDS, 60),
                'running' => $keepAlive->running(),
                'beat' => $keepAlive->lastBeat()?->toIso8601String(),
                'tick' => Setting::get('last_tick_at'),
            ],
            'pilot' => [
                'connected' => PilotClient::connected(),
                'url' => PilotClient::url(),
                'spend_synced' => Setting::get('pilot_spend_synced_at'),
                'every_minutes' => CampaignBilling::SYNC_MINUTES,
                'failures' => (int) Cache::get('pilot:failures', 0),
                'guard' => CampaignLimits::enabled(),
                'guard_error' => Setting::get('credit_guard_error'),
            ],
            'receipts' => [
                'mailbox' => MetaReceipts::configured(),
                'checked' => Setting::get('meta_receipts_checked_at'),
                'every_minutes' => MetaReceipts::EVERY_MINUTES,
                'charges_every_minutes' => MetaReceipts::CHARGES_EVERY_MINUTES,
                'charges_since' => Setting::get('meta_charges_since'),
                'cards' => MetaReceipts::ourCards(),
            ],
            'sms' => [
                'phone' => $phone ? ['version' => $phone->app_version, 'seen' => $phone->last_seen_at?->toIso8601String(), 'sent' => $phone->last_sent_at?->toIso8601String()] : null,
                'last_sms' => Setting::get('sms_last_seen'),
                'silent_minutes' => Ticker::SMS_SILENT_HOURS * 60,
            ],
            'mail' => ['ready' => MailSettings::ready()],
            'app' => ['plain' => AppReleaseController::versionName('plain'), 'sms' => AppReleaseController::versionName('sms')],
            'errors_today' => $errorsToday,
            'now' => CarbonImmutable::now()->toIso8601String(),
        ]);
    }

    /** One job, now: the tick, the spend sync, the receipts, or the timer itself. */
    public function run(Request $request, string $job, KeepAlive $keepAlive): RedirectResponse
    {
        abort_unless($request->user()->hasRole(Access::OWNER), 403);
        abort_unless(in_array($job, ['tick', 'spend', 'receipts', 'timer'], true), 404);

        try {
            match ($job) {
                'tick' => app(Ticker::class)->run(),
                'spend' => (function () {
                    Cache::forget('pilot:spend-sync');
                    app(CampaignBilling::class)->sync();
                    app(CampaignLimits::class)->guard();
                })(),
                'receipts' => (function () {
                    app(MetaReceipts::class)->check();
                    Cache::forget('meta-charges:pilot');
                    app(MetaReceipts::class)->fromPilotIfDue();
                })(),
                'timer' => (function () use ($keepAlive) {
                    Cache::forget('keep-alive:beat');
                    Cache::forget('keep-alive:starting');
                    $keepAlive->start(force: true);
                })(),
                default => abort(404),
            };
        } catch (RuntimeException $e) {
            $this->toast($e->getMessage(), 'error');

            return back();
        }

        $this->toast(match ($job) {
            'tick' => __('সব reminder আর হিসাব এখনই চালানো হলো।'),
            'spend' => __('Pilot থেকে খরচ আনা হলো।'),
            'receipts' => __('Meta receipt আর card charge দেখা হলো।'),
            default => __('Timer আবার চালু করা হলো।'),
        });

        return back();
    }
}
