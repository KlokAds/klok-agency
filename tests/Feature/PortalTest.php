<?php

use App\Models\Campaign;
use App\Models\CampaignDay;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\PilotAccount;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\AgencyAlert;
use App\Services\CampaignAlerts;
use App\Services\Invoices;
use App\Support\Access;
use Database\Seeders\AccessSeeder;
use Database\Seeders\BooksSeeder;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Mail;

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

    // By default the whole life of each campaign: the old day counts too, a bar a week past three months.
    CampaignDay::create(['campaign_id' => $this->mine->id, 'day' => now()->subDays(120)->toDateString(), 'spend' => 300, 'results' => 2, 'clicks' => 4]);
    $this->actingAs($user->fresh())->get('/portal/campaigns')
        ->assertInertia(fn ($page) => $page->where('range.key', 'all')->where('totals.spend', 976)->where('totals.results', 20)
            ->where('series', fn ($series) => count($series) <= 19));

    $this->actingAs($user->fresh())->get("/portal/campaigns/{$this->mine->id}")->assertOk()
        ->assertInertia(fn ($page) => $page->component('portal/Campaign')->where('campaign.name', 'GFFS Sales'));
    $this->actingAs($user->fresh())->get("/portal/campaigns/{$this->theirs->id}")->assertNotFound();

    $line = ['kind' => 'service', 'description' => 'Page', 'usd' => null, 'rate' => null, 'amount' => 100000, 'campaign_id' => null, 'period_from' => null, 'period_to' => null, 'results' => null, 'clicks' => null];
    $shared = app(Invoices::class)->save(['client_id' => $this->gffs->id, 'issue_date' => now()->toDateString(), 'due_date' => null, 'notes' => null, 'items' => [$line]]);
    app(Invoices::class)->markSent($shared);
    // Made but never sent: the client does not see it.
    app(Invoices::class)->save(['client_id' => $this->gffs->id, 'issue_date' => now()->toDateString(), 'due_date' => null, 'notes' => null, 'items' => [$line]]);
    app(Invoices::class)->save(['client_id' => $this->other->id, 'issue_date' => now()->toDateString(), 'due_date' => null, 'notes' => null, 'items' => [$line]]);
    $this->actingAs($user->fresh())->get('/portal/invoices')
        ->assertInertia(fn ($page) => $page->component('portal/Invoices')->has('invoices', 1)->where('invoices.0.number', $shared->number));

    // Unlinked: back to waiting.
    $this->actingAs($this->owner)->delete("/clients/{$this->gffs->id}/portal-users/{$user->id}")->assertRedirect();
    $this->actingAs($user->fresh())->get('/portal')->assertInertia(fn ($page) => $page->component('portal/Pending'));
});

it('tells the client of a new invoice, its payment, the day\'s result and a campaign ending — by email too', function () {
    $user = signUp();
    $user->forceFill(['client_id' => $this->gffs->id])->save();
    Mail::fake();
    Setting::put('smtp_username', 'billing@klokads.com');
    Setting::put('smtp_password', Crypt::encryptString('x'));
    $kinds = fn () => $user->fresh()->notifications()->pluck('data')->pluck('kind')->all();

    // A new invoice, shared: the client hears, with the link to pay.
    $line = ['kind' => 'service', 'description' => 'Page', 'usd' => null, 'rate' => null, 'amount' => 100000, 'campaign_id' => null, 'period_from' => null, 'period_to' => null, 'results' => null, 'clicks' => null];
    $invoice = app(Invoices::class)->save(['client_id' => $this->gffs->id, 'issue_date' => now()->toDateString(), 'due_date' => null, 'notes' => null, 'items' => [$line]]);
    app(Invoices::class)->markSent($invoice);
    expect($kinds())->toContain('client_invoice')
        ->and($user->fresh()->notifications()->where('data->kind', 'client_invoice')->sole()->data['url'])->toBe('/i/'.$invoice->public_token);

    // Paid by bank, confirmed by hand: thanks.
    $payment = $invoice->payments()->create(['method' => 'bank', 'amount' => 100000, 'status' => 'pending']);
    app(Invoices::class)->verify($payment, 100000, $this->owner);
    expect($kinds())->toContain('client_paid');

    // Home shows every taka they paid, with its date and invoice — the whole amount.
    $this->actingAs($user->fresh())->get('/portal')
        ->assertInertia(fn ($page) => $page->where('lifetime.paid_bdt', 100000)
            ->where('payments.0.amount', 100000)
            ->where('payments.0.invoice', $invoice->number)
            ->where('payments.0.refund', false));

    // The evening: the day's result, and the campaign that ends tomorrow — in plain words.
    $this->travelTo(now()->setTime(21, 30));
    app(CampaignAlerts::class)->run();
    $ending = $user->fresh()->notifications()->where('data->kind', 'client_ending')->sole();
    expect($kinds())->toContain('client_summary')
        ->and($ending->data['params']['time'])->toBe(now()->addDay()->format('j M').' রাত শেষে (রাত 12টায়) বন্ধ');

    // Email as well as the bell and the phone.
    expect((new AgencyAlert('client_paid', 'x'))->via($user->fresh()))->toContain('mail');

    // The portal's list, which reads them.
    $this->actingAs($user->fresh())->get('/portal/notifications')
        ->assertInertia(fn ($page) => $page->component('portal/Notifications')->has('notifications', 4));
    expect($user->fresh()->unreadNotifications()->count())->toBe(0);
});

it('lets a client change their own name, email, phone and password, and tells the Owner', function () {
    $user = signUp();
    $user->forceFill(['client_id' => $this->gffs->id])->save();

    $this->actingAs($user->fresh())->patch('/settings/profile', [
        'name' => 'Akbor Hossain', 'email' => 'akbor@gffs.test', 'phone' => '01812345678', 'company' => 'GFFS',
    ])->assertRedirect();

    expect($user->fresh())->name->toBe('Akbor Hossain')->email->toBe('akbor@gffs.test')->phone->toBe('01812345678')
        ->and($this->owner->notifications()->where('data->kind', 'portal_profile')->sole()->data['params']['what'])->toContain('email');

    $this->actingAs($user->fresh())->withSession(['auth.password_confirmed_at' => time()])->put('/settings/password', [
        'current_password' => 'secret-pass-1', 'password' => 'newpass-123', 'password_confirmation' => 'newpass-123',
    ])->assertRedirect();
    expect($this->owner->notifications()->where('data->kind', 'portal_profile')->count())->toBe(2);

    // The team cannot change their own sign-in email here.
    $this->owner->forceFill(['email' => 'owner@klok.test'])->save();
    $this->actingAs($this->owner)->patch('/settings/profile', ['name' => 'Owner', 'email' => 'other@klok.test'])->assertRedirect();
    expect($this->owner->fresh()->email)->toBe('owner@klok.test');
});

it('does not let anyone link a staff account to a client', function () {
    $staff = User::factory()->create();
    $staff->assignRole('Accountant');

    $this->actingAs($this->owner)->post("/clients/{$this->gffs->id}/portal-users", ['user_id' => $staff->id])->assertNotFound();
    expect($staff->fresh()->client_id)->toBeNull();
});
