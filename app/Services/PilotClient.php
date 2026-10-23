<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Talks to Klok Pilot through its MCP endpoint with an agency token
 * (Pilot → Agents): reads ad accounts, their campaigns and spend, and sets
 * a prepaid campaign's end, spend cap and on/off.
 */
class PilotClient
{
    public static function url(): string
    {
        return (string) (Setting::get('pilot_url') ?: 'https://pilot.klokads.com/mcp');
    }

    public static function token(): ?string
    {
        $stored = Setting::get('pilot_token');

        try {
            return $stored ? Crypt::decryptString($stored) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    public static function connected(): bool
    {
        return self::token() !== null;
    }

    /**
     * Ad accounts the agency token reaches, across all its shops.
     *
     * @return list<array{id: int, name: string, external_id: string|null, platform: string|null, currency: string|null, shop: string|null}>
     */
    public function adAccounts(): array
    {
        $result = $this->call('list_ad_accounts');

        return array_values(array_map(fn ($a) => [
            'id' => (int) $a['ad_account_id'],
            'name' => (string) ($a['name'] ?? '#'.$a['ad_account_id']),
            'external_id' => $a['external_id'] ?? null,
            'platform' => $a['platform'] ?? null,
            'currency' => $a['currency'] ?? null,
            'shop' => $a['shop'] ?? null,
        ], $result['ad_accounts'] ?? []));
    }

    /**
     * One ad account's numbers over the last $days days (1 = today).
     *
     * @return array{currency?: string, from?: string, to?: string, last_synced_at?: string|null, totals?: array<string, mixed>, campaigns?: list<array<string, mixed>>}
     */
    public function performance(int $adAccountId, int $days = 1): array
    {
        return $this->call('ad_account_performance', ['ad_account_id' => $adAccountId, 'days' => $days]);
    }

    /**
     * Every campaign in one ad account, with its status and spend so far.
     *
     * @return array{currency?: string, last_synced_at?: string|null, campaigns?: list<array<string, mixed>>}
     */
    public function campaigns(int $adAccountId): array
    {
        return $this->call('ad_account_campaigns', ['ad_account_id' => $adAccountId]);
    }

    /**
     * Day-by-day spend per campaign in one ad account (at most 92 days).
     *
     * @param  array<int, int>  $campaignIds
     * @return array{currency?: string, rows?: list<array{date: string, campaign_id: int, spend: float|int, results?: int, clicks?: int}>}
     */
    public function dailySpend(int $adAccountId, string $from, string $to, array $campaignIds = []): array
    {
        return $this->call('campaign_daily_spend', array_filter([
            'ad_account_id' => $adAccountId, 'from' => $from, 'to' => $to, 'campaign_ids' => $campaignIds ?: null,
        ], fn ($v) => $v !== null));
    }

    /**
     * A Meta campaign's end, daily budget, spend cap and on/off; with
     * new_end, spend_cap or active it changes them (needs the token's
     * write:campaigns permission).
     *
     * @param  array{new_end?: string, spend_cap?: float, active?: bool, spent_before?: string, reason?: string}  $change
     * @return array{daily_budget?: float|null, spend_cap?: float|null, active?: bool, end?: string|null, extendable?: bool, why_not?: string|null, spend_cap_error?: string|null, spent_before?: float|null, currency?: string}
     */
    public function limits(int $adAccountId, int $campaignId, array $change = []): array
    {
        return $this->call('set_campaign_limits', ['ad_account_id' => $adAccountId, 'campaign_id' => $campaignId, ...$change]);
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function call(string $tool, array $arguments = []): array
    {
        $token = self::token();

        if (! $token) {
            throw new RuntimeException(__('Klok Pilot-এর token দেওয়া নেই — Settings-এ দিন।'));
        }

        $response = Http::timeout(30)
            ->withToken($token)
            ->accept('application/json, text/event-stream')
            ->post(self::url(), [
                'jsonrpc' => '2.0',
                'id' => random_int(1, PHP_INT_MAX),
                'method' => 'tools/call',
                // An empty argument list must still go out as a JSON object, not [].
                'params' => ['name' => $tool, 'arguments' => $arguments ?: new \stdClass],
            ]);

        if (! $response->successful()) {
            throw new RuntimeException('Klok Pilot: HTTP '.$response->status());
        }

        $body = $response->body();

        // Streamed replies come as server-sent events: the JSON is on the last "data:" line.
        if (str_contains((string) $response->header('Content-Type'), 'event-stream')) {
            preg_match_all('/^data:\s*(.+)$/m', $body, $m);
            $body = (string) end($m[1]);
        }

        $message = json_decode($body, true);

        if (isset($message['error'])) {
            throw new RuntimeException('Klok Pilot: '.($message['error']['message'] ?? 'error'));
        }

        $result = $message['result'] ?? [];

        if (($result['isError'] ?? false) === true) {
            throw new RuntimeException('Klok Pilot: '.($result['content'][0]['text'] ?? 'tool error'));
        }

        if (isset($result['structuredContent']) && is_array($result['structuredContent'])) {
            return $result['structuredContent'];
        }

        $text = $result['content'][0]['text'] ?? '{}';
        $data = json_decode($text, true);

        return is_array($data) ? $data : [];
    }
}
