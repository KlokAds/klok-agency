<?php

use App\Models\Campaign;
use App\Models\CampaignDay;
use App\Models\Client;
use App\Models\PilotAccount;
use App\Models\Setting;
use App\Models\User;
use App\Services\CampaignBilling;
use App\Support\Access;
use Database\Seeders\AccessSeeder;
use Database\Seeders\BooksSeeder;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Laravel\Fortify\Features;

beforeEach(function () {
    $this->seed([AccessSeeder::class, BooksSeeder::class]);
    $this->artisan('books:import-sheet')->assertSuccessful();

    $this->owner = User::factory()->create();
    $this->owner->assignRole(Access::OWNER);

    Setting::put('pilot_token', Crypt::encryptString('agency-token'));
    $this->account = PilotAccount::create(['pilot_id' => 9, 'name' => 'Klok Ads Agency', 'platform' => 'meta', 'currency' => 'USD', 'seen_at' => now()]);
    $this->gffs = Client::where('name', 'GFFS')->first();
    $this->end = now()->addDay()->setTime(23, 59)->toIso8601String();
    $this->pilotError = null;

    // Klok Pilot: a GFFS campaign at $2 a day that ends tomorrow; Meta may refuse a change.
    Http::fake(function ($request) {
        if ($request['params']['name'] === 'set_campaign_limits' && $this->pilotError !== null) {
            return Http::response(['jsonrpc' => '2.0', 'id' => 1, 'error' => ['code' => -32000, 'message' => $this->pilotError]]);
        }

        $args = $request['params']['arguments'] ?? [];
        $content = match ($request['params']['name']) {
            'ad_account_campaigns' => ['currency' => 'USD', 'account_problem' => null, 'campaigns' => [
                ['campaign_id' => 101, 'name' => 'GFFS - Messages', 'status' => 'ACTIVE', 'start_time' => now()->subDays(2)->toIso8601String(), 'stop_time' => $this->end, 'spend_total' => 9, 'spend_7d' => 9, 'daily_budget' => 2],
            ]],
            'campaign_daily_spend' => ['currency' => 'USD', 'rows' => [
                ['date' => now()->subDays(2)->toDateString(), 'campaign_id' => 101, 'spend' => 2.12, 'results' => 7],
                ['date' => now()->subDay()->toDateString(), 'campaign_id' => 101, 'spend' => 1.92, 'results' => 6],
            ]],
            'set_campaign_limits' => [
                'daily_budget' => $args['daily_budget'] ?? 2.0, 'spend_cap' => $args['spend_cap'] ?? null, 'active' => $args['active'] ?? true,
                'end' => $this->end, 'extendable' => true, 'why_not' => null, 'spend_cap_error' => null, 'spent_before' => 0,
            ],
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

it('links a portal sign-up only once its email is confirmed, when email checks are on', function () {
    Notification::fake();
    config(['fortify.features' => [...config('fortify.features'), Features::emailVerification()]]);

    $this->post('/portal/register', [
        'name' => 'Not Rahim', 'company' => 'GFFS Ltd', 'phone' => '01712345678', 'email' => 'owner@gffs.test',
        'password' => 'secret-pass-1', 'password_confirmation' => 'secret-pass-1',
    ])->assertRedirect('/portal');
    $user = User::where('email', 'owner@gffs.test')->sole();

    // Typing the client's email does not make it theirs: a link goes to it.
    expect($user->email_verified_at)->toBeNull();
    Notification::assertSentTo($user, VerifyEmail::class);

    $this->actingAs($this->owner)->get("/clients/{$this->gffs->id}")
        ->assertInertia(fn ($page) => $page->where('portal.waiting', fn ($w) => collect($w)->firstWhere('id', $user->id)['verified'] === false));
    $this->actingAs($this->owner)->post("/clients/{$this->gffs->id}/portal-users", ['user_id' => $user->id])->assertSessionHasErrors('user_id');
    expect($user->fresh()->client_id)->toBeNull();

    // The link opened: now the Owner can link them.
    $user->markEmailAsVerified();
    $this->actingAs($this->owner)->post("/clients/{$this->gffs->id}/portal-users", ['user_id' => $user->id])->assertSessionHasNoErrors();
    expect($user->fresh()->client_id)->toBe($this->gffs->id);
});

it('keeps sign-up as before while email checks are off', function () {
    // config/fortify.php turns the checks on now; this is the switch taken back out.
    config(['fortify.features' => array_values(array_filter(config('fortify.features'), fn ($f) => $f !== Features::emailVerification()))]);

    $this->post('/portal/register', [
        'name' => 'Rahim', 'company' => 'GFFS Ltd', 'phone' => '01712345678', 'email' => 'rahim@gffs.test',
        'password' => 'secret-pass-1', 'password_confirmation' => 'secret-pass-1',
    ])->assertRedirect('/portal');

    expect(User::where('email', 'rahim@gffs.test')->sole()->email_verified_at)->not->toBeNull();
});

it('never shows a client the days a campaign ran for another client', function () {
    $other = Client::where('name', '!=', 'GFFS')->first();
    $moved = Campaign::create([
        'pilot_campaign_id' => 202, 'pilot_account_id' => $this->account->id, 'name' => 'Was Theirs', 'status' => 'ACTIVE',
        'stop_at' => now()->addDays(5), 'client_id' => $other->id, 'bill_from' => now()->subDays(30)->toDateString(), 'spend_total' => 0, 'spend_7d' => 0,
    ]);
    CampaignDay::create(['campaign_id' => $moved->id, 'day' => now()->subDays(10)->toDateString(), 'spend' => 7777, 'results' => 70, 'clicks' => 700]);
    CampaignDay::create(['campaign_id' => $moved->id, 'day' => now()->subDay()->toDateString(), 'spend' => 300, 'results' => 3, 'clicks' => 30]);
    // Our own campaign's life before it was billed still shows: it was always theirs.
    CampaignDay::create(['campaign_id' => $this->campaign->id, 'day' => now()->subDays(20)->toDateString(), 'spend' => 50, 'results' => 1, 'clicks' => 2]);

    // Moved to GFFS, billed to them from three days ago.
    $moved->update(['client_id' => $this->gffs->id, 'bill_from' => now()->subDays(3)->toDateString()]);

    $mine = (int) CampaignDay::where('campaign_id', $this->campaign->id)->sum('spend');
    $this->actingAs($this->client)->get('/portal/campaigns?range=all')
        ->assertInertia(fn ($page) => $page->where('totals.spend', $mine + 300)
            ->where('range.from', now()->subDays(20)->toDateString()));
    $this->actingAs($this->client)->get('/portal/campaigns?range=30d')
        ->assertInertia(fn ($page) => $page->where('totals.spend', $mine + 300));

    $this->actingAs($this->client)->get("/portal/campaigns/{$moved->id}?range=all")
        ->assertInertia(fn ($page) => $page->where('totals.spend', 300)->has('days', 1)
            ->where('range.from', now()->subDays(3)->toDateString()));
    $this->actingAs($this->client)->get("/portal/campaigns/{$moved->id}?range=custom&from=".now()->subDays(15)->toDateString().'&to='.now()->toDateString())
        ->assertInertia(fn ($page) => $page->where('totals.spend', 300));
});

it('shows the client a plain line, not Pilot or Meta error text, when a change fails', function () {
    $url = "/portal/campaigns/{$this->campaign->id}";
    $this->pilotError = 'Ad account act_998877 is disabled: payment method Visa 4242 failed';

    $this->actingAs($this->client)->post("$url/pause")->assertSessionHasErrors('control');
    $said = session('errors')->first('control');
    expect($said)->not->toContain('act_998877')->not->toContain('Klok Pilot')->not->toContain('4242');

    // Our own refusals, written for the client, still come through as they are.
    $this->pilotError = null;
    $this->actingAs($this->client)->post("$url/resume")->assertSessionHasErrors('control');
    expect(session('errors')->first('control'))->not->toBe($said);
});
