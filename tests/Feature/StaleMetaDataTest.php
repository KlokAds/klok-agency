<?php

use App\Models\Campaign;
use App\Models\Client;
use App\Models\PilotAccount;
use App\Models\Setting;
use App\Models\User;
use App\Services\CampaignBilling;
use App\Support\Access;
use Database\Seeders\AccessSeeder;
use Database\Seeders\BooksSeeder;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->seed([AccessSeeder::class, BooksSeeder::class]);
    $this->owner = User::factory()->create();
    $this->owner->assignRole(Access::OWNER);

    Setting::put('pilot_token', Crypt::encryptString('agency-token'));
    $this->account = PilotAccount::create(['pilot_id' => 5, 'name' => 'Anowar Hossain (KA)', 'platform' => 'meta', 'currency' => 'USD', 'seen_at' => now()]);
    $this->client = Client::create(['name' => 'GFFS']);
    $this->synced = now()->subMinutes(10);

    Http::fake(function ($request) {
        $content = match ($request['params']['name']) {
            'ad_account_campaigns' => ['currency' => 'USD', 'last_synced_at' => $this->synced->toIso8601String(), 'account_problem' => null, 'campaigns' => [
                ['campaign_id' => 301, 'name' => 'GFFS - Sales', 'status' => 'ACTIVE', 'start_time' => now()->subDays(9)->toIso8601String(), 'spend_total' => 9, 'spend_7d' => 9, 'daily_budget' => 2],
            ]],
            'campaign_daily_spend' => ['currency' => 'USD', 'rows' => []],
            default => [],
        };

        return Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => ['structuredContent' => $content]]);
    });

    app(CampaignBilling::class)->refreshCampaigns($this->account);
    Campaign::where('pilot_campaign_id', 301)->sole()->update(['client_id' => $this->client->id, 'bill_from' => now()->subDays(9)->toDateString()]);
});

$stale = fn (User $owner) => $owner->notifications()->where('data->title', CampaignBilling::STALE_TITLE)->count();

it('stays quiet while Meta keeps sending the account\'s numbers', function () use ($stale) {
    app(CampaignBilling::class)->sync();

    expect($stale($this->owner))->toBe(0);
});

it('tells the Owner once when Meta stops sending a billed account\'s numbers', function () use ($stale) {
    $this->synced = now()->subHours(27);

    app(CampaignBilling::class)->sync();
    app(CampaignBilling::class)->sync();

    expect($stale($this->owner))->toBe(1);
    expect($this->owner->notifications()->first()->data['params']['account'] ?? null)->toBe('Anowar Hossain (KA)');
});

it('says nothing for an account with no client campaign running on it', function () use ($stale) {
    $this->synced = now()->subHours(27);
    Campaign::where('pilot_campaign_id', 301)->update(['client_id' => null]);

    app(CampaignBilling::class)->refreshCampaigns($this->account);

    expect($stale($this->owner))->toBe(0);
});
