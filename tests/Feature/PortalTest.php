<?php

use App\Models\Campaign;
use App\Models\CampaignDay;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\PilotAccount;
use App\Models\User;
use App\Services\Invoices;
use App\Support\Access;
use Database\Seeders\AccessSeeder;
use Database\Seeders\BooksSeeder;

beforeEach(function () {
    $this->seed([AccessSeeder::class, BooksSeeder::class]);
    $this->owner = User::factory()->create();
    $this->owner->assignRole(Access::OWNER);
    $this->gffs = Client::create(['name' => 'GFFS', 'type' => 'client', 'status' => 'active']);
    $this->other = Client::create(['name' => 'Edge', 'type' => 'client', 'status' => 'active']);
    $account = PilotAccount::create(['pilot_id' => 7, 'name' => 'Ads', 'platform' => 'meta', 'currency' => 'USD']);
    $make = fn (string $name, Client $client) => Campaign::create([
        'pilot_campaign_id' => crc32($name), 'pilot_account_id' => $account->id, 'name' => $name, 'status' => 'ACTIVE',
        'stop_at' => now()->addDays(2)->startOfDay(), 'client_id' => $client->id, 'bill_from' => now()->subWeek()->toDateString(),
        'spend_total' => 0, 'spend_7d' => 0,
    ]);
    $this->mine = $make('GFFS Sales', $this->gffs);
    $this->theirs = $make('Edge Fair', $this->other);
    CampaignDay::create(['campaign_id' => $this->mine->id, 'day' => now()->toDateString(), 'spend' => 176, 'results' => 13, 'clicks' => 97]);
    CampaignDay::create(['campaign_id' => $this->mine->id, 'day' => now()->subDays(10)->toDateString(), 'spend' => 500, 'results' => 5, 'clicks' => 20]);
    CampaignDay::create(['campaign_id' => $this->theirs->id, 'day' => now()->toDateString(), 'spend' => 9999, 'results' => 1, 'clicks' => 1]);
});

function signUp(): User
{
    test()->post('/portal/register', [
        'name' => 'Rahim', 'company' => 'GFFS Ltd', 'phone' => '01712345678', 'email' => 'rahim@gffs.test',
        'password' => 'secret-pass-1', 'password_confirmation' => 'secret-pass-1',
    ])->assertRedirect('/portal');

    return User::where('email', 'rahim@gffs.test')->sole();
}

it('lets a client sign up, waits for the Owner, and keeps them out of the books', function () {
    $user = signUp();

    expect($user->isClient())->toBeTrue()
        ->and($user->client_id)->toBeNull()
        ->and($this->owner->notifications()->where('data->kind', 'portal_signup')->count())->toBe(1);

    $this->actingAs($user)->get('/portal')->assertInertia(fn ($page) => $page->component('portal/Pending'));
    $this->actingAs($user)->get('/portal/campaigns')->assertForbidden();

    // Any page of the books sends them home; a form there is refused.
    $this->actingAs($user)->get('/dashboard')->assertRedirect('/portal');
    $this->actingAs($user)->get('/clients')->assertRedirect('/portal');
    $this->actingAs($user)->post('/invoices', [])->assertForbidden();
    $this->actingAs($user)->get('/settings/profile')->assertOk();
});

it('shows a linked client only their own campaigns, spend over a period, and invoices', function () {
    $user = signUp();
    $this->actingAs($this->owner)->post("/clients/{$this->gffs->id}/portal-users", ['user_id' => $user->id])->assertRedirect();
    expect($user->fresh()->client_id)->toBe($this->gffs->id);

    $this->actingAs($user->fresh())->get('/portal')->assertOk()
        ->assertInertia(fn ($page) => $page->component('portal/Home')
            ->where('client', 'GFFS')
            ->where('week.6.spend', 176)
            ->has('running', 1)
            ->where('running.0.name', 'GFFS Sales'));

    // Today, a week, a month: the other client's campaign never shows.
    $this->actingAs($user->fresh())->get('/portal/campaigns?range=today')
        ->assertInertia(fn ($page) => $page->component('portal/Campaigns')->where('totals.spend', 176)->has('campaigns', 1)->where('campaigns.0.results', 13));
    $this->actingAs($user->fresh())->get('/portal/campaigns?range=30d')
        ->assertInertia(fn ($page) => $page->where('totals.spend', 676)->has('series', 30));
    $this->actingAs($user->fresh())->get('/portal/campaigns?range=custom&from='.now()->subDays(12)->toDateString().'&to='.now()->subDays(8)->toDateString())
        ->assertInertia(fn ($page) => $page->where('totals.spend', 500));

    $this->actingAs($user->fresh())->get("/portal/campaigns/{$this->mine->id}")->assertOk()
        ->assertInertia(fn ($page) => $page->component('portal/Campaign')->where('campaign.name', 'GFFS Sales'));
    $this->actingAs($user->fresh())->get("/portal/campaigns/{$this->theirs->id}")->assertNotFound();

    $line = ['kind' => 'service', 'description' => 'Page', 'usd' => null, 'rate' => null, 'amount' => 100000, 'campaign_id' => null, 'period_from' => null, 'period_to' => null, 'results' => null, 'clicks' => null];
    app(Invoices::class)->save(['client_id' => $this->gffs->id, 'issue_date' => now()->toDateString(), 'due_date' => null, 'notes' => null, 'items' => [$line]]);
    app(Invoices::class)->save(['client_id' => $this->other->id, 'issue_date' => now()->toDateString(), 'due_date' => null, 'notes' => null, 'items' => [$line]]);
    $this->actingAs($user->fresh())->get('/portal/invoices')
        ->assertInertia(fn ($page) => $page->component('portal/Invoices')->has('invoices', 1)->where('invoices.0.number', Invoice::where('client_id', $this->gffs->id)->value('number')));

    // Unlinked: back to waiting.
    $this->actingAs($this->owner)->delete("/clients/{$this->gffs->id}/portal-users/{$user->id}")->assertRedirect();
    $this->actingAs($user->fresh())->get('/portal')->assertInertia(fn ($page) => $page->component('portal/Pending'));
});

it('does not let anyone link a staff account to a client', function () {
    $staff = User::factory()->create();
    $staff->assignRole('Accountant');

    $this->actingAs($this->owner)->post("/clients/{$this->gffs->id}/portal-users", ['user_id' => $staff->id])->assertNotFound();
    expect($staff->fresh()->client_id)->toBeNull();
});
