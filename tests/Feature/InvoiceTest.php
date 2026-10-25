<?php

use App\Models\BdtEntry;
use App\Models\Campaign;
use App\Models\CampaignDay;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\PaymentSms;
use App\Models\PilotAccount;
use App\Models\Setting;
use App\Models\User;
use App\Models\Wallet;
use App\Payments\BanglaQr;
use App\Payments\PaymentMatcher;
use App\Payments\PaymentMethod;
use App\Payments\PaySettings;
use App\Payments\SmsParser;
use App\Services\Invoices;
use App\Services\Postings;
use App\Support\Access;
use Database\Seeders\AccessSeeder;
use Database\Seeders\BooksSeeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;

const SMS_TOKEN = 'test-device-token-0123456789';

beforeEach(function () {
    $this->seed(AccessSeeder::class);

    $this->owner = User::factory()->create();
    $this->owner->assignRole(Access::OWNER);
    $this->client = Client::create(['name' => 'GFFS', 'type' => 'client', 'status' => 'active', 'phone' => '01711111111']);

    Setting::put('sms_device_token', Hash::make(SMS_TOKEN));
    foreach (['pay_bkash_on' => '1', 'pay_bkash_number' => '01700000001', 'pay_nagad_on' => '1', 'pay_nagad_number' => '01700000002', 'pay_bank_on' => '1', 'pay_bank_name' => 'DBBL', 'pay_bank_account_name' => 'Klok Ads', 'pay_bank_account_number' => '1234567890'] as $key => $value) {
        Setting::put($key, $value);
    }
    // These tests count the invoice's own amounts; the payment charge has its own tests.
    foreach (array_keys(PaySettings::CHARGE_DEFAULTS) as $key) {
        Setting::put($key, '0');
    }
});

it('adds the payment charge on top: 1% for bKash, ৳10 for NPSB, none on Bangla QR, and books it as income', function () {
    foreach (PaySettings::CHARGE_DEFAULTS as $key => $default) {
        Setting::put($key, $default);
    }
    $invoice = sentInvoice($this->client); // ৳14,900

    expect(PaySettings::charge(PaymentMethod::Bkash, 1490000))->toBe(14900)
        ->and(PaySettings::charge(PaymentMethod::Bkash, 218120))->toBe(2200) // ৳21.81 rounds up to ৳22
        ->and(PaySettings::charge(PaymentMethod::BanglaQr, 1490000))->toBe(0) // the bank's rules: QR stays the invoice's amount
        ->and(PaySettings::charge(PaymentMethod::Bank, 1490000))->toBe(1000)
        ->and(PaySettings::charge(PaymentMethod::Cash, 1490000))->toBe(0);

    // The client page shows each way's charge, and the bKash session asks for due + 1%.
    $this->get(route('invoice.public', $invoice->public_token))->assertOk()
        ->assertInertia(fn ($page) => $page->where('state.charges.bkash', 14900)->where('state.charges.bank', 1000));
    $this->postJson(route('invoice.public.start', $invoice->public_token), ['method' => 'bkash', 'payer' => '01712345678'])->assertOk()
        ->assertJsonPath('session.amount', 1504900)
        ->assertJsonPath('session.charge', 14900)
        ->assertJsonPath('session.extra', 0);

    $this->postJson(route('hooks.sms', SMS_TOKEN), [
        'from' => 'bKash', 'text' => 'You have received Tk 15,049.00 from 01712345678. Fee Tk 0.00. Balance Tk 20,000.00. TrxID CHG1234567 at '.now()->format('d/m/Y H:i'),
    ])->assertJson(['status' => 'matched']);

    // The invoice takes its ৳14,900; the ৳149 is our income in the same place.
    $payment = $invoice->payments()->where('status', 'verified')->sole();
    expect($invoice->fresh()->status)->toBe('paid')
        ->and($invoice->fresh()->paid)->toBe(1490000)
        ->and($payment->amount)->toBe(1504900)
        ->and($payment->charge)->toBe(14900)
        ->and(BdtEntry::find($payment->bdt_entry_id)->amount)->toBe(1490000)
        ->and(BdtEntry::find($payment->charge_entry_id))->category->toBe('other_income')
        ->and(BdtEntry::find($payment->charge_entry_id)->amount)->toBe(14900);
});

it('tells the phone which invoice an SMS paid, and takes the same money sent back to that number as its refund', function () {
    Setting::put('pay_charge_mfs', '1');
    $invoice = sentInvoice($this->client, ['total' => 300000]); // ৳3,000, + ৳30 on bKash

    $this->postJson(route('invoice.public.start', $invoice->public_token), ['method' => 'bkash', 'payer' => '01712345678'])->assertOk()
        ->assertJsonPath('session.amount', 303000);
    smsHook('bKash', 'You have received Tk 3,030.00 from 01712345678. Fee Tk 0.00. Balance Tk 3,035.82. TrxID PAYBACK001 at '.now()->format('d/m/Y H:i'))
        ->assertJson(['status' => 'matched', 'invoice' => $invoice->number]);
    $payment = $invoice->payments()->where('status', 'verified')->sole();
    expect($invoice->fresh()->status)->toBe('paid');

    // The same ৳3,030 sent back to that number: the payment leaves the books, the invoice is due again.
    smsHook('bKash', 'Send Money Tk 3,030.00 to 01712345678 successful. Ref test. Fee Tk 5.00. Balance Tk 0.82. TrxID BACK123456 at '.now()->format('d/m/Y H:i'))
        ->assertJson(['status' => 'refund', 'invoice' => $invoice->number]);

    expect($invoice->fresh()->status)->toBe('sent')
        ->and($invoice->fresh()->paid)->toBe(0)
        ->and($payment->fresh()->status)->toBe('refunded')
        ->and(BdtEntry::find($payment->bdt_entry_id))->toBeNull()
        ->and(BdtEntry::find($payment->charge_entry_id))->toBeNull()
        ->and(BdtEntry::where('category', 'expense')->latest('id')->first()->amount)->toBe(-500)
        ->and($this->owner->notifications()->where('data->kind', 'invoice_refund')->count())->toBe(1);

    // Sent again (a resend), or to someone else: nothing more happens.
    smsHook('bKash', 'Send Money Tk 3,030.00 to 01712345678 successful. Fee Tk 5.00. TrxID BACK123456 at '.now()->format('d/m/Y H:i'))->assertJson(['status' => 'ignored']);
    smsHook('bKash', 'Send Money Tk 50.00 to 01899999999 successful. Fee Tk 0.00. TrxID OTHER12345 at '.now()->format('d/m/Y H:i'))->assertJson(['status' => 'ignored']);
});

it('pays the invoice in full when the client sends only the due, without taking a charge', function () {
    Setting::put('pay_charge_bank', '10');
    $invoice = sentInvoice($this->client);

    // A bank transfer of just ৳14,900 (no ৳10): confirmed by hand, the invoice is paid, no charge row.
    $this->postJson(route('invoice.public.claim', $invoice->public_token), ['method' => 'bank', 'reference' => 'NPSB778899', 'sender' => 'City Bank'])->assertOk();
    $claim = $invoice->payments()->where('reference', 'NPSB778899')->sole();
    expect($claim->charge)->toBe(1000)
        ->and($claim->amount)->toBe(1491000);

    app(Invoices::class)->verify($claim, 1490000, $this->owner);

    expect($invoice->fresh()->status)->toBe('paid')
        ->and($claim->fresh()->charge)->toBe(0)
        ->and($claim->fresh()->charge_entry_id)->toBeNull();
});

/** An invoice for ৳14,900: $100 of ads at ৳137 plus a ৳1,200 service fee. */
function sentInvoice(Client $client, array $overrides = []): Invoice
{
    $invoice = app(Invoices::class)->save([
        'client_id' => $client->id, 'issue_date' => now()->toDateString(), 'due_date' => now()->addWeek()->toDateString(), 'notes' => null,
        'items' => [
            ['kind' => 'ad_budget', 'description' => '', 'usd' => 10000, 'rate' => 137.0, 'amount' => null, 'campaign_id' => null, 'period_from' => null, 'period_to' => null, 'results' => null, 'clicks' => null],
            ['kind' => 'service', 'description' => 'Page management', 'usd' => null, 'rate' => null, 'amount' => 120000, 'campaign_id' => null, 'period_from' => null, 'period_to' => null, 'results' => null, 'clicks' => null],
        ],
    ]);
    app(Invoices::class)->markSent($invoice);

    return tap($invoice->fresh(), fn ($i) => $i->forceFill($overrides)->save());
}

function smsHook(string $from, string $text): TestResponse
{
    return test()->postJson(route('hooks.sms', SMS_TOKEN), ['from' => $from, 'text' => $text]);
}

it('creates a numbered invoice with campaign, advance and service lines and their totals', function () {
    $account = PilotAccount::create(['pilot_id' => 9, 'name' => 'Agency', 'platform' => 'meta', 'currency' => 'USD', 'seen_at' => now()]);
    $campaign = Campaign::create(['pilot_campaign_id' => 101, 'pilot_account_id' => $account->id, 'name' => 'GFFS - Messages', 'client_id' => $this->client->id, 'bill_from' => now()->subDays(5)->toDateString()]);
    CampaignDay::create(['campaign_id' => $campaign->id, 'day' => now()->subDays(10)->toDateString(), 'spend' => 999, 'results' => 1, 'clicks' => 1]);
    CampaignDay::create(['campaign_id' => $campaign->id, 'day' => now()->subDays(2)->toDateString(), 'spend' => 2550, 'results' => 12, 'clicks' => 40]);
    CampaignDay::create(['campaign_id' => $campaign->id, 'day' => now()->subDay()->toDateString(), 'spend' => 1450, 'results' => 8, 'clicks' => 20]);

    // Only days from bill_from on count.
    $this->actingAs($this->owner)->getJson('/invoices/campaigns?'.http_build_query(['client' => $this->client->id, 'from' => now()->subDays(20)->toDateString(), 'to' => now()->toDateString()]))
        ->assertOk()->assertJsonPath('campaigns.0.usd', 4000)->assertJsonPath('campaigns.0.results', 20);

    $this->actingAs($this->owner)->post('/invoices', [
        'client_id' => $this->client->id, 'issue_date' => now()->toDateString(), 'due_date' => now()->addWeek()->toDateString(),
        'items' => [
            ['kind' => 'ad_spend', 'description' => 'GFFS - Messages', 'campaign_id' => $campaign->id, 'period_from' => now()->subDays(5)->toDateString(), 'period_to' => now()->toDateString(), 'results' => 20, 'clicks' => 60, 'usd' => '40.00', 'rate' => '137.5'],
            ['kind' => 'ad_budget', 'usd' => '100', 'rate' => '137.5'],
            ['kind' => 'service', 'description' => 'Page management', 'amount' => '1,200'],
        ],
    ])->assertRedirect();

    $invoice = Invoice::with('items')->sole();

    expect($invoice->number)->toBe('KA-0001')
        ->and($invoice->status)->toBe('sent')
        ->and($invoice->usd)->toBe(14000)
        ->and($invoice->total)->toBe(550000 + 1375000 + 120000)
        ->and($invoice->items[0]->results)->toBe(20)
        ->and(strlen($invoice->public_token))->toBe(40);

    $this->actingAs($this->owner)->get("/invoices/{$invoice->id}")->assertOk()
        ->assertInertia(fn ($page) => $page->component('invoices/Show')->where('invoice.total', 2045000));
    $this->actingAs($this->owner)->get('/invoices')->assertOk()
        ->assertInertia(fn ($page) => $page->component('invoices/Index')->where('invoices.total', 1));
});

it('defaults the client rate to the current FX batch plus the commission', function () {
    $this->seed(BooksSeeder::class);
    app(Postings::class)->buyUsd(['entry_date' => now()->toDateString(), 'description' => null, 'usd' => 100000, 'bdt' => 12250000, 'notes' => null]);
    Setting::put('commission_percent', '20');

    expect(Invoices::fxRate())->toBe(122.5)->and(Invoices::clientRate())->toBe(147.0);
});

it('shows the public page by its token only, also to a cancelled invoice', function () {
    $invoice = sentInvoice($this->client);

    $this->get('/i/'.$invoice->public_token)->assertOk()
        ->assertInertia(fn ($page) => $page->component('public/Invoice')
            ->where('invoice.number', $invoice->number)
            ->where('state.due', 1490000)
            ->has('methods.bkash')
            ->missing('invoice.client_id'));

    $this->get('/i/'.str_repeat('x', 40))->assertNotFound();
    $this->get('/i/short')->assertNotFound();

    $invoice->forceFill(['status' => 'void'])->save();
    $this->get('/i/'.$invoice->public_token)->assertOk()->assertInertia(fn ($page) => $page->where('state.due', 0));
});

it('reads incoming wallet and bank SMS and ignores fakes and outgoing money', function () {
    $parser = new SmsParser;
    Setting::put('pay_bank_sms_senders', 'DBBL, BRAC BANK');

    expect($parser->parse('bKash', 'You have received Tk 14,900.00 from 01712345678. Ref KA0001. Fee Tk 0.00. Balance Tk 52,310.50. TrxID BJK7Q2M9XZ at 20/10/2026 14:23'))
        ->toMatchArray(['provider' => 'bkash', 'amount' => 1490000, 'trx_id' => 'BJK7Q2M9XZ', 'payer_number' => '01712345678'])
        ->and($parser->parse('NAGAD', "Money Received.\nAmount: Tk 2,500.00\nSender: 01912345678\nRef: N/A\nTxnID: 73KD9PLQ\nBalance: Tk 3,000.00"))
        ->toMatchArray(['provider' => 'nagad', 'amount' => 250000, 'trx_id' => '73KD9PLQ'])
        ->and($parser->parse('16216', 'Tk1,000.00 received from A/C:01612345678 Fee:Tk0, Your A/C Balance: Tk5,250.00 TxnId:4417829301 Date:20-OCT-26'))
        ->toMatchArray(['provider' => 'rocket', 'amount' => 100000, 'trx_id' => '4417829301'])
        ->and($parser->parse('BRAC BANK', 'Your A/C 1234***890 has been credited by BDT 5,000.00 on 20-Oct-26. Ref No: NPSB778812. Avl Bal BDT 90,000.00'))
        ->toMatchArray(['provider' => 'bank', 'amount' => 500000, 'trx_id' => 'NPSB778812'])
        ->and($parser->parse('+8801712345678', 'You have received Tk 14,900.00 from 01712345678. TrxID FAKE123456'))->toBeNull()
        ->and($parser->parse('bKash', 'Send Money Tk 500.00 to 01712345678 successful. Fee Tk 0.00. TrxID ABC1234567'))->toBeNull()
        ->and($parser->parse('BRAC BANK', 'Your A/C 1234 has been debited by BDT 500.00. Ref No: X12345678'))->toBeNull();
});

it('asks a second open session on the same wallet for one taka more', function () {
    $first = sentInvoice($this->client);
    $second = sentInvoice(Client::create(['name' => 'Other', 'type' => 'client', 'status' => 'active']));
    $matcher = app(PaymentMatcher::class);

    $a = $matcher->startSession($first, PaymentMethod::Bkash);
    $b = $matcher->startSession($second, PaymentMethod::Bkash);
    $c = $matcher->startSession(sentInvoice(Client::create(['name' => 'Third', 'type' => 'client', 'status' => 'active'])), PaymentMethod::Nagad);

    expect($a->amount)->toBe(1490000)
        ->and($b->amount)->toBe(1490100)
        ->and($c->amount)->toBe(1490000)
        ->and($matcher->startSession($first, PaymentMethod::Bkash)->id)->toBe($a->id);

    smsHook('bKash', 'You have received Tk 14,901.00 from 01711111111. TrxID SECOND1234 at 20/10/2026 14:25')->assertJson(['status' => 'matched']);

    expect($second->fresh()->status)->toBe('paid')->and($first->fresh()->status)->toBe('sent');
});

it('checks the forwarder token, stores each SMS once, and settles the open session into the books', function () {
    smsHook('bKash', 'x');
    $this->postJson(route('hooks.sms', 'wrong-token'), ['from' => 'bKash', 'text' => 'x'])->assertNotFound();
    $this->postJson(route('hooks.sms', SMS_TOKEN), [])->assertJson(['status' => 'heartbeat']);
    expect(Setting::get('sms_last_seen'))->not->toBeNull();

    $invoice = sentInvoice($this->client);

    // bKash needs the number the money comes from.
    $this->postJson("/i/{$invoice->public_token}/pay", ['method' => 'bkash'])->assertUnprocessable()->assertJsonValidationErrors('payer');
    $this->postJson("/i/{$invoice->public_token}/pay", ['method' => 'bkash', 'payer' => '01798765432'])->assertOk()
        ->assertJsonPath('session.amount', 1490000)->assertJsonPath('session.method', 'bkash');

    $payload = ['sender' => 'bKash', 'message' => 'You have received Tk 14,900.00 from 01798765432. Fee Tk 0.00. Balance Tk 60,000.00. TrxID AUTO123456 at 20/10/2026 14:25'];
    $this->postJson(route('hooks.sms', SMS_TOKEN), $payload)->assertJson(['status' => 'matched']);
    $this->postJson(route('hooks.sms', SMS_TOKEN), $payload)->assertJson(['status' => 'duplicate']);

    $invoice->refresh();
    $payment = InvoicePayment::where('status', 'verified')->sole();
    $entry = BdtEntry::findOrFail($payment->bdt_entry_id);

    expect($invoice->status)->toBe('paid')
        ->and($invoice->paid)->toBe(1490000)
        ->and($payment->reference)->toBe('AUTO123456')
        ->and($entry->category)->toBe('client_payment')
        ->and($entry->client_id)->toBe($this->client->id)
        ->and($entry->amount)->toBe(1490000)
        ->and($entry->pay_via)->toBe('mfs')
        ->and($entry->wallet)->toBe('bkash')
        ->and($entry->usd_credit)->toBe(10000)
        ->and(PaymentSms::where('trx_id', 'AUTO123456')->count())->toBe(1);

    $this->getJson("/i/{$invoice->public_token}/status")->assertJsonPath('status', 'paid')->assertJsonPath('session', null);
});

it('ignores a session payer who is not the one who paid, and money short of the session', function () {
    $invoice = sentInvoice($this->client);
    $this->postJson("/i/{$invoice->public_token}/pay", ['method' => 'nagad', 'payer' => '01712345678'])->assertOk();

    smsHook('NAGAD', "Money Received.\nAmount: Tk 14,900.00\nSender: 01999999999\nTxnID: OTHER123");
    expect($invoice->fresh()->status)->toBe('sent');

    smsHook('NAGAD', "Money Received.\nAmount: Tk 14,900.00\nSender: 01712345678\nTxnID: MINE1234");
    expect($invoice->fresh()->status)->toBe('paid');
});

it('confirms a TrxID claim from an SMS that came first, and refuses a reused TrxID', function () {
    $invoice = sentInvoice($this->client);

    smsHook('bKash', 'You have received Tk 14,900.00 from 01712345678. TrxID QWE123RTY9 at 20/10/2026 14:23')->assertJson(['status' => 'stored']);

    $this->postJson("/i/{$invoice->public_token}/claim", ['method' => 'bkash', 'reference' => ' qwe123rty9 ', 'sender' => '01712345678'])
        ->assertOk()->assertJsonPath('status', 'paid');

    $other = sentInvoice($this->client);
    $this->postJson("/i/{$other->public_token}/claim", ['method' => 'bkash', 'reference' => 'QWE123RTY9', 'sender' => '01712345678'])
        ->assertStatus(422)->assertJsonValidationErrors('reference');
});

it('confirms a claim when its SMS arrives later, but not a short one', function () {
    $invoice = sentInvoice($this->client);

    $this->postJson("/i/{$invoice->public_token}/claim", ['method' => 'bkash', 'reference' => 'LATE123456', 'sender' => '01712345678'])
        ->assertOk()->assertJsonPath('checking', true);
    expect($this->owner->notifications()->where('data->kind', 'invoice_claim')->count())->toBe(1);

    smsHook('bKash', 'You have received Tk 14,900.00 from 01712345678. TrxID LATE123456 at 20/10/2026 14:23')->assertJson(['status' => 'matched']);
    expect($invoice->fresh()->status)->toBe('paid');

    $short = sentInvoice($this->client);
    $this->postJson("/i/{$short->public_token}/claim", ['method' => 'bkash', 'reference' => 'LOW1234567', 'sender' => '01712345678']);
    smsHook('bKash', 'You have received Tk 1,000.00 from 01712345678. TrxID LOW1234567 at 20/10/2026 14:23');

    expect($short->fresh()->status)->toBe('sent')
        ->and((string) InvoicePayment::where('reference', 'LOW1234567')->value('note'))->toContain('1,000');
});

it('takes a manual part payment with its share of the ad budget as credit', function () {
    $invoice = sentInvoice($this->client);

    $this->actingAs($this->owner)->post("/invoices/{$invoice->id}/pay", [
        'method' => 'bank', 'wallet' => 'bank', 'paid_on' => now()->toDateString(), 'amount' => '7,450', 'reference' => 'NPSB1',
    ])->assertRedirect();

    $invoice->refresh();
    $entry = BdtEntry::where('category', 'client_payment')->sole();

    expect($invoice->status)->toBe('partial')
        ->and($invoice->paid)->toBe(745000)
        ->and($entry->pay_via)->toBe('bank')
        ->and($entry->usd_credit)->toBe(5000);

    // The rest takes whatever ad credit is left.
    $this->actingAs($this->owner)->post("/invoices/{$invoice->id}/pay", [
        'method' => 'cash', 'wallet' => 'cash', 'paid_on' => now()->toDateString(), 'amount' => '7450',
    ])->assertRedirect();

    expect($invoice->fresh()->status)->toBe('paid')
        ->and((int) BdtEntry::where('category', 'client_payment')->sum('usd_credit'))->toBe(10000)
        ->and(BdtEntry::where('category', 'client_payment')->latest('id')->first()->wallet)->toBe('cash');

    $this->actingAs($this->owner)->post("/invoices/{$invoice->id}/void")->assertSessionHasErrors('invoice');
});

it('books the Bangla QR fee from the bank when a QR payment is confirmed', function () {
    Setting::put('pay_qr_on', '1');
    Setting::put('pay_qr_image', 'payments/qr.png');
    Setting::put('qr_fee_percent', '1');
    $invoice = sentInvoice($this->client);

    $this->postJson("/i/{$invoice->public_token}/pay", ['method' => 'qr'])->assertOk()->assertJsonPath('session.method', 'qr');
    smsHook('bKash', 'You have received payment Tk 14,900.00 from 01812345678. Fee Tk 0.00. TrxID QRPAY12345 at 20/10/2026 10:01')->assertJson(['status' => 'matched']);

    $payment = InvoicePayment::where('status', 'verified')->sole();
    $fee = BdtEntry::findOrFail($payment->fee_entry_id);

    expect($payment->method)->toBe(PaymentMethod::BanglaQr)
        ->and(BdtEntry::findOrFail($payment->bdt_entry_id)->pay_via)->toBe('qr')
        ->and(BdtEntry::findOrFail($payment->bdt_entry_id)->wallet)->toBe('bank')
        ->and($fee->category)->toBe('expense')
        ->and($fee->wallet)->toBe('bank')
        ->and($fee->amount)->toBe(-14900)
        ->and($fee->head->name)->toBe('Bank charge');
});

it('takes a Bangla QR payment that lands short by the bank\'s fee, and books the gap as the fee', function () {
    Setting::put('pay_qr_on', '1');
    Setting::put('pay_qr_image', 'payments/qr.png');
    Setting::put('qr_fee_percent', '0.4');
    $invoice = sentInvoice($this->client); // ৳14,900

    // The QR asks for the invoice's own amount; the bank keeps 0.4% (৳59.60) and the poisha round.
    $this->postJson("/i/{$invoice->public_token}/pay", ['method' => 'qr'])->assertOk()
        ->assertJsonPath('session.amount', 1490000)
        ->assertJsonPath('session.charge', 0);
    smsHook('bKash', 'You have received payment Tk 14,840.50 from 01812345678. TrxID QRSHORT123 at 20/10/2026 10:01')->assertJson(['status' => 'matched']);

    $payment = InvoicePayment::where('status', 'verified')->sole();
    expect($invoice->fresh()->status)->toBe('paid')
        ->and($payment->amount)->toBe(1490000)
        ->and(BdtEntry::findOrFail($payment->bdt_entry_id)->amount)->toBe(1490000)
        ->and(BdtEntry::findOrFail($payment->fee_entry_id)->amount)->toBe(-5950);

    // Below ৳2,000 the bank keeps nothing, and much less than the QR is not that payment.
    expect(PaySettings::qrFee(199900))->toBe(0)
        ->and(PaySettings::qrFee(250000))->toBe(1000);
    $small = sentInvoice($this->client, ['total' => 150000]);
    $this->postJson("/i/{$small->public_token}/pay", ['method' => 'qr'])->assertOk();
    smsHook('bKash', 'You have received payment Tk 1,400.00 from 01812345678. TrxID QRFAR12345 at 20/10/2026 10:05')->assertJson(['status' => 'stored']);
});

it('lets the Owner give an unmatched SMS to an invoice', function () {
    $invoice = sentInvoice($this->client);
    smsHook('bKash', 'You have received Tk 5,000.00 from 01712345678. TrxID ODD1234567 at 20/10/2026 14:23')->assertJson(['status' => 'stored']);
    $sms = PaymentSms::sole();

    $this->actingAs($this->owner)->post("/invoices/sms/{$sms->id}/assign", ['invoice_id' => $invoice->id])->assertRedirect();

    expect($sms->fresh()->status)->toBe('matched')
        ->and($invoice->fresh()->status)->toBe('partial')
        ->and($invoice->fresh()->paid)->toBe(500000);
});

it('shows sent invoices past due as overdue, keeps their status, and tells the Owner once', function () {
    $invoice = sentInvoice($this->client, ['due_date' => now()->subDays(2)->toDateString()]);
    $future = sentInvoice($this->client);

    expect(app(Invoices::class)->markOverdue())->toBe(1)
        ->and($invoice->fresh()->status)->toBe('sent')
        ->and($invoice->fresh()->isOverdue())->toBeTrue()
        ->and($future->fresh()->isOverdue())->toBeFalse()
        ->and($future->fresh()->status)->toBe('sent')
        ->and(app(Invoices::class)->markOverdue())->toBe(0)
        ->and($this->owner->notifications()->where('data->kind', 'invoice_overdue')->count())->toBe(1);
});

it('saves the payment settings and makes a forwarder URL shown once', function () {
    $this->actingAs($this->owner)->post('/admin/payment', [
        'pay_business_name' => 'Klok Ads', 'qr_fee_percent' => '1', 'commission_percent' => '20',
        'pay_bkash_on' => '1', 'pay_bkash_number' => '01711112222', 'pay_bkash_type' => 'merchant',
        'pay_nagad_on' => '0', 'pay_rocket_on' => '0', 'pay_qr_on' => '0', 'pay_bank_on' => '0',
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect(Setting::get('pay_bkash_number'))->toBe('01711112222')->and(Setting::get('pay_nagad_on'))->toBe('0');

    $this->actingAs($this->owner)->post('/admin/payment', ['pay_business_name' => 'Klok Ads', 'qr_fee_percent' => '1', 'commission_percent' => '20', 'pay_bank_on' => '1'])
        ->assertSessionHasErrors(['pay_bank_name', 'pay_bank_account_number']);

    $this->actingAs($this->owner)->post('/admin/payment/token')->assertRedirect()->assertSessionHas('sms_hook_url');
    expect(Hash::check(SMS_TOKEN, (string) Setting::get('sms_device_token')))->toBeFalse();

    $this->actingAs($this->owner)->get('/admin/payment')->assertOk()->assertInertia(fn ($page) => $page->component('admin/Payment'));
});

it('lists only the client\'s own campaigns on the invoice, ended ones marked', function () {
    $account = PilotAccount::create(['pilot_id' => 9, 'name' => 'Klok Ads Agency', 'platform' => 'meta', 'currency' => 'USD', 'seen_at' => now()]);
    Campaign::create(['pilot_campaign_id' => 1, 'pilot_account_id' => $account->id, 'name' => 'GFFS - Sales', 'status' => 'ACTIVE', 'client_id' => $this->client->id, 'bill_from' => now()->subDays(5)->toDateString()]);
    Campaign::create(['pilot_campaign_id' => 3, 'pilot_account_id' => $account->id, 'name' => 'GFFS - Old', 'status' => 'ACTIVE', 'client_id' => $this->client->id, 'bill_from' => now()->subDays(5)->toDateString(), 'stop_at' => now()->subMonth()]);
    Campaign::create(['pilot_campaign_id' => 2, 'pilot_account_id' => $account->id, 'name' => 'Someone else', 'status' => 'ACTIVE', 'stop_at' => now()->addDays(3)]);

    $list = $this->actingAs($this->owner)->getJson('/invoices/campaigns?'.http_build_query(['client' => $this->client->id, 'from' => now()->subDays(10)->toDateString(), 'to' => now()->toDateString()]))
        ->assertOk()->json('campaigns');

    expect(array_column($list, 'name'))->toBe(['GFFS - Old', 'GFFS - Sales'])
        ->and($list[0]['ended'])->toBeTrue()
        ->and($list[1]['live'])->toBeTrue();
});

it('can still fix a sent invoice until money comes in, and then locks it', function () {
    $line = fn (int $amount) => ['kind' => 'service', 'description' => 'Page management', 'usd' => null, 'rate' => null, 'amount' => $amount, 'campaign_id' => null, 'period_from' => null, 'period_to' => null, 'results' => null, 'clicks' => null];
    $invoice = app(Invoices::class)->save([
        'client_id' => $this->client->id, 'issue_date' => now()->toDateString(), 'due_date' => null, 'notes' => null,
        'items' => [$line(120000)],
    ]);
    $this->actingAs($this->owner)->post("/invoices/{$invoice->id}/send")->assertRedirect();
    $popup = $invoice->payments()->create(['method' => 'bkash', 'amount' => 120000, 'status' => 'pending', 'expires_at' => now()->addMinutes(30)]);

    // Sent, nothing paid: a mistake can be fixed; the pay popup for the old amount closes.
    expect($invoice->fresh()->isEditable())->toBeTrue();
    $this->actingAs($this->owner)->get("/invoices/{$invoice->id}/edit")->assertOk();
    $this->actingAs($this->owner)->put("/invoices/{$invoice->id}", [
        'client_id' => $this->client->id, 'issue_date' => now()->toDateString(), 'due_date' => null, 'notes' => null,
        'items' => [$line(1000)], // taka, as the form sends it
    ])->assertRedirect();

    expect($invoice->fresh()->total)->toBe(100000)
        ->and($popup->fresh()->status)->toBe('expired');

    // Money in: locked.
    $invoice->forceFill(['paid' => 50000, 'status' => 'partial'])->save();
    expect($invoice->fresh()->isEditable())->toBeFalse();
    $this->actingAs($this->owner)->get("/invoices/{$invoice->id}/edit")->assertRedirect();
});

/** A static Bangla QR as a bank hands it out (fields made up, check code real). */
function staticBanglaQr(): string
{
    $tlv = fn (string $tag, string $value) => $tag.str_pad((string) strlen($value), 2, '0', STR_PAD_LEFT).$value;
    $body = $tlv('00', '01').$tlv('01', '11').$tlv('26', $tlv('00', 'bd.com.islamibank').$tlv('01', '1234567890123'))
        .$tlv('52', '5999').$tlv('53', '050').$tlv('58', 'BD').$tlv('59', 'KLOK ADS').$tlv('60', 'RAJSHAHI').'6304';

    return $body.BanglaQr::crc($body);
}

it('computes the EMV check code the way banks do', function () {
    expect(BanglaQr::crc('123456789'))->toBe('29B1');
});

it('puts the amount into the agency QR and keeps everything else', function () {
    $qr = new BanglaQr(staticBanglaQr());
    $text = $qr->forAmount(1490050);

    $fields = BanglaQr::parse($text);
    expect($fields['01'])->toBe('12')
        ->and($fields['54'])->toBe('14900.50')
        ->and($fields['59'])->toBe('KLOK ADS')
        ->and($fields['26'])->toBe(BanglaQr::parse(staticBanglaQr())['26'])
        ->and($qr->merchant())->toBe('KLOK ADS');

    // A changed or cut text is refused.
    expect(fn () => BanglaQr::parse(substr(staticBanglaQr(), 0, -1).'0'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => BanglaQr::parse('hello'))->toThrow(InvalidArgumentException::class)
        ->and(BanglaQr::svg($text))->toStartWith('<svg');
});

it('takes the QR text in Settings only when it is a real Bangla QR', function () {
    $base = ['pay_business_name' => 'Klok Ads', 'qr_fee_percent' => '1', 'commission_percent' => '20'];

    $this->actingAs($this->owner)->post('/admin/payment', $base + ['pay_qr_payload' => '000201broken'])->assertSessionHasErrors('pay_qr_payload');
    $this->actingAs($this->owner)->post('/admin/payment', $base + ['pay_qr_payload' => staticBanglaQr()])->assertSessionHasNoErrors();

    expect(Setting::get('pay_qr_on'))->toBe('1')
        ->and(PaySettings::methods()['qr'])->toMatchArray(['dynamic' => true, 'merchant' => 'KLOK ADS', 'image' => null]);
});

it('shows a QR with the whole due locked in, or the part the client chose when allowed', function () {
    Setting::put('pay_qr_on', '1');
    Setting::put('pay_qr_payload', staticBanglaQr());
    $invoice = sentInvoice($this->client);

    // Locked: an amount from the client is ignored, the QR carries everything due.
    $state = $this->postJson("/i/{$invoice->public_token}/pay", ['method' => 'qr', 'amount' => '500'])->assertOk()->json();
    expect($state['session']['amount'])->toBe(1490000)
        ->and($state['allow_partial'])->toBeFalse();
    $svg = $this->get($state['session']['qr'])->assertOk()->assertHeader('Content-Type', 'image/svg+xml')->getContent();
    expect($svg)->toContain('<svg');

    // Open: the client pays ৳5,000 now; the QR is made for that.
    $this->actingAs($this->owner)->post("/invoices/{$invoice->id}/partial", ['allow_partial' => true])->assertRedirect();
    $state = $this->postJson("/i/{$invoice->public_token}/pay", ['method' => 'qr', 'amount' => '5000'])->assertOk()->json();
    expect($state['session']['amount'])->toBe(500000)->and($state['allow_partial'])->toBeTrue();

    // Not more than is due, not under ৳10.
    $this->postJson("/i/{$invoice->public_token}/pay", ['method' => 'qr', 'amount' => '20000'])->assertUnprocessable()->assertJsonValidationErrors('amount');
    $this->postJson("/i/{$invoice->public_token}/pay", ['method' => 'qr', 'amount' => '5'])->assertUnprocessable();

    // The bank SMS for exactly that pays that part; the rest stays due.
    Setting::put('pay_bank_sms_senders', 'IslamiBank');
    smsHook('IslamiBank', 'Islami Bank CellFin BDT 5,000.00 Received from 01711111111')->assertJson(['status' => 'matched']);
    expect($invoice->fresh()->paid)->toBe(500000)->and($invoice->fresh()->due())->toBe(990000);
});

it('gives every invoice its own code for its admin address, and old number links still open', function () {
    $invoice = sentInvoice($this->client);

    expect($invoice->uid)->toMatch('/^[0-9a-z]{26}$/')
        ->and(route('invoices.show', $invoice))->toEndWith('/invoices/'.$invoice->uid);

    $this->actingAs($this->owner)->get('/invoices/'.$invoice->uid)->assertOk()
        ->assertInertia(fn ($page) => $page->where('invoice.uid', $invoice->uid));
    // A link from an older notification (/invoices/{number}) still finds it.
    $this->actingAs($this->owner)->get('/invoices/'.$invoice->id)->assertOk();
    $this->actingAs($this->owner)->get('/invoices/nope')->assertNotFound();
});

it('books each way of paying into the account the money really reaches', function () {
    Wallet::create(['key' => 'ibbl', 'name' => 'Islami Bank (CellFin)', 'type' => 'bank', 'opening_balance' => 0, 'is_active' => true, 'sort' => 5]);
    Setting::put('qr_fee_percent', '1');

    // Bangla QR lands in Islami Bank by itself; bank transfers stay in the bank (UCB).
    expect(PaymentMethod::BanglaQr->wallet())->toBe('ibbl')
        ->and(PaymentMethod::Bank->wallet())->toBe('bank');

    // Chosen in Settings, it follows the choice; a place that does not exist is not taken.
    Setting::put('pay_wallet_bank', 'ibbl');
    expect(PaymentMethod::Bank->wallet())->toBe('ibbl');
    Setting::put('pay_wallet_bank', 'gone');
    expect(PaymentMethod::Bank->wallet())->toBe('bank');

    // A QR payment and its fee are both booked in Islami Bank.
    $invoice = sentInvoice($this->client);
    $payment = $invoice->payments()->create(['method' => 'qr', 'amount' => $invoice->due(), 'status' => 'pending']);
    app(Invoices::class)->verify($payment, $invoice->due(), $this->owner);

    $payment->refresh();
    expect($payment->wallet)->toBe('ibbl')
        ->and(BdtEntry::find($payment->bdt_entry_id)->wallet)->toBe('ibbl')
        ->and(BdtEntry::find($payment->fee_entry_id)->wallet)->toBe('ibbl');
});

it('matches a bKash payment only from the number the client gave', function () {
    $invoice = sentInvoice($this->client);
    $this->postJson("/i/{$invoice->public_token}/pay", ['method' => 'bkash', 'payer' => '01798765432'])->assertOk();

    // Same amount, another number: not this invoice.
    smsHook('bKash', 'You have received Tk 14,900.00 from 01811111111. TrxID OTHER11111 at 20/10/2026 14:25')->assertJson(['status' => 'stored']);
    // No number in the SMS at all: not taken on trust either.
    smsHook('bKash', 'You have received Tk 14,900.00. TrxID NONUM22222 at 20/10/2026 14:26')->assertJson(['status' => 'stored']);
    expect($invoice->fresh()->paid)->toBe(0);

    // A masked number matches by the digits it shows.
    smsHook('bKash', 'You have received Tk 14,900.00 from 01798***432. TrxID MASKED3333 at 20/10/2026 14:27')->assertJson(['status' => 'matched']);
    expect($invoice->fresh()->status)->toBe('paid');
});

it('ties a TrxID written by hand to its SMS, before or after it comes, and never takes it twice', function () {
    // The SMS came first and matched nothing.
    smsHook('bKash', 'You have received Tk 5,000.00 from 01712345678. TrxID HAND111111 at 20/10/2026 14:23')->assertJson(['status' => 'stored']);
    $invoice = sentInvoice($this->client);
    $pay = fn (string $ref, string $amount) => $this->actingAs($this->owner)->post("/invoices/{$invoice->uid}/pay", [
        'method' => 'bkash', 'wallet' => 'bkash', 'paid_on' => now()->toDateString(), 'amount' => $amount, 'reference' => $ref,
    ]);

    // More than the SMS says came in: refused.
    $pay('HAND111111', '6000')->assertSessionHasErrors('amount');
    // Right amount: written, and the SMS is tied to it.
    $pay('HAND111111', '5000')->assertSessionHasNoErrors();
    expect(PaymentSms::where('trx_id', 'HAND111111')->value('status'))->toBe('matched');
    // The same TrxID again: refused.
    $pay('hand111111', '1000')->assertSessionHasErrors('reference');

    // Written by hand before its SMS: when the SMS comes, it joins that payment quietly.
    $pay('LATER22222', '2000')->assertSessionHasNoErrors();
    smsHook('bKash', 'You have received Tk 2,000.00 from 01712345678. TrxID LATER22222 at 20/10/2026 14:30')->assertJson(['status' => 'matched']);
    expect($invoice->fresh()->paid)->toBe(700000);
});

it('pays an invoice with money already written by hand, without counting it twice', function () {
    $invoice = sentInvoice($this->client);
    $byHand = BdtEntry::create(['entry_date' => now()->subDays(3)->toDateString(), 'description' => 'GFFS reimbursement received', 'category' => 'client_payment',
        'client_id' => $this->client->id, 'pay_via' => 'bank', 'wallet' => 'bank', 'amount' => 1490000]);
    $before = BdtEntry::where('category', 'client_payment')->count();

    // The invoice page offers it.
    $this->actingAs($this->owner)->get("/invoices/{$invoice->uid}")
        ->assertInertia(fn ($page) => $page->where('earlier_payments.0.id', $byHand->id));

    $this->actingAs($this->owner)->post("/invoices/{$invoice->uid}/pay", [
        'method' => 'bank', 'wallet' => 'bank', 'paid_on' => now()->toDateString(), 'amount' => '1', 'existing_entry_id' => $byHand->id,
    ])->assertSessionHasNoErrors();

    expect($invoice->fresh()->status)->toBe('paid')
        ->and(BdtEntry::where('category', 'client_payment')->count())->toBe($before)
        ->and($byHand->fresh()->usd_credit)->toBe(10000)
        ->and($byHand->fresh()->notes)->toContain($invoice->number)
        ->and($invoice->payments()->where('status', 'verified')->sole()->bdt_entry_id)->toBe($byHand->id);

    // Used once: the next invoice no longer offers it.
    $next = sentInvoice($this->client);
    $this->actingAs($this->owner)->get("/invoices/{$next->uid}")->assertInertia(fn ($page) => $page->has('earlier_payments', 0));
});

it('tells the Owner when the money came in from another number than the client gave', function () {
    Setting::put('pay_charge_mfs', '0');
    $invoice = sentInvoice($this->client); // ৳14,900
    $session = app(PaymentMatcher::class)->startSession($invoice, PaymentMethod::Bkash, '01707589304');

    smsHook('bKash', 'You have received Tk '.number_format($session->amount / 100, 2).' from 01858823568. Fee Tk 0.00. Balance Tk 20,000.00. TrxID OTHERNUM01 at '.now()->format('d/m/Y H:i'))
        ->assertJson(['status' => 'stored']);

    // Not taken on trust: the invoice is still due, and the Owner is asked to match it.
    expect($invoice->fresh()->status)->toBe('sent')
        ->and($this->owner->notifications()->where('data->kind', 'sms_check')->sole()->data['params']['number'])->toBe($invoice->number);
});
