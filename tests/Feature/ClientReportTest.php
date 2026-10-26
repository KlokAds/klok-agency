<?php

use App\Models\BdtEntry;
use App\Models\Campaign;
use App\Models\CampaignDay;
use App\Models\Client;
use App\Models\FxBatch;
use App\Models\PilotAccount;
use App\Models\User;
use App\Support\Access;
use Database\Seeders\AccessSeeder;
use Database\Seeders\BooksSeeder;

beforeEach(function () {
    $this->seed([AccessSeeder::class, BooksSeeder::class]);
    FxBatch::create(['number' => 1, 'purchased_on' => now()->subDays(40)->toDateString(), 'description' => 'USD bought', 'usd' => 10000, 'bdt' => 1400000]);
    $this->owner = User::factory()->create();
    $this->owner->assignRole(Access::OWNER);
    $this->client = Client::create(['name' => 'Rafi Traders', 'type' => 'client', 'status' => 'active', 'company' => 'Rafi Traders Ltd']);
});

it('lays out a client report with campaigns, days, payments and the standing, without any cost', function () {
    $account = PilotAccount::create(['pilot_id' => 7, 'name' => 'Rafi Ads', 'platform' => 'meta', 'currency' => 'USD', 'seen_at' => now()]);
    $campaign = Campaign::create(['pilot_campaign_id' => 701, 'pilot_account_id' => $account->id, 'name' => 'Rafi — Leads', 'status' => 'ACTIVE', 'client_id' => $this->client->id, 'bill_from' => now()->subDays(10)->toDateString()]);
    CampaignDay::create(['campaign_id' => $campaign->id, 'day' => now()->subDays(3)->toDateString(), 'spend' => 500, 'results' => 10, 'clicks' => 40]);
    CampaignDay::create(['campaign_id' => $campaign->id, 'day' => now()->subDays(2)->toDateString(), 'spend' => 700, 'results' => 14, 'clicks' => 50]);
    BdtEntry::create(['entry_date' => now()->subDays(5)->toDateString(), 'description' => 'Rafi paid', 'category' => 'client_payment', 'client_id' => $this->client->id, 'amount' => 300000, 'pay_via' => 'mfs', 'usd_credit' => 2000]);

    $this->actingAs($this->owner)->get("/clients/{$this->client->id}/report")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('clients/Report')
            ->where('client.name', 'Rafi Traders')
            ->where('summary.spend', 1200)
            ->where('summary.results', 24)
            ->where('summary.paid', 300000)
            ->where('campaigns.0.name', 'Rafi — Leads')
            ->where('campaigns.0.cpr', 50)
            ->where('payments.0.via', 'MFS (bKash / Nagad)')
            ->has('months', 1)
            ->missing('summary.cost')
            ->missing('standing.rate')
        );
});

it('is only for someone who may see clients, and keeps to the period asked', function () {
    $viewer = User::factory()->create();
    $viewer->assignRole('Viewer');
    $this->actingAs($viewer)->get("/clients/{$this->client->id}/report")->assertOk();

    $client = User::factory()->create(['client_id' => $this->client->id]);
    $client->assignRole(Access::CLIENT);
    // A client's own account never reaches the team's pages: it is sent back to the portal.
    $this->actingAs($client)->get("/clients/{$this->client->id}/report")->assertRedirect();

    $this->actingAs($this->owner)->get("/clients/{$this->client->id}/report?range=7d")
        ->assertInertia(fn ($page) => $page->where('range.key', '7d'));
});
