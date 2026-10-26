<?php

use App\Models\BdtEntry;
use App\Models\Campaign;
use App\Models\CampaignDay;
use App\Models\Client;
use App\Models\PilotAccount;
use App\Models\User;
use Database\Seeders\AccessSeeder;
use Database\Seeders\BooksSeeder;

beforeEach(fn () => $this->seed([AccessSeeder::class, BooksSeeder::class]));

it('lays the days of spend on the payments oldest first, so the client sees which one is being spent', function () {
    $client = Client::create(['name' => 'GFFS', 'type' => 'client', 'status' => 'active']);
    $person = User::factory()->create(['client_id' => $client->id]);
    $account = PilotAccount::create(['pilot_id' => 9, 'name' => 'Klok Ads Agency', 'platform' => 'meta', 'currency' => 'USD', 'seen_at' => now()]);
    $campaign = Campaign::create(['pilot_campaign_id' => 901, 'pilot_account_id' => $account->id, 'name' => 'Sales', 'status' => 'ACTIVE', 'client_id' => $client->id, 'bill_from' => '2026-10-19', 'start_at' => '2026-10-01', 'stop_at' => now()->addDays(3)]);
    foreach (['2026-10-18' => 500, '2026-10-19' => 200, '2026-10-20' => 200, '2026-10-21' => 200, '2026-10-22' => 200, '2026-10-23' => 200, '2026-10-24' => 200, '2026-10-25' => 200, '2026-10-26' => 100] as $day => $spend) {
        CampaignDay::create(['campaign_id' => $campaign->id, 'day' => $day, 'spend' => $spend, 'results' => 1, 'clicks' => 1]);
    }
    // $14 on the 20th, $14 on the 25th; 18 Oct is before billing began and does not count.
    foreach (['2026-10-20', '2026-10-25'] as $date) {
        BdtEntry::create(['entry_date' => $date, 'description' => 'advance', 'category' => 'client_payment', 'client_id' => $client->id, 'pay_via' => 'bank', 'wallet' => 'bank', 'amount' => 210000, 'usd_credit' => 1400]);
    }

    // Newest first: the 25 Oct payment is being spent now — 26 Oct's $1.00 after the first ran out on the 25th.
    $this->actingAs($person)->get('/portal/campaigns')->assertOk()
        ->assertInertia(fn ($page) => $page->has('rounds', 2)
            ->where('rounds.0.date', '2026-10-25')->where('rounds.0.usd', 1400)->where('rounds.0.spent', 100)->where('rounds.0.left', 1300)->where('rounds.0.state', 'running')->where('rounds.0.from', '2026-10-26')
            ->where('rounds.1.date', '2026-10-20')->where('rounds.1.spent', 1400)->where('rounds.1.left', 0)->where('rounds.1.state', 'used')->where('rounds.1.from', '2026-10-19')->where('rounds.1.to', '2026-10-25'));
});
