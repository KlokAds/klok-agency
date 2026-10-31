<?php

use App\Mail\RenewalMail;
use App\Models\Account;
use App\Models\BdtEntry;
use App\Models\Campaign;
use App\Models\CampaignDay;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\PilotAccount;
use App\Models\Renewal;
use App\Models\Setting;
use App\Models\UsdEntry;
use App\Models\User;
use App\Services\Books;
use App\Services\CampaignBilling;
use App\Services\CampaignLimits;
use App\Services\Invoices;
use App\Services\PlatformDue;
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

    // The client sends the money by hand and gives the transaction; nothing moves until it is checked.
    Setting::put('pay_bkash_on', '1');
    Setting::put('pay_bkash_number', '01700000001');
    Setting::put('pay_charge_mfs', '0');
    $this->postJson(route('invoice.public.claim', $invoice->public_token), ['method' => 'bkash', 'reference' => 'BKX4455667', 'sender' => '01712345678'])->assertOk();
    $claim = $invoice->payments()->where('reference', 'BKX4455667')->sole();
    expect($renewal->fresh()->status)->toBe('invoiced')
        ->and(collect($this->limits)->whereNotNull('new_end')->count())->toBe(0);

    // The Owner verifies it: the client chose the days, so the campaign runs 7 days past its end at once.
    app(Invoices::class)->verify($claim, $invoice->fresh()->due(), $this->owner);
    $newEnd = now()->addDay()->addDays(7)->toDateString();
    expect(collect($this->limits)->last()['new_end'])->toBe($newEnd)
        ->and($renewal->fresh()->status)->toBe('approved')
        ->and($renewal->fresh()->new_end->toDateString())->toBe($newEnd);
});

it('lets the client pick the days on the invoice at its own rate, extends once paid, or stop', function () {
    payGffs($this, '1500', '10');
    app(CampaignLimits::class)->refresh($this->campaign, true);
    $this->gffs->update(['renew_days' => 7]);
    app(Renewals::class)->openDue();
    $invoice = Invoice::sole();
    // The agency set the rate by hand: the days are priced at it, not at today's.
    $invoice->items()->sole()->forceFill(['rate' => 150])->save();

    $this->get("/i/{$invoice->public_token}")->assertInertia(fn ($page) => $page->where('state.renewal.days', 7)->where('state.renewal.rate', 150));
    $this->postJson("/i/{$invoice->public_token}/days", ['days' => 15])->assertOk()
        ->assertJsonPath('renewal.days', 15)
        ->assertJsonPath('due', 450000); // 15 × $2 × ৳150

    expect($invoice->fresh()->total)->toBe(450000)
        ->and($invoice->items()->sole()->description)->toContain('15 days')
        ->and(Renewal::sole()->days)->toBe(15);

    // Paid in full: extended by the 15 days.
    $this->actingAs($this->owner)->post("/invoices/{$invoice->id}/pay", [
        'method' => 'bank', 'wallet' => 'bank', 'paid_on' => now()->toDateString(), 'amount' => '4500',
    ])->assertRedirect();
    expect(Renewal::sole()->status)->toBe('approved')
        ->and(collect($this->limits)->last()['new_end'])->toBe(now()->addDay()->addDays(15)->toDateString());
});

it('lets the client say no on the invoice: it is withdrawn and the campaign stops at its end', function () {
    payGffs($this, '1500', '10');
    app(CampaignLimits::class)->refresh($this->campaign, true);
    $this->gffs->update(['renew_days' => 7]);
    app(Renewals::class)->openDue();
    $invoice = Invoice::sole();
    $person = User::factory()->create(['client_id' => $this->gffs->id]);
    $person->assignRole(Access::CLIENT);

    $this->postJson("/i/{$invoice->public_token}/stop")->assertOk()->assertJsonPath('renewal.declined', true);
    expect(Renewal::sole()->status)->toBe('declined')
        ->and($invoice->fresh()->status)->toBe('void')
        ->and(collect($this->limits)->whereNotNull('new_end')->count())->toBe(0);

    // The client's own people get it in writing too.
    expect($person->notifications()->where('data->kind', 'client_declined')->count())->toBe(1);
});

it('makes an auto-renewal client\'s invoice by itself, and extends the campaign once it is paid', function () {
    payGffs($this, '1500', '10');
    Mail::fake();
    app(CampaignLimits::class)->refresh($this->campaign, true);
    $this->gffs->update(['renew_days' => 7]);

    expect(app(Renewals::class)->openDue())->toBe(1);
    $renewal = Renewal::sole();
    $invoice = Invoice::sole();

    // Its dates follow the campaign: due on its last day, and the 7 days start the day after.
    $from = now()->addDays(2);
    expect($invoice->issue_date->toDateString())->toBe(now()->toDateString())
        ->and($invoice->due_date->toDateString())->toBe(now()->addDay()->toDateString())
        ->and($invoice->items()->sole()->description)->toContain('7 days ('.$from->format('j M').' – '.$from->addDays(6)->format('j M Y').')');

    // 7 days × $2, sent, and the team told to pass it on (not to send a link).
    expect($invoice->usd)->toBe(1400)
        ->and($invoice->status)->toBe('sent')
        ->and($renewal->status)->toBe('invoiced')
        ->and($renewal->days)->toBe(7)
        ->and($this->owner->notifications()->where('data->kind', 'renewal_invoiced')->count())->toBe(1)
        ->and($this->owner->notifications()->where('data->kind', 'renewal_open')->count())->toBe(0);

    // Paid in full: no OK needed, Pilot moves the end 7 days on.
    $this->actingAs($this->owner)->post("/invoices/{$invoice->id}/pay", [
        'method' => 'bank', 'wallet' => 'bank', 'paid_on' => now()->toDateString(), 'amount' => (string) ($invoice->total / 100),
    ])->assertRedirect();

    $newEnd = now()->addDay()->addDays(7)->toDateString();
    expect($renewal->fresh()->status)->toBe('approved')
        ->and($renewal->fresh()->approved_by)->toBeNull()
        ->and(collect($this->limits)->last()['new_end'])->toBe($newEnd)
        ->and($this->owner->notifications()->where('data->kind', 'renewal_extended')->count())->toBe(1)
        ->and($this->owner->notifications()->where('data->kind', 'renewal_paid')->count())->toBe(0);
});

it('moves an unpaid renewal and its invoice along when the end moves, instead of making a second one', function () {
    payGffs($this, '1500', '10');
    app(CampaignLimits::class)->refresh($this->campaign, true);
    $this->gffs->update(['renew_days' => 7]);

    expect(app(Renewals::class)->openDue())->toBe(1);
    $invoice = Invoice::sole();

    // The credit left runs it a day more (as on 1 Nov 2026), and the ticker comes round again.
    $this->campaign->update(['stop_at' => now()->addDays(2)->setTime(23, 59)]);
    expect(app(Renewals::class)->openDue())->toBe(0);

    $from = now()->addDays(3);
    expect(Invoice::count())->toBe(1)
        ->and(Renewal::sole()->ends_on->toDateString())->toBe(now()->addDays(2)->toDateString())
        ->and($invoice->fresh()->number)->toBe($invoice->number)
        ->and($invoice->fresh()->total)->toBe($invoice->total)
        ->and($invoice->fresh()->due_date->toDateString())->toBe(now()->addDays(2)->toDateString())
        ->and($invoice->items()->sole()->description)->toContain('7 days ('.$from->format('j M').' – '.$from->addDays(6)->format('j M Y').')')
        ->and($this->owner->notifications()->where('data->kind', 'renewal_invoiced')->count())->toBe(1);
});

it('prices a fixed-rate client\'s renewal at their rate, set on the client form', function () {
    payGffs($this, '1500', '10');
    app(CampaignLimits::class)->refresh($this->campaign, true);

    $form = ['name' => 'GFFS', 'status' => 'active', 'renew_days' => 7, 'rate_mode' => 'fixed', 'fixed_rate' => ''];
    $this->actingAs($this->owner)->put("/clients/{$this->gffs->id}", $form)->assertSessionHasErrors('fixed_rate');
    $this->actingAs($this->owner)->put("/clients/{$this->gffs->id}", [...$form, 'fixed_rate' => '150'])->assertSessionHasNoErrors();

    expect(app(Renewals::class)->openDue())->toBe(1);
    $invoice = Invoice::sole();

    expect($invoice->items()->sole()->rate)->toEqual(150.0)
        ->and($invoice->total)->toBe(210000)
        ->and(Invoices::clientRate($this->gffs->fresh()))->toEqual(150.0);

    // Back to commission: their own percent on the FX batch, not the agency's.
    $this->actingAs($this->owner)->put("/clients/{$this->gffs->id}", [...$form, 'rate_mode' => 'commission', 'commission_percent' => '10'])->assertSessionHasNoErrors();
    $fx = Invoices::fxRate();
    expect(Invoices::clientRate($this->gffs->fresh()))->toEqual(round($fx * 1.1, 2))
        ->and(Invoices::clientRate())->toEqual(round($fx * (1 + Invoices::commissionPercent() / 100), 2));
});

it('gives a link already open its invoice once the client is switched to auto renewal', function () {
    payGffs($this, '1500', '10');
    app(CampaignLimits::class)->refresh($this->campaign, true);

    expect(app(Renewals::class)->openDue())->toBe(1)
        ->and(Invoice::count())->toBe(0);

    $this->gffs->update(['renew_days' => 5]);
    expect(app(Renewals::class)->openDue())->toBe(1)
        ->and(Invoice::sole()->usd)->toBe(1000)
        ->and(app(Renewals::class)->openDue())->toBe(0);
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
            // Ends tomorrow night: one full day after today, plus today's budget still to run.
            ->where('forecast.clients.0.campaigns.0.days_left', 1)
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

it('puts spend not paid for yet on the renewal invoice first, so the new money clears it before the days', function () {
    $this->campaign->update(['budget' => 200]);
    app(Renewals::class)->openDue();
    $renewal = Renewal::sole();

    // No money in yet: the $4.04 already spent is due.
    $this->get("/r/{$renewal->token}")->assertInertia(fn ($page) => $page->where('renewal.due', 404));
    $this->post("/r/{$renewal->token}/choose", ['days' => 7])->assertRedirect();

    $invoice = Invoice::with('items')->sole();
    expect($invoice->usd)->toBe(404 + 1400)
        ->and($invoice->items->pluck('usd')->all())->toBe([404, 1400]);

    // Paid: the old spend is settled first, and the 7 days are what is left.
    $this->actingAs($this->owner)->post("/invoices/{$invoice->id}/pay", [
        'method' => 'bank', 'wallet' => 'bank', 'paid_on' => now()->toDateString(), 'amount' => (string) ($invoice->total / 100),
    ])->assertRedirect();
    $client = collect(app(Books::class)->clients())->firstWhere('id', $this->gffs->id);
    expect($client['credit_left'])->toBe(1400)
        ->and(UsdEntry::where('source', 'pilot')->pluck('status')->unique()->all())->toBe(['settled']);
});

it('lets a card charge say which campaign it paid for, and pays the rest oldest first', function () {
    $other = Campaign::create(['pilot_campaign_id' => 202, 'pilot_account_id' => $this->account->id, 'name' => 'GFFS - Leads', 'status' => 'ACTIVE', 'client_id' => $this->gffs->id, 'bill_from' => $this->day1]);
    CampaignDay::create(['campaign_id' => $other->id, 'day' => $this->day2, 'spend' => 300, 'results' => 1, 'clicks' => 2]);
    app(CampaignBilling::class)->rebuild([$this->gffs->id]);
    $meta = Account::where('code', 'meta')->first();

    // Before: the suggestion for a $3 charge pays the oldest spend.
    $rows = $this->actingAs($this->owner)->getJson("/dollars/pending/{$meta->id}?amount=3")->assertOk()->json('rows');
    $keys = collect($rows)->keyBy('key');
    expect($keys["c:{$other->id}"]['pending'])->toBe(300)
        ->and($keys["c:{$this->campaign->id}"]['pending'])->toBe(404);

    // Meta's invoice says the $3 was for GFFS - Leads.
    $this->actingAs($this->owner)->post('/quick/card-charge', [
        'entry_date' => now()->toDateString(), 'account_id' => $meta->id, 'amount' => '3', 'allocations' => ["c:{$other->id}" => '3'],
    ])->assertRedirect();

    $pending = (new PlatformDue(new Books))->pending($meta);
    expect($pending["c:{$other->id}"] ?? 0)->toBe(0)
        ->and($pending["c:{$this->campaign->id}"])->toBe(404);

    // More than the charge is refused.
    $this->actingAs($this->owner)->post('/quick/card-charge', [
        'entry_date' => now()->toDateString(), 'account_id' => $meta->id, 'amount' => '1', 'allocations' => ["c:{$other->id}" => '5'],
    ])->assertSessionHasErrors('allocations');
});

it('prices spend at the FX batch that paid for it plus commission, not today\'s rate', function () {
    $rows = UsdEntry::where('source', 'pilot')->get();
    $fifo = app(Books::class)->fifo();
    $cost = $rows->sum(fn ($r) => $fifo['entries'][$r->id]['cost']);
    $expected = round($cost / -$rows->sum('amount') * 1.2, 2);

    expect(Invoices::rateFor($rows))->toBe($expected);

    $list = $this->actingAs($this->owner)->getJson('/invoices/campaigns?'.http_build_query(['client' => $this->gffs->id, 'from' => $this->day1, 'to' => $this->day2]))->json('campaigns');
    expect($list[0]['rate'])->toBe($expected);
});

it('extends a campaign from an ordinary invoice\'s advance line: its USD buys the days, approved after payment', function () {
    payGffs($this, '1500', '10');
    app(CampaignLimits::class)->refresh($this->campaign, true);

    // $14 for this campaign at $2 a day: 7 more days.
    $this->actingAs($this->owner)->post('/invoices', [
        'client_id' => $this->gffs->id, 'issue_date' => now()->toDateString(), 'due_date' => null, 'notes' => null,
        'items' => [['kind' => 'ad_budget', 'description' => 'Advance', 'campaign_id' => $this->campaign->id, 'period_from' => null, 'period_to' => null, 'results' => null, 'clicks' => null, 'usd' => '14', 'rate' => '150', 'amount' => '']],
    ])->assertRedirect();
    $invoice = Invoice::with('items')->latest('id')->first();
    expect($invoice->items->first()->days)->toBe(7)
        ->and(Renewal::count())->toBe(0);

    $this->actingAs($this->owner)->post("/invoices/{$invoice->id}/pay", [
        'method' => 'bank', 'wallet' => 'bank', 'paid_on' => now()->toDateString(), 'amount' => (string) ($invoice->total / 100),
    ])->assertRedirect();

    $renewal = Renewal::sole();
    expect($renewal->status)->toBe('paid')
        ->and($renewal->days)->toBe(7)
        ->and(collect($this->limits)->whereNotNull('new_end')->count())->toBe(0);

    $this->actingAs($this->owner)->post("/renewals/{$renewal->id}/approve")->assertRedirect();
    expect(collect($this->limits)->last()['new_end'])->toBe(now()->addDay()->addDays(7)->toDateString());
});

it('refunds the unspent advance at the rate it was paid, and the credit is 0', function () {
    payGffs($this, '1500', '10');
    $books = fn () => collect(app(Books::class)->clients())->firstWhere('id', $this->gffs->id);
    expect($books()['credit_left'])->toBe(596)
        ->and($books()['unearned'])->toBe(89400);

    $this->actingAs($this->owner)->post("/clients/{$this->gffs->id}/refund", ['entry_date' => now()->toDateString(), 'wallet' => 'bank'])->assertRedirect();

    $row = BdtEntry::where('category', 'client_refund')->sole();
    expect($row->amount)->toBe(-89400)
        ->and($row->usd_credit)->toBe(-596)
        ->and($books()['credit_left'])->toBe(0)
        ->and($books()['unearned'])->toBe(0)
        ->and(app(Books::class)->summary()['equity_ok'])->toBeTrue();

    // Nothing left: a second refund is refused; the ledger can't rewrite the row.
    $this->actingAs($this->owner)->post("/clients/{$this->gffs->id}/refund", ['entry_date' => now()->toDateString(), 'wallet' => 'bank'])->assertSessionHasErrors('refund');
});
