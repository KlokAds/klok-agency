<?php

use App\Models\Campaign;
use App\Models\Client;
use App\Models\PilotAccount;
use App\Models\Setting;
use App\Models\User;
use App\Services\Books;
use App\Services\CampaignBilling;
use App\Support\Access;
use Database\Seeders\AccessSeeder;
use Database\Seeders\BooksSeeder;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;

// A client with no money paid ahead: guard() puts no spend cap on their campaigns, so the
// portal must not let them raise a budget or switch a campaign on (finding 37).
beforeEach(function () {
    $this->seed([AccessSeeder::class, BooksSeeder::class]);
    $this->artisan('books:import-sheet')->assertSuccessful();

    Setting::put('pilot_token', Crypt::encryptString('agency-token'));
    $this->account = PilotAccount::create(['pilot_id' => 9, 'name' => 'Klok Ads Agency', 'platform' => 'meta', 'currency' => 'USD', 'seen_at' => now()]);
    $this->gffs = Client::where('name', 'GFFS')->first();
    $this->end = now()->addDays(5)->setTime(23, 59)->toIso8601String();
    $this->limits = [];
    $this->status = 'ACTIVE';

    Http::fake(function ($request) {
        $args = $request['params']['arguments'] ?? [];
        $content = match ($request['params']['name']) {
            'ad_account_campaigns' => ['currency' => 'USD', 'account_problem' => null, 'campaigns' => [
                ['campaign_id' => 101, 'name' => 'GFFS - Messages', 'status' => $this->status, 'start_time' => now()->subDays(2)->toIso8601String(), 'stop_time' => $this->end, 'spend_total' => 9, 'spend_7d' => 9, 'daily_budget' => 2],
            ]],
            'campaign_daily_spend' => ['currency' => 'USD', 'rows' => [
                ['date' => now()->subDay()->toDateString(), 'campaign_id' => 101, 'spend' => 1.92, 'results' => 6],
            ]],
            'set_campaign_limits' => (function () use ($args) {
                $this->limits[] = $args;

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

    $this->client = User::factory()->create(['client_id' => $this->gffs->id]);
    $this->client->assignRole(Access::CLIENT);
    $this->url = "/portal/campaigns/{$this->campaign->id}";
});

it('starts from a client with no money paid ahead', function () {
    $figure = collect(app(Books::class)->clients())->firstWhere('id', $this->gffs->id);

    expect((int) $figure['credit_usd'])->toBe(0)
        ->and((int) $figure['credit_left'])->toBeLessThanOrEqual(0);
});

it('does not let a client with no paid money raise the budget, but lets them lower it', function () {
    // Up to the portal's top ($1000) used to go straight to Meta: now refused, Meta never asked.
    $this->actingAs($this->client)->post("{$this->url}/budget", ['budget' => '1000'])->assertSessionHasErrors('control');
    $this->actingAs($this->client)->post("{$this->url}/budget", ['budget' => '2.50'])->assertSessionHasErrors('control');
    expect(collect($this->limits)->pluck('daily_budget')->filter()->all())->toBe([])
        ->and($this->campaign->fresh()->dailyBudget())->toBe(200);

    // Spending less is always fine.
    $this->actingAs($this->client)->post("{$this->url}/budget", ['budget' => '1'])->assertSessionHasNoErrors();
    expect(collect($this->limits)->last()['daily_budget'])->toEqual(1.0)
        ->and($this->campaign->fresh()->daily_budget)->toBe(100);
});

it('lets a client with no paid money pause, but not switch it on again', function () {
    $this->actingAs($this->client)->post("{$this->url}/pause")->assertSessionHasNoErrors();
    expect(collect($this->limits)->last())->toMatchArray(['active' => false]);

    $this->status = 'PAUSED';
    app(CampaignBilling::class)->refreshCampaigns($this->account);
    $calls = count($this->limits);

    $this->actingAs($this->client)->post("{$this->url}/resume")->assertSessionHasErrors('control');
    expect(count($this->limits))->toBe($calls)
        ->and($this->campaign->fresh()->paused_by_client_at)->not->toBeNull();
});
