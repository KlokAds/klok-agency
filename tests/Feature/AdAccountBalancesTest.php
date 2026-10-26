<?php

use App\Models\Account;
use App\Models\Campaign;
use App\Models\CampaignDay;
use App\Models\Client;
use App\Models\MetaReceipt;
use App\Models\PilotAccount;
use App\Models\Setting;
use App\Services\AdAccountBalances;
use App\Services\Postings;
use Database\Seeders\AccessSeeder;
use Database\Seeders\BooksSeeder;

beforeEach(function () {
    $this->seed([AccessSeeder::class, BooksSeeder::class]);
    Setting::put('meta_charges_since', '2026-10-01');
    $this->account = PilotAccount::create(['pilot_id' => 9, 'external_id' => 'act_777', 'name' => 'Klok Ads Agency', 'platform' => 'meta', 'currency' => 'USD', 'seen_at' => now()]);
    $this->client = Client::create(['name' => 'GFFS', 'type' => 'client', 'status' => 'active']);
    $this->a = Campaign::create(['pilot_campaign_id' => 901, 'pilot_account_id' => $this->account->id, 'name' => 'Sales A', 'client_id' => $this->client->id, 'bill_from' => '2026-10-01']);
    $this->b = Campaign::create(['pilot_campaign_id' => 902, 'pilot_account_id' => $this->account->id, 'name' => 'Sales B']);
    foreach ([['2026-10-02', 300, 100], ['2026-10-03', 300, 200], ['2026-10-04', 400, 0]] as [$day, $a, $b]) {
        CampaignDay::create(['campaign_id' => $this->a->id, 'day' => $day, 'spend' => $a, 'results' => 1, 'clicks' => 1]);
        CampaignDay::create(['campaign_id' => $this->b->id, 'day' => $day, 'spend' => $b, 'results' => 1, 'clicks' => 1]);
    }
});

function charge(PilotAccount $account, string $day, int $cents, ?array $allocations = null): void
{
    $meta = Account::where('kind', 'platform')->where('code', 'meta')->firstOrFail();
    $entry = app(Postings::class)->cardCharge(['entry_date' => $day, 'description' => 'Meta charge · card 1234', 'account_id' => $meta->id, 'amount' => $cents, 'allocations' => $allocations]);
    MetaReceipt::create(['message_id' => 'pilot:'.$entry->id, 'transaction_id' => 't'.$entry->id, 'subject' => 'Meta', 'pilot_account_id' => $account->id, 'account_ref' => '777', 'amount' => $cents, 'currency' => 'USD', 'charged_at' => $day.' 10:00:00', 'usd_entry_id' => $entry->id, 'status' => 'booked']);
}

it('spreads a charge over the oldest spend first and shows what is still to charge', function () {
    // Oldest first: 2 Oct A 300 + B 100, 3 Oct A 300 → 700 of a 700 charge; 3 Oct B 200 and 4 Oct A 400 wait.
    charge($this->account, '2026-10-05', 700);

    $balances = app(AdAccountBalances::class);
    expect($balances->allocate())->toBe(1);

    $accounts = $balances->accounts();
    expect($accounts)->toHaveCount(1)
        ->and($accounts[0]['spent'])->toBe(1300)
        ->and($accounts[0]['charged'])->toBe(700)
        ->and($accounts[0]['pending'])->toBe(600);

    $byId = collect($accounts[0]['campaigns'])->keyBy('id');
    expect($byId[$this->a->id]['charged'])->toBe(600)
        ->and($byId[$this->a->id]['pending'])->toBe(400)
        ->and($byId[$this->b->id]['charged'])->toBe(100)
        ->and($byId[$this->b->id]['pending'])->toBe(200)
        ->and(collect($accounts[0]['charges'][0]['by_campaign'])->pluck('amount', 'name')->all())->toBe(['Sales A' => 600, 'Sales B' => 100]);

    // The client's spend: 600 final, 400 still provisional against their credit.
    expect($balances->client($this->client->id))->toBe(['charged' => 600, 'uncharged' => 400]);

    // A second charge continues where the first stopped; running again changes nothing.
    charge($this->account, '2026-10-06', 1000);
    expect($balances->allocate())->toBe(1)->and($balances->allocate())->toBe(0);
    $accounts = $balances->accounts();
    expect($accounts[0]['pending'])->toBe(0)
        ->and(collect($accounts[0]['charges'])->firstWhere('amount', 1000)['by_campaign'])->toContain(['name' => 'অন্য', 'amount' => 400]);
});

it('keeps the breakdown a receipt itself gives', function () {
    charge($this->account, '2026-10-05', 500, ['c:'.$this->b->id => 300, 'c:'.$this->a->id => 200]);
    $balances = app(AdAccountBalances::class);
    expect($balances->allocate())->toBe(0);
    $byId = collect($balances->accounts()[0]['campaigns'])->keyBy('id');
    expect($byId[$this->b->id]['charged'])->toBe(300)->and($byId[$this->a->id]['charged'])->toBe(200);
});

it('matches a charge only to the days spent up to its own day, so later days stay uncharged', function () {
    // The 3 Oct charge pays 2–3 Oct (A 600 + B 300 = 900 of 1000, 100 "other"); 4 Oct A 400 waits.
    charge($this->account, '2026-10-03', 1000);
    $balances = app(AdAccountBalances::class);
    expect($balances->allocate())->toBe(1);

    $accounts = $balances->accounts();
    expect($accounts[0]['pending'])->toBe(400)
        ->and(collect($accounts[0]['campaigns'])->keyBy('id')[$this->a->id]['pending'])->toBe(400)
        ->and($balances->client($this->client->id))->toBe(['charged' => 600, 'uncharged' => 400])
        ->and(collect($accounts[0]['charges'][0]['by_campaign'])->pluck('amount', 'name')->all())->toBe(['Sales A' => 600, 'Sales B' => 300, 'অন্য' => 100]);
});
