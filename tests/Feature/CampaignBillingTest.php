<?php

use App\Models\Account;
use App\Models\Campaign;
use App\Models\Client;
use App\Models\PilotAccount;
use App\Models\Setting;
use App\Models\UsdEntry;
use App\Models\User;
use App\Services\Books;
use App\Services\CampaignBilling;
use App\Support\Access;
use Carbon\CarbonImmutable;
use Database\Seeders\AccessSeeder;
use Database\Seeders\BooksSeeder;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->seed([AccessSeeder::class, BooksSeeder::class]);
    $this->artisan('books:import-sheet')->assertSuccessful();

    $this->owner = User::factory()->create();
    $this->owner->assignRole(Access::OWNER);

    Setting::put('pilot_token', Crypt::encryptString('agency-token'));
    $this->account = PilotAccount::create(['pilot_id' => 9, 'name' => 'Klok Ads Agency', 'platform' => 'meta', 'currency' => 'USD', 'seen_at' => now()]);
    $this->gffs = Client::where('name', 'GFFS')->first();

    $this->day1 = now()->subDays(2)->toDateString();
    $this->day2 = now()->subDay()->toDateString();

    // Klok Pilot: one ad account, a GFFS campaign and someone else's, and two days of spend.
    Http::fake(function ($request) {
        $content = match ($request['params']['name']) {
            'list_ad_accounts' => ['ad_accounts' => [['ad_account_id' => 9, 'external_id' => 'act_9', 'name' => 'Klok Ads Agency', 'platform' => 'meta', 'currency' => 'USD', 'shop' => 'Klok Ads']]],
            'ad_account_campaigns' => ['currency' => 'USD', 'campaigns' => [
                ['campaign_id' => 101, 'name' => 'GFFS - Messages', 'status' => 'ACTIVE', 'daily_budget' => 2, 'spend_total' => 9, 'spend_7d' => 9, 'last_spend_day' => $this->day2],
                ['campaign_id' => 102, 'name' => 'Brand awareness', 'status' => 'PAUSED', 'spend_total' => 0, 'spend_7d' => 0],
            ]],
            'campaign_daily_spend' => ['currency' => 'USD', 'rows' => [
                ['date' => $this->day1, 'campaign_id' => 101, 'spend' => 5.00, 'results' => 7],
                ['date' => $this->day2, 'campaign_id' => 101, 'spend' => 4.00, 'results' => 6],
            ]],
            default => [],
        };

        return Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => ['structuredContent' => $content]]);
    });
});

it('lists an ad account\'s campaigns and suggests the client from the name', function () {
    $this->actingAs($this->owner)->get('/campaigns?account='.$this->account->id)->assertOk()
        ->assertInertia(fn ($page) => $page->component('agency/Campaigns')
            ->where('campaigns.total', 2)
            ->where('campaigns.data.0.name', 'GFFS - Messages')
            ->where('campaigns.data.0.suggested.name', 'GFFS')
            ->where('campaigns.data.1.suggested', null));
});

it('bills a linked campaign\'s spend to its client week by week and funds it from the advance credit', function () {
    app(CampaignBilling::class)->refreshCampaigns($this->account);
    $campaign = Campaign::where('pilot_campaign_id', 101)->sole();

    $this->actingAs($this->owner)->put("/campaigns/{$campaign->id}/client", ['client_id' => $this->gffs->id, 'bill_from' => $this->day1])->assertRedirect();
    app(CampaignBilling::class)->sync();

    // Both days fall in one week: one row of $9.
    $rows = UsdEntry::where('source', 'pilot')->orderBy('entry_date')->get();
    expect($rows->pluck('amount')->all())->toBe([-900])
        ->and($rows->pluck('status')->unique()->all())->toBe(['pending'])
        ->and($rows->first()->account_id)->toBe(Account::where('code', 'meta')->value('id'));

    // ৳900 buys $6 of credit (rate 150): the $9 week is only partly covered, so it stays pending.
    $this->actingAs($this->owner)->post('/quick/client-payment', [
        'entry_date' => now()->toDateString(), 'client_id' => $this->gffs->id, 'category' => 'client_payment',
        'pay_via' => 'bank', 'wallet' => 'bank', 'amount' => '900', 'usd_credit' => '6',
    ])->assertRedirect();

    expect(UsdEntry::where('source', 'pilot')->orderBy('entry_date')->pluck('status')->all())->toBe(['pending']);

    $books = new Books;
    $client = collect($books->clients())->firstWhere('id', $this->gffs->id);

    expect($client['credit_usd'])->toBe(600)
        ->and($client['campaign_usd'])->toBe(900)
        ->and($client['credit_left'])->toBe(-300)
        // The $6 the money reached is earned: nothing of the ৳900 is still held.
        ->and($client['unearned'])->toBe(0)
        ->and($books->summary()['equity_ok'])->toBeTrue();

    // Pilot rows follow the campaign: they cannot be edited by hand.
    $this->actingAs($this->owner)->delete('/usd/'.$rows->first()->id)->assertSessionHasErrors('entry');

    // Unlinking takes the spend off the client again.
    $this->actingAs($this->owner)->put("/campaigns/{$campaign->id}/client", ['client_id' => null])->assertRedirect();
    expect(UsdEntry::where('source', 'pilot')->count())->toBe(0);
});

it('only bills spend from the bill-from day on', function () {
    app(CampaignBilling::class)->refreshCampaigns($this->account);
    $campaign = Campaign::where('pilot_campaign_id', 101)->sole();
    $campaign->update(['client_id' => $this->gffs->id, 'bill_from' => $this->day2]);

    app(CampaignBilling::class)->sync();

    expect(UsdEntry::where('source', 'pilot')->pluck('amount')->all())->toBe([-400]);
});

it('counts spend the card has not been charged for yet, oldest first', function () {
    app(CampaignBilling::class)->refreshCampaigns($this->account);
    Campaign::where('pilot_campaign_id', 101)->update(['client_id' => $this->gffs->id, 'bill_from' => $this->day1]);
    app(CampaignBilling::class)->sync();

    $before = (new Books)->unbilledByClient()[$this->gffs->id] ?? 0;

    $this->actingAs($this->owner)->post('/quick/card-charge', [
        'entry_date' => now()->toDateString(), 'account_id' => Account::where('code', 'meta')->value('id'), 'amount' => '5',
    ])->assertRedirect();

    $books = new Books;
    $after = $books->unbilledByClient()[$this->gffs->id] ?? 0;

    expect($before - $after)->toBeLessThanOrEqual(500)
        ->and(array_sum($books->unbilledByClient()))->toBe($books->summary()['platform_due']);
});

it('links every campaign whose name names one client', function () {
    app(CampaignBilling::class)->refreshCampaigns($this->account);

    $this->actingAs($this->owner)->post('/campaigns/link-suggested', ['account' => $this->account->id, 'bill_from' => $this->day1])->assertRedirect();

    expect(Campaign::where('pilot_campaign_id', 101)->value('client_id'))->toBe($this->gffs->id)
        ->and(Campaign::where('pilot_campaign_id', 102)->value('client_id'))->toBeNull();
});

it('bills from the campaign start when no day is given, or the day after spend already entered by hand', function () {
    app(CampaignBilling::class)->refreshCampaigns($this->account);
    $campaign = Campaign::where('pilot_campaign_id', 101)->sole();
    // Started on day 1 (after GFFS's hand-written spend from the old sheet).
    $campaign->update(['start_at' => $this->day1]);

    $this->actingAs($this->owner)->put("/campaigns/{$campaign->id}/client", ['client_id' => $this->gffs->id])->assertRedirect();
    expect($campaign->fresh()->bill_from->toDateString())->toBe($this->day1);

    // GFFS's spend up to day 1 was written by hand: billing starts the day after.
    UsdEntry::create([
        'entry_date' => $this->day1, 'description' => 'GFFS - Meta ad spend', 'category' => 'client_spend',
        'account_id' => Account::where('code', 'meta')->value('id'), 'client_id' => $this->gffs->id, 'amount' => -500, 'status' => 'pending',
    ]);
    $this->actingAs($this->owner)->put("/campaigns/{$campaign->id}/client", ['client_id' => $this->gffs->id])->assertRedirect();
    expect($campaign->fresh()->bill_from->toDateString())->toBe($this->day2);

    app(CampaignBilling::class)->sync();
    expect(UsdEntry::where('source', 'pilot')->pluck('amount')->all())->toBe([-400]);
});

it('runs billing weeks seven days at a time from the first billed day, whatever day they paid', function () {
    $week = app(CampaignBilling::class)->weeks($this->gffs->id, '2026-10-19');
    $span = fn (string $day) => array_map(fn ($d) => $d->toDateString(), $week(CarbonImmutable::parse($day)));

    // Paid up to the 18th, paid again on the 20th: that $14 is for the 19th–25th.
    expect($span('2026-10-19'))->toBe(['2026-10-19', '2026-10-25'])
        ->and($span('2026-10-25'))->toBe(['2026-10-19', '2026-10-25'])
        ->and($span('2026-10-26'))->toBe(['2026-10-26', '2026-11-01']);
});
