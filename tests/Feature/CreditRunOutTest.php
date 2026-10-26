<?php

use App\Models\BdtEntry;
use App\Models\Campaign;
use App\Models\Client;
use App\Models\PilotAccount;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\AgencyAlert;
use App\Services\CampaignBilling;
use App\Services\CampaignLimits;
use Database\Seeders\AccessSeeder;
use Database\Seeders\BooksSeeder;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    $this->seed([AccessSeeder::class, BooksSeeder::class]);
    Notification::fake();
    Setting::put('pilot_url', 'https://pilot.test/mcp');
    Setting::put('pilot_token', Crypt::encryptString('secret'));
    Setting::put('credit_guard', true);
    $this->account = PilotAccount::create(['pilot_id' => 9, 'name' => 'Klok Ads Agency', 'platform' => 'meta', 'currency' => 'USD', 'seen_at' => now()]);
    $this->gffs = Client::create(['name' => 'GFFS', 'type' => 'client', 'status' => 'active']);
    $this->person = User::factory()->create(['client_id' => $this->gffs->id]);
    $this->limits = [];
    // Ends tonight; two days of spend ($4.04) at $2 a day.
    $this->end = now()->setTime(23, 59)->toIso8601String();

    Http::fake(function ($request) {
        $args = $request['params']['arguments'] ?? [];
        $content = match ($request['params']['name']) {
            'ad_account_campaigns' => ['currency' => 'USD', 'account_problem' => null, 'campaigns' => [
                ['campaign_id' => 101, 'name' => 'GFFS - Messages', 'status' => 'ACTIVE', 'start_time' => now()->subDays(2)->toIso8601String(), 'stop_time' => $this->end, 'spend_total' => 4.04, 'spend_7d' => 4.04, 'daily_budget' => 2],
            ]],
            'campaign_daily_spend' => ['currency' => 'USD', 'rows' => [
                ['date' => now()->subDays(2)->toDateString(), 'campaign_id' => 101, 'spend' => 2.12, 'results' => 7],
                ['date' => now()->subDay()->toDateString(), 'campaign_id' => 101, 'spend' => 1.92, 'results' => 6],
            ]],
            'set_campaign_limits' => (function () use ($args) {
                $this->limits[] = $args;
                if (isset($args['new_end'])) {
                    $this->end = $args['new_end'].'T23:59:00+06:00';
                }

                return [
                    'daily_budget' => $args['daily_budget'] ?? 2.0, 'spend_cap' => $args['spend_cap'] ?? null, 'active' => $args['active'] ?? true,
                    'end' => $this->end, 'extendable' => true, 'why_not' => null, 'spend_cap_error' => null, 'spent_before' => 0,
                ];
            })(),
            default => [],
        };

        return Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => ['structuredContent' => $content]]);
    });

    app(CampaignBilling::class)->refreshCampaigns($this->account);
    $this->campaign = Campaign::where('pilot_campaign_id', 101)->sole();
    $this->campaign->update(['client_id' => $this->gffs->id, 'bill_from' => now()->subDays(2)->toDateString()]);
    app(CampaignBilling::class)->sync();
});

function fund(Client $client, int $usd, int $bdt): void
{
    BdtEntry::create(['entry_date' => now()->subDays(3)->toDateString(), 'description' => 'advance', 'category' => 'client_payment', 'client_id' => $client->id,
        'pay_via' => 'bank', 'wallet' => 'bank', 'amount' => $bdt, 'usd_credit' => $usd]);
}

it('runs a campaign on when it ends with a dollar or more of credit left, and tells the client', function () {
    // $6 paid, $4.04 spent: $1.96 left — one more day at $2 a day.
    fund($this->gffs, 600, 90000);

    $done = app(CampaignLimits::class)->guard();

    expect($done['extended'] ?? 0)->toBe(1)
        ->and(collect($this->limits)->firstWhere('new_end', '!=', null)['new_end'])->toBe(now()->addDay()->toDateString())
        ->and($this->campaign->fresh()->lastDay()?->toDateString())->toBe(now()->addDay()->toDateString());
    Notification::assertSentTo($this->person, AgencyAlert::class, fn (AgencyAlert $n) => $n->kind === 'client_extended' && $n->params['days'] === 1);

    // Once per end: the next tick moves nothing again.
    expect(app(CampaignLimits::class)->guard()['extended'] ?? 0)->toBe(0);
});

it('leaves under a dollar alone: no extension, no refund', function () {
    // $4.50 paid, $4.04 spent: $0.46 left.
    fund($this->gffs, 450, 67500);

    expect(app(CampaignLimits::class)->guard()['extended'] ?? 0)->toBe(0)
        ->and(collect($this->limits)->whereNotNull('new_end'))->toBeEmpty();
    Notification::assertNotSentTo($this->person, AgencyAlert::class);

    $this->actingAs($this->person)->get('/portal')->assertOk()
        ->assertInertia(fn ($page) => $page->where('credit.left', 46)->where('credit.refund', null));
});
