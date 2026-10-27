<?php

use App\Models\Campaign;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\PilotAccount;
use App\Models\Renewal;
use App\Models\Setting;
use App\Models\User;
use App\Services\CampaignBilling;
use App\Services\CampaignLimits;
use App\Services\Renewals;
use App\Support\Access;
use Database\Seeders\AccessSeeder;
use Database\Seeders\BooksSeeder;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Grammars\SQLiteGrammar;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/*
 * A renewal's days must not change once its invoice has money on it: the client could pay for
 * one day, then ask for 90 while the payment is being settled. The invoice row is read under a
 * lock (Invoices::verify locks it too), so a settling payment always finishes first.
 */
beforeEach(function () {
    $this->seed([AccessSeeder::class, BooksSeeder::class]);
    $this->artisan('books:import-sheet')->assertSuccessful();

    $this->owner = User::factory()->create();
    $this->owner->assignRole(Access::OWNER);

    Setting::put('pilot_token', Crypt::encryptString('agency-token'));
    $account = PilotAccount::create(['pilot_id' => 9, 'name' => 'Klok Ads Agency', 'platform' => 'meta', 'currency' => 'USD', 'seen_at' => now()]);
    $this->gffs = Client::where('name', 'GFFS')->first();
    $day1 = now()->subDays(2)->toDateString();
    $day2 = now()->subDay()->toDateString();
    $end = now()->addDay()->setTime(23, 59)->toIso8601String();
    $this->extended = 0;

    // Klok Pilot: a GFFS campaign at $2 a day that ends tomorrow.
    Http::fake(function ($request) use ($day1, $day2, $end) {
        $args = $request['params']['arguments'] ?? [];
        $content = match ($request['params']['name']) {
            'ad_account_campaigns' => ['currency' => 'USD', 'campaigns' => [
                ['campaign_id' => 101, 'name' => 'GFFS - Messages', 'status' => 'ACTIVE', 'start_time' => now()->subDays(2)->toIso8601String(), 'stop_time' => $end, 'spend_total' => 9, 'spend_7d' => 9, 'last_spend_day' => $day2],
            ]],
            'campaign_daily_spend' => ['currency' => 'USD', 'rows' => [
                ['date' => $day1, 'campaign_id' => 101, 'spend' => 2.12, 'results' => 7],
                ['date' => $day2, 'campaign_id' => 101, 'spend' => 1.92, 'results' => 6],
            ]],
            'set_campaign_limits' => (function () use ($args, $end) {
                $this->extended += isset($args['new_end']) ? 1 : 0;

                return [
                    'daily_budget' => 2.0, 'spend_cap' => $args['spend_cap'] ?? null, 'active' => $args['active'] ?? true,
                    'end' => isset($args['new_end']) ? $args['new_end'].'T23:59:00+06:00' : $end,
                    'extendable' => true, 'why_not' => null, 'spend_cap_error' => null, 'spent_before' => 0,
                ];
            })(),
            default => [],
        };

        return Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => ['structuredContent' => $content]]);
    });

    app(CampaignBilling::class)->refreshCampaigns($account);
    $this->campaign = Campaign::where('pilot_campaign_id', 101)->sole();
    $this->campaign->update(['client_id' => $this->gffs->id, 'bill_from' => $day1]);
    app(CampaignBilling::class)->sync();

    $this->actingAs($this->owner)->post('/quick/client-payment', [
        'entry_date' => now()->toDateString(), 'client_id' => $this->gffs->id, 'category' => 'client_payment',
        'pay_via' => 'bank', 'wallet' => 'bank', 'amount' => '1500', 'usd_credit' => '10',
    ])->assertRedirect();
    app(CampaignLimits::class)->refresh($this->campaign, true);

    // Auto renewal: a 1-day invoice is made at once.
    $this->gffs->update(['renew_days' => 1]);
    app(Renewals::class)->openDue();
    $this->invoice = Invoice::sole();
    $this->renewal = Renewal::sole();
});

/**
 * Runs $work with SQLite showing lockForUpdate() as a marker (it has no row locks of its own),
 * and gives back every SQL statement it ran.
 *
 * @return list<string>
 */
function sqlWithLocks(Closure $work): array
{
    $connection = DB::connection();
    $connection->setQueryGrammar(new class($connection) extends SQLiteGrammar
    {
        protected function compileLock(Builder $query, $value)
        {
            return $value === true ? '/* for update */' : '';
        }
    });

    $sql = [];
    DB::listen(function ($query) use (&$sql) {
        $sql[] = $query->sql;
    });

    try {
        $work();
    } finally {
        $connection->useDefaultQueryGrammar();
    }

    return $sql;
}

/** @param  list<string>  $sql */
function readsInvoiceLocked(array $sql): bool
{
    return collect($sql)->contains(fn (string $s) => str_starts_with($s, 'select') && str_contains($s, 'from "invoices"') && str_contains($s, '/* for update */'));
}

it('reads the renewal invoice under a row lock before changing its days', function () {
    $sql = sqlWithLocks(fn () => app(Renewals::class)->changeDays($this->renewal, 5));

    expect(readsInvoiceLocked($sql))->toBeTrue()
        ->and($this->renewal->fresh()->days)->toBe(5);
});

it('reads the renewal invoice under a row lock before choosing again or ending the contract', function () {
    $sql = sqlWithLocks(fn () => app(Renewals::class)->choose($this->renewal, 3));
    expect(readsInvoiceLocked($sql))->toBeTrue();

    $sql = sqlWithLocks(fn () => app(Renewals::class)->decline($this->renewal->fresh()));
    expect(readsInvoiceLocked($sql))->toBeTrue()
        ->and($this->renewal->fresh()->status)->toBe('declined');
});

it('will not price a renewal invoice again once money is on it', function () {
    $total = $this->invoice->total;

    // Part paid by the Owner: the invoice has money on it, the renewal still waits for the rest.
    $this->actingAs($this->owner)->post("/invoices/{$this->invoice->id}/pay", [
        'method' => 'bank', 'wallet' => 'bank', 'paid_on' => now()->toDateString(), 'amount' => '1',
    ])->assertRedirect();
    expect($this->invoice->fresh()->paid)->toBeGreaterThan(0);

    auth()->logout();
    $this->postJson("/i/{$this->invoice->public_token}/days", ['days' => 90])->assertStatus(422);
    $this->postJson("/i/{$this->invoice->public_token}/stop")->assertOk();

    // A stale copy handed to the service is checked against the row, not trusted.
    $stale = $this->renewal;
    expect(fn () => app(Renewals::class)->changeDays($stale, 90))->toThrow(RuntimeException::class);
    app(Renewals::class)->decline($stale);

    expect($this->invoice->fresh()->total)->toBe($total)
        ->and($this->invoice->fresh()->status)->not->toBe('void')
        ->and($this->renewal->fresh()->days)->toBe(1)
        ->and($this->renewal->fresh()->status)->toBe('invoiced')
        ->and($this->extended)->toBe(0);
});
