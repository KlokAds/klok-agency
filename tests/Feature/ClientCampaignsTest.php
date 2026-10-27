<?php

use App\Models\Campaign;
use App\Models\Client;
use App\Models\PilotAccount;
use App\Models\User;
use App\Support\Access;
use Database\Seeders\AccessSeeder;
use Database\Seeders\BooksSeeder;

beforeEach(function () {
    $this->seed([AccessSeeder::class, BooksSeeder::class]);
    $this->owner = User::factory()->create();
    $this->owner->assignRole(Access::OWNER);
    $this->client = Client::create(['name' => 'Rafi Traders', 'type' => 'client', 'status' => 'active']);
    $this->other = Client::create(['name' => 'Other Shop', 'type' => 'client', 'status' => 'active']);
    $this->first = PilotAccount::create(['pilot_id' => 7, 'name' => 'Account One', 'platform' => 'meta', 'currency' => 'USD', 'seen_at' => now()]);
    $this->second = PilotAccount::create(['pilot_id' => 8, 'name' => 'Account Two', 'platform' => 'meta', 'currency' => 'USD', 'seen_at' => now()]);
});

it('lists one client\'s campaigns from every ad account when opened from their page', function () {
    Campaign::create(['pilot_campaign_id' => 1, 'pilot_account_id' => $this->first->id, 'name' => 'Rafi A', 'client_id' => $this->client->id]);
    Campaign::create(['pilot_campaign_id' => 2, 'pilot_account_id' => $this->second->id, 'name' => 'Rafi B', 'client_id' => $this->client->id]);
    Campaign::create(['pilot_campaign_id' => 3, 'pilot_account_id' => $this->first->id, 'name' => 'Other C', 'client_id' => $this->other->id]);

    $this->actingAs($this->owner)->get("/campaigns?client={$this->client->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('agency/Campaigns')
            ->where('client.name', 'Rafi Traders')
            ->has('campaigns.data', 2)
            ->where('counts.total', 2)
            ->where('campaigns.data.0.account', fn ($name) => in_array($name, ['Account One', 'Account Two'], true)));
});

it('pages the campaigns on a client\'s page, six at a time, each with its link', function () {
    foreach (range(1, 8) as $n) {
        Campaign::create(['pilot_campaign_id' => 100 + $n, 'pilot_account_id' => $this->first->id, 'name' => "Rafi {$n}", 'client_id' => $this->client->id]);
    }

    $this->actingAs($this->owner)->get("/clients/{$this->client->id}?campaign_page=2")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('campaigns.total', 8)
            ->where('campaigns.current_page', 2)
            ->has('campaigns.data', 2)
            ->has('campaigns.data.0.slug'));
});
