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
    $this->artisan('books:import-sheet')->assertSuccessful();

    $this->owner = User::factory()->create();
    $this->owner->assignRole(Access::OWNER);

    Setting::put('pilot_token', Crypt::encryptString('agency-token'));
    $this->account = PilotAccount::create(['pilot_id' => 9, 'name' => 'Klok Ads Agency', 'platform' => 'meta', 'currency' => 'USD', 'seen_at' => now()]);
    $this->gffs = Client::where('name', 'GFFS')->first();
    $this->end = now()->addDay()->setTime(23, 59)->toIso8601String();
    $this->limits = [];
    $this->problem = null;
    $this->status = 'ACTIVE';

    // Klok Pilot: a GFFS campaign at $2 a day that ends tomorrow, two days of spend ($4.04).
    Http::fake(function ($request) {
        $args = $request['params']['arguments'] ?? [];
        $content = match ($request['params']['name']) {
            'ad_account_campaigns' => ['currency' => 'USD', 'account_problem' => $this->problem, 'campaigns' => [
                ['campaign_id' => 101, 'name' => 'GFFS - Messages', 'status' => $this->status, 'start_time' => now()->subDays(2)->toIso8601String(), 'stop_time' => $this->end, 'spend_total' => 9, 'spend_7d' => 9, 'daily_budget' => 2],
            ]],
            'campaign_daily_spend' => ['currency' => 'USD', 'rows' => [
                ['date' => now()->subDays(2)->toDateString(), 'campaign_id' => 101, 'spend' => 2.12, 'results' => 7],
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
});

function payAhead(object $test, string $bdt, string $usd): void
{
    $test->actingAs($test->owner)->post('/quick/client-payment', [
        'entry_date' => now()->toDateString(), 'client_id' => $test->gffs->id, 'category' => 'client_payment',
        'pay_via' => 'bank', 'wallet' => 'bank', 'amount' => $bdt, 'usd_credit' => $usd,
    ])->assertRedirect();
}

it('lets the client pause their campaign and switch it on again, and tells the team', function () {
    payAhead($this, '1500', '10');
    $url = "/portal/campaigns/{$this->campaign->id}";

    $this->actingAs($this->client)->post("$url/pause")->assertRedirect();
    expect(collect($this->limits)->last())->toMatchArray(['active' => false])
        ->and($this->campaign->fresh()->clientState())->toBe('paused_client')
        ->and($this->owner->notifications()->where('data->kind', 'client_campaign')->count())->toBe(1);

    // The next read from Meta does not call it a problem on our side.
    $this->status = 'PAUSED';
    app(CampaignBilling::class)->refreshCampaigns($this->account);
    expect($this->client->notifications()->where('data->kind', 'client_held')->count())->toBe(0);

    $this->actingAs($this->client)->get($url)->assertInertia(fn ($page) => $page->where('control.state', 'paused_client'));
    $this->actingAs($this->client)->post("$url/resume")->assertRedirect();
    expect(collect($this->limits)->last())->toMatchArray(['active' => true])
        ->and($this->campaign->fresh()->paused_by_client_at)->toBeNull();

    // Not theirs: nothing happens.
    $other = User::factory()->create(['client_id' => Client::where('name', '!=', 'GFFS')->first()->id]);
    $other->assignRole(Access::CLIENT);
    $this->actingAs($other)->post("$url/pause")->assertNotFound();
});

it('raises the budget only as far as the money paid carries it to the last day', function () {
    payAhead($this, '1500', '10'); // $10 paid, $4.04 spent: $5.96 for today and tomorrow
    $url = "/portal/campaigns/{$this->campaign->id}";

    $this->actingAs($this->client)->get($url)->assertInertia(fn ($page) => $page->where('control.room', 298));

    // More than the money carries: refused, Meta never asked.
    $this->actingAs($this->client)->post("$url/budget", ['budget' => '5'])->assertSessionHasErrors('control');
    expect(collect($this->limits)->pluck('daily_budget')->filter()->all())->toBe([]);

    $this->actingAs($this->client)->post("$url/budget", ['budget' => '2.50'])->assertSessionHasNoErrors();
    expect(collect($this->limits)->last()['daily_budget'])->toEqual(2.5)
        ->and($this->campaign->fresh()->daily_budget)->toBe(250);

    // Lowering is always fine; nonsense is refused.
    $this->actingAs($this->client)->post("$url/budget", ['budget' => '1'])->assertSessionHasNoErrors();
    $this->actingAs($this->client)->post("$url/budget", ['budget' => '0.2'])->assertSessionHasErrors('budget');
});

it('tells the client when the ad account stops on our side, and when it runs again', function () {
    $this->problem = 'payment';
    app(CampaignBilling::class)->refreshCampaigns($this->account);

    expect($this->account->fresh()->problem)->toBe('payment')
        ->and($this->owner->notifications()->where('data->kind', 'account_problem')->count())->toBe(1)
        ->and($this->client->notifications()->where('data->kind', 'client_held')->count())->toBe(1);
    $this->actingAs($this->client)->get('/portal')->assertInertia(fn ($page) => $page->where('running.0.state', 'held'));

    // Read again with the same problem: nobody is told twice.
    app(CampaignBilling::class)->refreshCampaigns($this->account);
    expect($this->client->notifications()->where('data->kind', 'client_held')->count())->toBe(1);

    $this->problem = null;
    app(CampaignBilling::class)->refreshCampaigns($this->account);
    expect($this->client->notifications()->where('data->kind', 'client_back')->count())->toBe(1);
    $this->actingAs($this->client)->get('/portal')->assertInertia(fn ($page) => $page->where('running.0.state', 'live'));
});

it('sends a feature request to the Owners and gives clients the agency WhatsApp', function () {
    Setting::put('business_whatsapp', '01712345678');

    $this->actingAs($this->client)->post('/feedback', ['kind' => 'feature', 'text' => 'Invoice PDF on WhatsApp please'])->assertRedirect();
    $sent = $this->owner->notifications()->where('data->kind', 'feedback')->sole();
    expect($sent->data['params']['text'])->toBe('Invoice PDF on WhatsApp please');

    $this->actingAs($this->client)->get('/portal')->assertInertia(fn ($page) => $page->where('contact.whatsapp', 'https://wa.me/8801712345678'));
});

it('shows what would come back of the unspent money, less the cost of sending it', function () {
    payAhead($this, '1500', '10');

    $this->actingAs($this->client)->get('/portal')->assertInertia(fn ($page) => $page->has('credit.refund.mfs')->has('credit.refund.bank'));
});
