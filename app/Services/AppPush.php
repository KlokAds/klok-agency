<?php

namespace App\Services;

use App\Models\AppDevice;
use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Notifications to KlokOne's Android app through Firebase Cloud
 * Messaging, the way chat apps get theirs. Every one rings: urgent ones pop
 * up on the screen with a loud sound, the rest come with the usual sound.
 * Firebase keeps a message for a week, so a phone that was off or without
 * internet still gets it when it is back; the bell keeps every one besides.
 * The Firebase service account (a JSON key) is kept encrypted in settings.
 */
class AppPush
{
    public const SETTING = 'fcm_service_account';

    /** Android channels the app makes: both ring; urgent pops up over everything. */
    public const CHANNELS = ['urgent' => 'finance_urgent', 'normal' => 'finance_normal'];

    /** How long Firebase keeps a message for a phone that is off. */
    public const TTL_SECONDS = 7 * 24 * 3600;

    /** @return array{project_id: string, client_email: string, private_key: string}|null */
    public function account(): ?array
    {
        $stored = Setting::get(self::SETTING);

        try {
            $data = $stored ? json_decode(Crypt::decryptString($stored), true) : null;
        } catch (Throwable) {
            return null;
        }

        return is_array($data) && isset($data['project_id'], $data['client_email'], $data['private_key']) ? $data : null;
    }

    public function ready(): bool
    {
        return $this->account() !== null;
    }

    /**
     * @param  iterable<AppDevice>  $devices
     * @param  array{title: string, body?: ?string, url?: ?string, level?: string, tag?: ?string, kind?: ?string}  $payload
     * @return int phones reached
     */
    public function send(iterable $devices, array $payload): int
    {
        $account = $this->account();
        $token = $account ? $this->accessToken($account) : null;

        if ($account === null || $token === null) {
            return 0;
        }

        $level = ($payload['level'] ?? 'normal') === 'urgent' ? 'urgent' : 'normal';
        $data = array_map('strval', array_filter([
            'url' => $payload['url'] ?? null,
            'kind' => $payload['kind'] ?? null,
            'level' => $level,
            'tag' => $payload['tag'] ?? null,
        ], fn ($v) => $v !== null && $v !== ''));
        $reached = 0;

        foreach ($devices as $device) {
            try {
                $response = Http::withToken($token)->timeout(10)->post(
                    "https://fcm.googleapis.com/v1/projects/{$account['project_id']}/messages:send",
                    ['message' => [
                        'token' => $device->token,
                        // A notification message: Android shows it itself, even with the app closed.
                        'notification' => ['title' => $payload['title'], 'body' => (string) ($payload['body'] ?? '')],
                        'data' => $data,
                        'android' => [
                            'priority' => 'high',
                            'ttl' => self::TTL_SECONDS.'s',
                            'notification' => array_filter([
                                'channel_id' => self::CHANNELS[$level],
                                'sound' => 'default',
                                'default_vibrate_timings' => true,
                                'notification_priority' => $level === 'urgent' ? 'PRIORITY_MAX' : 'PRIORITY_HIGH',
                                'visibility' => 'PRIVATE',
                                'tag' => $payload['tag'] ?? null,
                                'icon' => 'ic_notification',
                                'color' => '#0D9488',
                            ], fn ($v) => $v !== null),
                        ],
                    ]],
                );

                if ($response->successful()) {
                    $reached++;
                    $device->forceFill(['failures' => 0, 'last_sent_at' => now()])->save();
                } elseif (in_array($response->status(), [404, 410], true) || str_contains($response->body(), 'UNREGISTERED')) {
                    // The app was removed from that phone, or its token was replaced.
                    $device->delete();
                } else {
                    $device->increment('failures');
                    Log::warning('FCM send failed: '.$response->status().' '.mb_substr($response->body(), 0, 300));
                }
            } catch (Throwable $e) {
                Log::warning('FCM send failed: '.$e->getMessage());
            }
        }

        return $reached;
    }

    /**
     * A short-lived OAuth token from the service account (a signed JWT
     * exchanged at Google), kept for 50 minutes.
     *
     * @param  array{project_id: string, client_email: string, private_key: string}  $account
     */
    private function accessToken(array $account): ?string
    {
        $cached = Cache::get('fcm.access_token');

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $now = time();
        $b64 = fn (string $s): string => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
        $header = $b64((string) json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $claims = $b64((string) json_encode([
            'iss' => $account['client_email'],
            'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
            'aud' => 'https://oauth2.googleapis.com/token',
            'iat' => $now,
            'exp' => $now + 3600,
        ]));
        $signature = '';

        if (! openssl_sign("{$header}.{$claims}", $signature, $account['private_key'], OPENSSL_ALGO_SHA256)) {
            return null;
        }

        try {
            $response = Http::asForm()->timeout(10)->post('https://oauth2.googleapis.com/token', [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => "{$header}.{$claims}.".$b64($signature),
            ]);
        } catch (Throwable $e) {
            Log::warning('FCM token failed: '.$e->getMessage());

            return null;
        }

        $token = $response->successful() ? (string) $response->json('access_token') : null;

        if ($token) {
            Cache::put('fcm.access_token', $token, 3000);
        }

        return $token ?: null;
    }
}
