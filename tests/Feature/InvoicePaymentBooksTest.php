<?php

use App\Models\BdtEntry;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Setting;
use App\Models\User;
use App\Payments\PaySettings;
use App\Services\Books;
use App\Services\Invoices;
use App\Support\Access;
use Database\Seeders\AccessSeeder;
use Illuminate\Support\Facades\Hash;

const IP_SMS_TOKEN = 'invoice-books-device-token-0123456789';

beforeEach(function () {
    $this->seed(AccessSeeder::class);

    $this->owner = User::factory()->create();
    $this->owner->assignRole(Access::OWNER);
    $this->client = Client::create(['name' => 'GFFS', 'type' => 'client', 'status' => 'active', 'phone' => '01711111111']);

    Setting::put('sms_device_token', Hash::make(IP_SMS_TOKEN));
    foreach (['pay_bkash_on' => '1', 'pay_bkash_number' => '01700000001', 'pay_bank_on' => '1', 'pay_bank_name' => 'DBBL', 'pay_bank_account_name' => 'Klok Ads', 'pay_bank_account_number' => '1234567890'] as $key => $value) {
        Setting::put($key, $value);
    }
    foreach (array_keys(PaySettings::CHARGE_DEFAULTS) as $key) {
        Setting::put($key, '0');
    }
});

/** An invoice for ৳14,900: $100 of ads at ৳137 plus a ৳1,200 service fee. */
function ipSentInvoice(Client $client, array $overrides = []): Invoice
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

it('makes the invoice due again when the BDT row behind its payment is deleted, and keeps that row\'s money fixed', function () {
    Setting::put('pay_charge_bank', '10');
    $invoice = ipSentInvoice($this->client);

    $this->actingAs($this->owner)->post("/invoices/{$invoice->id}/pay", [
        'method' => 'bank', 'wallet' => 'bank', 'paid_on' => now()->toDateString(), 'amount' => '7,450', 'reference' => 'NPSB1',
    ])->assertRedirect();
    $payment = $invoice->payments()->where('status', 'verified')->sole();
    $entry = BdtEntry::findOrFail($payment->bdt_entry_id);
    expect($invoice->fresh()->paid)->toBe(745000);

    // Its money can't be changed from the ledger, but its words can.
    $form = ['entry_date' => $entry->entry_date->toDateString(), 'description' => 'GFFS paid', 'category' => 'client_payment', 'client_id' => $this->client->id,
        'pay_via' => 'bank', 'wallet' => 'bank', 'amount' => '7450', 'usd_credit' => (string) ($entry->usd_credit / 100)];
    $this->actingAs($this->owner)->put("/bdt/{$entry->id}", [...$form, 'amount' => '9000'])->assertSessionHasErrors('entry');
    $this->actingAs($this->owner)->put("/bdt/{$entry->id}", $form)->assertSessionHasNoErrors();
    expect($entry->fresh()->amount)->toBe(745000)->and($entry->fresh()->description)->toBe('GFFS paid');

    // Deleted (as the void message says): the invoice is due again and can be voided.
    $this->actingAs($this->owner)->delete("/bdt/{$entry->id}")->assertSessionHasNoErrors();

    expect($invoice->fresh()->paid)->toBe(0)
        ->and($invoice->fresh()->status)->toBe('sent')
        ->and($payment->fresh()->status)->toBe('rejected')
        ->and($payment->fresh()->bdt_entry_id)->toBeNull();

    $this->actingAs($this->owner)->post("/invoices/{$invoice->id}/void")->assertSessionHasNoErrors();
    expect($invoice->fresh()->status)->toBe('void');
});

it('keeps the payment charge of a part payment as the charge, not as money toward the invoice', function () {
    Setting::put('pay_charge_mfs', '1');
    $invoice = ipSentInvoice($this->client, ['allow_partial' => true]);

    // ৳5,000 now on bKash: the session asks ৳5,050.
    $this->postJson(route('invoice.public.start', $invoice->public_token), ['method' => 'bkash', 'payer' => '01712345678', 'amount' => '5000'])->assertOk()
        ->assertJsonPath('session.amount', 505000)
        ->assertJsonPath('session.charge', 5000);

    $this->postJson(route('hooks.sms', IP_SMS_TOKEN), [
        'from' => 'bKash', 'text' => 'You have received Tk 5,050.00 from 01712345678. Fee Tk 0.00. Balance Tk 9,000.00. TrxID PART123456 at '.now()->format('d/m/Y H:i'),
    ])->assertJson(['status' => 'matched']);

    $payment = $invoice->payments()->where('status', 'verified')->sole();
    expect($invoice->fresh()->paid)->toBe(500000)
        ->and($invoice->fresh()->due())->toBe(990000)
        ->and($payment->charge)->toBe(5000)
        ->and(BdtEntry::findOrFail($payment->charge_entry_id)->amount)->toBe(5000);
});

it('still takes no charge when the client sends only the due, and a claim never takes more than the charge on what came', function () {
    Setting::put('pay_charge_mfs', '1');
    $invoice = ipSentInvoice($this->client, ['allow_partial' => true]);

    // A TrxID claim with no session carries the charge on the whole due (৳149); a ৳5,000 part
    // pays at most ৳50 of charge.
    $this->postJson(route('invoice.public.claim', $invoice->public_token), ['method' => 'bkash', 'reference' => 'CLAIM12345', 'sender' => '01712345678', 'amount' => '5000'])->assertOk();
    $claim = $invoice->payments()->where('reference', 'CLAIM12345')->sole();
    expect($claim->charge)->toBe(14900);

    app(Invoices::class)->verify($claim, 500000, $this->owner);
    expect($claim->fresh()->charge)->toBe(5000)
        ->and($invoice->fresh()->paid)->toBe(495000);
});

it('links money already in the book to an invoice only once, however often the form is sent', function () {
    $invoice = ipSentInvoice($this->client);
    $byHand = BdtEntry::create(['entry_date' => now()->subDays(3)->toDateString(), 'description' => 'GFFS paid by hand', 'category' => 'client_payment',
        'client_id' => $this->client->id, 'pay_via' => 'bank', 'wallet' => 'bank', 'amount' => 700000]);

    // Two submits that both passed the "still unlinked" check before either saved.
    $first = $invoice->payments()->create(['method' => 'bank', 'amount' => 700000, 'status' => 'pending']);
    $second = $invoice->payments()->create(['method' => 'bank', 'amount' => 700000, 'status' => 'pending']);

    expect(app(Invoices::class)->verify($first, 700000, $this->owner, existing: $byHand))->toBeTrue()
        ->and(app(Invoices::class)->verify($second, 700000, $this->owner, existing: $byHand->fresh()))->toBeFalse()
        ->and($invoice->fresh()->paid)->toBe(700000)
        ->and($invoice->fresh()->status)->toBe('partial')
        ->and($second->fresh()->status)->toBe('pending');

    // The form again with the same entry is refused, and leaves no stray payment behind.
    $count = $invoice->payments()->count();
    $this->actingAs($this->owner)->post("/invoices/{$invoice->id}/pay", [
        'method' => 'bank', 'wallet' => 'bank', 'paid_on' => now()->toDateString(), 'amount' => '1', 'existing_entry_id' => $byHand->id,
    ])->assertSessionHasErrors('existing_entry_id');
    expect($invoice->payments()->count())->toBe($count)->and($invoice->fresh()->paid)->toBe(700000);
});

it('writes a client\'s unspent credit refund once, and a second click finds nothing left', function () {
    BdtEntry::create(['entry_date' => now()->subDay()->toDateString(), 'description' => 'GFFS advance', 'category' => 'client_payment',
        'client_id' => $this->client->id, 'pay_via' => 'bank', 'wallet' => 'bank', 'amount' => 137000, 'usd_credit' => 1000]);
    $figure = collect(app(Books::class)->clients())->firstWhere('id', $this->client->id);
    expect($figure['credit_left'])->toBeGreaterThan(0)->and($figure['unearned'])->toBeGreaterThan(0);

    $form = ['entry_date' => now()->toDateString(), 'wallet' => 'bank'];
    $this->actingAs($this->owner)->post("/clients/{$this->client->id}/refund", $form)->assertSessionHasNoErrors();
    $this->actingAs($this->owner)->post("/clients/{$this->client->id}/refund", $form)->assertSessionHasErrors('refund');

    expect(BdtEntry::where('category', 'client_refund')->count())->toBe(1)
        ->and(collect(app(Books::class)->clients())->firstWhere('id', $this->client->id)['credit_left'])->toBe(0);
});
