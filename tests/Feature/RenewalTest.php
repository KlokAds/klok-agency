<?php

use App\Mail\RenewalMail;
use App\Models\Campaign;
use App\Models\CampaignDay;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\PilotAccount;
use App\Models\Renewal;
use App\Models\Setting;
use App\Models\User;
use App\Services\CampaignBilling;
use App\Services\CampaignLimits;
use App\Services\Renewals;
use App\Support\Access;
use Database\Seeders\AccessSeeder;
use Database\Seeders\BooksSeeder;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    $this->seed([AccessSeeder::class, BooksSeeder::class]);
    $this->artisan('books:import-sheet')->assertSuccessful();

    $this->owner = User::factory()->create();
    $this->owner->assignRole(Access::OWNER);

    Setting::put('pilot_token', Crypt::encryptString('agency-token'));
    $this->account = PilotAccount::create(['pilot_id' => 9, 'name' => 'Klok Ads Agency', 'platform' => 'meta', 'currency' => 'USD', 'seen_at' => now()]);
    $this->gffs = Client::where('name', 'GFFS')->first();
    $this->gffs->update(['phone' => '01711111111']);

    $this->day1 = now()->subDays(2)->toDateString();
    $this->day2 = now()->subDay()->toDateString();
    $this->end = now()->addDay()->setTime(23, 59)->toIso8601String();
    $this->limits = [];

    // Klok Pilot: a GFFS campaign at $2 a day that ends tomorrow, two days of spend.
    Http::fake(function ($request) {
        $args = $request['params']['arguments'] ?? [];
        $content = match ($request['params']['name']) {
            'ad_account_campaigns' => ['currency' => 'USD', 'campaigns' => [
                ['campaign_id' => 101, 'name' => 'GFFS - Messages', 'status' => 'ACTIVE', 'start_time' => now()->subDays(2)->toIso8601String(), 'stop_time' => $this->end, 'spend_total' => 9, 'spend_7d' => 9, 'last_spend_day' => $this->day2],
            ]],
            'campaign_daily_spend' => ['currency' => 'USD', 'rows' => [
                ['date' => $this->day1, 'campaign_id' => 101, 'spend' => 2.12, 'results' => 7],
                ['date' => $this->day2, 'campaign_id' => 101, 'spend' => 1.92, 'results' => 6],
            ]],
            'set_campaign_limits' => (function () use ($args) {
                $this->limits[] = $args;

                return [
                    'daily_budget' => 2.0, 'spend_cap' => $args['spend_cap'] ?? null, 'active' => $args['active'] ?? true,
                    'end' => isset($args['new_end']) ? $args['new_end'].'T23:59:00+06:00' : $this->end,
                    'extendable' => true, 'why_not' => null, 'spend_cap_error' => null, 'spent_before' => 0,
                ];
            })(),
            default => [],
        };

        return Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => ['structuredContent' => $content]]);
    });

    app(CampaignBilling::class)->refreshCampaigns($this->account);
    $this->campaign = Campaign::where('pilot_campaign_id', 101)->sole();
    $this->campaign->update(['client_id' => $this->gffs->id, 'bill_from' => $this->day1]);
    app(CampaignBilling::class)->sync();
});

function payGffs(object $test, string $bdt, string $usd): void
{
    $test->actingAs($test->owner)->post('/quick/client-payment', [
        'entry_date' => now()->toDateString(), 'client_id' => $test->gffs->id, 'category' => 'client_payment',
        'pay_via' => 'bank', 'wallet' => 'bank', 'amount' => $bdt, 'usd_credit' => $usd,
    ])->assertRedirect();
}

it('caps a prepaid campaign on Meta at what the client paid for, and pauses it when the money is gone', function () {
    // $10 of credit, $4.04 spent: the cap is what it spent plus what is left.
    payGffs($this, '1500', '10');
    app(CampaignLimits::class)->guard();

    $cap = collect($this->limits)->firstWhere('spend_cap');
    expect($cap['spend_cap'])->toBe(10.0)
        ->and($this->campaign->fresh()->spend_cap)->toBe(1000)
        ->and($this->campaign->fresh()->budget)->toBe(200);

    // A small move is not worth a call to Meta.
    $calls = count($this->limits);
    app(CampaignLimits::class)->guard();
    expect(count($this->limits))->toBe($calls);

    // Spend passes the credit: paused, and the bell says so.
    CampaignDay::where('campaign_id', $this->campaign->id)->whereDate('day', $this->day2)->update(['spend' => 900]);
    app(CampaignBilling::class)->rebuild([$this->gffs->id]);
    app(CampaignLimits::class)->guard();

    expect(collect($this->limits)->last()['active'])->toBeFalse()
        ->and($this->campaign->fresh()->paused_for_credit_at)->not->toBeNull()
        ->and($this->owner->notifications()->where('data->kind', 'credit_out')->count())->toBe(1);
});

it('leaves clients who pay after the spend alone', function () {
    app(CampaignLimits::class)->guard();

    expect(collect($this->limits)->where('spend_cap', '!=', null)->count())->toBe(0);
});

it('makes the renewal link two days before the end, and the client pays for more days', function () {
    payGffs($this, '1500', '10');
    Mail::fake();
    Setting::put('smtp_username', 'billing@klokads.com');
    config(['mail.default' => 'smtp', 'mail.from.address' => 'billing@klokads.com']);
    app(CampaignLimits::class)->refresh($this->campaign, true);

    expect(app(Renewals::class)->openDue())->toBe(1)
        ->and(app(Renewals::class)->openDue())->toBe(0);
    $renewal = Renewal::sole();
    expect($renewal->budget)->toBe(200)
        ->and($this->owner->notifications()->where('data->kind', 'renewal_open')->count())->toBe(1);

    // The admin page has the link ready for WhatsApp.
    $this->actingAs($this->owner)->get("/renewals/{$renewal->id}")->assertOk()
        ->assertInertia(fn ($page) => $page->component('agency/Renewal')
            ->where('renewal.url', $renewal->publicUrl())
            ->where('renewal.message.phone', '8801711111111'));

    // The client's page: no sign-in.
    auth()->logout();
    $this->get("/r/{$renewal->token}")->assertOk()
        ->assertInertia(fn ($page) => $page->component('public/Renewal')
            ->where('renewal.budget', 200)
            ->where('renewal.spent', 404));
    $this->get('/r/'.str_repeat('x', 40))->assertNotFound();

    // 7 days × $2 = $14 at the client's rate.
    $this->post("/r/{$renewal->token}/choose", ['days' => 7])->assertRedirect();
    $invoice = Invoice::sole();
    expect($invoice->usd)->toBe(1400)
        ->and($invoice->status)->toBe('sent')
        ->and($renewal->fresh()->status)->toBe('invoiced');

    // Paid in full: the admin hears, nothing moves on Meta yet.
    $this->actingAs($this->owner)->post("/invoices/{$invoice->id}/pay", [
        'method' => 'bank', 'wallet' => 'bank', 'paid_on' => now()->toDateString(), 'amount' => (string) ($invoice->total / 100),
    ])->assertRedirect();
    expect($renewal->fresh()->status)->toBe('paid')
        ->and(collect($this->limits)->whereNotNull('new_end')->count())->toBe(0);
    Mail::assertSent(RenewalMail::class, fn ($m) => $m->event === 'paid' && $m->hasTo('adsklok@gmail.com'));

    // The OK: Pilot moves the end 7 days past the current one.
    $this->actingAs($this->owner)->post("/renewals/{$renewal->id}/approve")->assertRedirect();
    $newEnd = now()->addDay()->addDays(7)->toDateString();
    expect(collect($this->limits)->last()['new_end'])->toBe($newEnd)
        ->and($renewal->fresh()->status)->toBe('approved')
        ->and($renewal->fresh()->new_end->toDateString())->toBe($newEnd);
});

it('tells the admin when the client ends the contract', function () {
    payGffs($this, '1500', '10');
    Mail::fake();
    config(['mail.default' => 'smtp', 'mail.from.address' => 'billing@klokads.com']);
    app(CampaignLimits::class)->refresh($this->campaign, true);
    app(Renewals::class)->openDue();
    $renewal = Renewal::sole();

    $this->post("/r/{$renewal->token}/decline", ['confirm' => 1])->assertRedirect();

    expect($renewal->fresh()->status)->toBe('declined')
        ->and(collect($this->limits)->whereNotNull('new_end')->count())->toBe(0);
    Mail::assertSent(RenewalMail::class, fn ($m) => $m->event === 'declined');
});

it('extends a campaign by hand and switches a paused one back on', function () {
    $this->campaign->update(['paused_for_credit_at' => now()]);

    $this->actingAs($this->owner)->post("/campaigns/{$this->campaign->id}/extend", ['days' => 5])->assertRedirect();

    $last = collect($this->limits)->last();
    expect($last['new_end'])->toBe(now()->addDay()->addDays(5)->toDateString())
        ->and($last['active'])->toBeTrue()
        ->and($this->campaign->fresh()->paused_for_credit_at)->toBeNull();
});

it('shows each Meta campaign with what Meta still takes, what it will spend, and the client\'s due', function () {
    payGffs($this, '600', '4');
    app(CampaignLimits::class)->refresh($this->campaign, true);

    $this->actingAs($this->owner)->get('/dollars')->assertOk()
        ->assertInertia(fn ($page) => $page->component('books/Dollars')
            ->where('forecast.clients.0.name', 'GFFS')
            ->where('forecast.clients.0.campaigns.0.pending', 404)
            ->where('forecast.clients.0.campaigns.0.budget', 200)
            ->where('forecast.clients.0.campaigns.0.days_left', 2)
            ->where('forecast.clients.0.to_spend', 400)
            // $4 paid, $4.04 spent: −$0.04 left, and $4 more to spend → $4.04 due.
            ->where('forecast.clients.0.balance', -404));

    // The client page holds budget against spend.
    CampaignDay::where('campaign_id', $this->campaign->id)->update(['budget' => 200]);
    $this->actingAs($this->owner)->get("/clients/{$this->gffs->id}")->assertOk()
        ->assertInertia(fn ($page) => $page->where('budget_days.over', 12)->where('budget_days.under', 8));
});

it('keeps the mailbox password encrypted and never sends it back', function () {
    $this->actingAs($this->owner)->put('/admin/settings/mail', [
        'host' => 'smtp.hostinger.com', 'port' => 465, 'username' => 'billing@klokads.com', 'password' => 'secret-pass',
        'from_name' => 'Klok Ads', 'notify_email' => 'adsklok@gmail.com',
    ])->assertRedirect();

    expect(Setting::get('smtp_password'))->not->toBe('secret-pass')
        ->and(Crypt::decryptString(Setting::get('smtp_password')))->toBe('secret-pass');

    $this->actingAs($this->owner)->get('/admin/settings')->assertOk()
        ->assertInertia(fn ($page) => $page->where('mail.has_password', true)->missing('mail.password'));
});

it('reads a midnight end as the day before', function () {
    $this->campaign->update(['stop_at' => '2026-10-26T00:00:00+06:00']);
    expect($this->campaign->fresh()->lastDay()->toDateString())->toBe('2026-10-25');

    $this->campaign->update(['stop_at' => '2026-10-30T23:59:00+06:00']);
    expect($this->campaign->fresh()->lastDay()->toDateString())->toBe('2026-10-30');
});
