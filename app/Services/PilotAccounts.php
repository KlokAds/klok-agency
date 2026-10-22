<?php

namespace App\Services;

use App\Models\PilotAccount;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

/**
 * Keeps the local list of Klok Pilot ad accounts in step with Pilot and
 * reads each account's numbers (both cached for ten minutes).
 */
class PilotAccounts
{
    public const DAYS = [1, 7, 30];

    public function __construct(private PilotClient $pilot) {}

    /**
     * Copies Pilot's ad accounts in; an account Pilot no longer shows keeps
     * its row (and client link) so old ad runs still name it.
     *
     * @throws RuntimeException when Pilot cannot be reached
     */
    public function sync(bool $force = false): void
    {
        if (! PilotClient::connected() || (! $force && ! Cache::add('pilot:synced', true, 600))) {
            return;
        }

        foreach ($this->pilot->adAccounts() as $a) {
            $account = PilotAccount::firstOrNew(['pilot_id' => $a['id']]);
            $account->fill([
                'name' => $a['name'],
                'external_id' => $a['external_id'],
                'platform' => $a['platform'],
                'currency' => $a['currency'],
                'shop' => $a['shop'],
            ]);
            $account->seen_at = now();
            $account->save();
        }

        if ($force) {
            Cache::put('pilot:synced', true, 600);
        }
    }

    /**
     * One account's numbers for the last $days days, with campaigns that
     * spent or got results, biggest spend first.
     *
     * @return array{days: int, error: string|null, currency: string, from: string|null, to: string|null, synced_at: string|null, totals: array<string, mixed>, campaigns: array<int, array{name: string, status: string|null, spend: float, results: int, cost_per_result: float|null, clicks: int, impressions: int}>}
     */
    public function report(PilotAccount $account, int $days): array
    {
        $days = in_array($days, self::DAYS, true) ? $days : 7;
        $empty = ['days' => $days, 'error' => null, 'currency' => $account->currency ?? 'USD', 'from' => null, 'to' => null, 'synced_at' => null, 'totals' => [], 'campaigns' => []];

        try {
            $report = Cache::remember("pilot:report:{$account->pilot_id}:{$days}", 600, fn () => $this->pilot->performance($account->pilot_id, $days));
        } catch (RuntimeException $e) {
            return ['error' => $e->getMessage()] + $empty;
        }

        $campaigns = collect($report['campaigns'] ?? [])
            ->filter(fn ($c) => (float) ($c['spend'] ?? 0) > 0 || (int) ($c['results'] ?? 0) > 0)
            ->map(fn ($c) => [
                'name' => (string) ($c['name'] ?? '—'),
                'status' => isset($c['status']) ? (string) $c['status'] : null,
                'spend' => (float) ($c['spend'] ?? 0),
                'results' => (int) ($c['results'] ?? 0),
                'cost_per_result' => isset($c['cost_per_result']) ? (float) $c['cost_per_result'] : null,
                'clicks' => (int) ($c['clicks'] ?? 0),
                'impressions' => (int) ($c['impressions'] ?? 0),
            ])->values()->all();

        return [
            'currency' => (string) ($report['currency'] ?? $empty['currency']),
            'from' => $report['from'] ?? null,
            'to' => $report['to'] ?? null,
            'synced_at' => $report['last_synced_at'] ?? null,
            'totals' => $report['totals'] ?? [],
            'campaigns' => $campaigns,
        ] + $empty;
    }
}
