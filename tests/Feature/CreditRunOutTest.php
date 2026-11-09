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
use App\Services\ClientNotifier;
use App\Support\Access;
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
        ->assertInertia(fn ($page) => $page->where('credit.left', 46)->where('credit.refundable', false));
});

it('runs a client past their credit up to the room the team allows, says so once, and stops at the edge', function () {
    $owner = User::factory()->create();
    $owner->assignRole(Access::OWNER);
    // $3 paid, $4.04 spent: $1.04 owed. With $5 allowed on credit the ads keep running.
    fund($this->gffs, 300, 45000);
    $this->gffs->update(['credit_allowance' => 500]);

    app(CampaignLimits::class)->guard();

    expect(collect($this->limits)->filter(fn ($l) => ($l['active'] ?? null) === false))->toBeEmpty()
        ->and($this->campaign->fresh()->paused_for_credit_at)->toBeNull()
        // The cap stops it where the room ends: $4.04 spent plus $3.96 still allowed.
        ->and(collect($this->limits)->whereNotNull('spend_cap')->last()['spend_cap'])->toBe(8.0);
    Notification::assertSentTo($this->person, AgencyAlert::class, fn (AgencyAlert $n) => $n->title === ClientNotifier::ON_CREDIT_TITLE && $n->params['owed'] === '$1.04');
    Notification::assertSentTo($owner, AgencyAlert::class, fn (AgencyAlert $n) => $n->title === CampaignLimits::ON_CREDIT_TITLE);

    $this->actingAs($this->person)->get('/portal')->assertInertia(fn ($page) => $page->where('credit.left', -104)->where('credit.allowance', 500));

    // Told once, not every tick.
    app(CampaignLimits::class)->guard();
    Notification::assertSentToTimes($this->person, AgencyAlert::class, 1);

    // The room ran out (its last day passed): the guard stops the campaign as before.
    $this->gffs->update(['credit_allowance_until' => now()->subDay()->toDateString()]);
    app(CampaignLimits::class)->guard();
    expect($this->campaign->fresh()->paused_for_credit_at)->not->toBeNull();

    // Money came: the old $1.04 is paid first, and what the guard stopped runs again.
    fund($this->gffs, 600, 90000);
    app(CampaignLimits::class)->guard();
    expect($this->campaign->fresh()->paused_for_credit_at)->toBeNull()
        ->and(collect($this->limits)->last(fn ($l) => isset($l['active']))['active'])->toBeTrue();
});

it('lets the team set and clear the room on credit on the client page', function () {
    $owner = User::factory()->create();
    $owner->assignRole(Access::OWNER);
    $form = ['name' => 'GFFS', 'status' => 'active', 'rate_mode' => 'commission'];

    $this->actingAs($owner)->put("/clients/{$this->gffs->id}", $form + ['credit_allowance' => '20', 'credit_allowance_until' => now()->addWeek()->toDateString()])->assertSessionHasNoErrors();
    expect($this->gffs->fresh())->credit_allowance->toBe(2000)->allowance()->toBe(2000);

    $this->actingAs($owner)->put("/clients/{$this->gffs->id}", $form + ['credit_allowance' => '', 'credit_allowance_until' => now()->addWeek()->toDateString()])->assertSessionHasNoErrors();
    expect($this->gffs->fresh())->credit_allowance->toBeNull()->credit_allowance_until->toBeNull();
});
