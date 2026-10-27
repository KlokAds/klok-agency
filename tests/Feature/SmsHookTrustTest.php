<?php

use App\Http\Controllers\Invoices\SmsHookController;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\PaymentSms;
use App\Models\Setting;
use App\Models\User;
use App\Payments\PaymentMatcher;
use App\Payments\PaymentMethod;
use App\Payments\PaySettings;
use App\Payments\SmsParser;
use App\Services\Invoices;
use App\Support\Access;
use Database\Seeders\AccessSeeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;

const TRUST_TOKEN = 'trust-test-token-0123456789abcdefghijklm';

beforeEach(function () {
    $this->seed(AccessSeeder::class);

    $this->owner = User::factory()->create();
    $this->owner->assignRole(Access::OWNER);
    $this->clientA = Client::create(['name' => 'Client A', 'type' => 'client', 'status' => 'active', 'phone' => '01711111111']);
    $this->clientB = Client::create(['name' => 'Client B', 'type' => 'client', 'status' => 'active', 'phone' => '01722222222']);

    Setting::put('sms_device_token', Hash::make(TRUST_TOKEN));
    foreach (['pay_bkash_on' => '1', 'pay_bkash_number' => '01700000001', 'pay_bank_sms_senders' => '01844-242613'] as $key => $value) {
        Setting::put($key, $value);
    }
    foreach (array_keys(PaySettings::CHARGE_DEFAULTS) as $key) {
        Setting::put($key, '0');
    }
});

/** A sent invoice for ৳14,900. */
function trustInvoice(Client $client): Invoice
{
    $invoice = app(Invoices::class)->save([
        'client_id' => $client->id, 'issue_date' => now()->toDateString(), 'due_date' => now()->addWeek()->toDateString(), 'notes' => null,
        'items' => [
            ['kind' => 'service', 'description' => 'Page management', 'usd' => null, 'rate' => null, 'amount' => 1490000, 'campaign_id' => null, 'period_from' => null, 'period_to' => null, 'results' => null, 'clicks' => null],
        ],
    ]);
    app(Invoices::class)->markSent($invoice);

    return $invoice->fresh();
}

/** The phone forwarding an SMS, with the service centre that delivered it (null: an older app). */
function trustSms(string $text, ?string $centre, string $from = 'bKash', string $token = TRUST_TOKEN): TestResponse
{
    return test()->postJson(route('hooks.sms', $token), array_filter(['from' => $from, 'text' => $text, 'serviceCentre' => $centre]));
}

function trustBody(int $taka, string $from, string $trx, ?int $balance = null): string
{
    return 'You have received Tk '.number_format($taka, 2).' from '.$from.'. Fee Tk 0.00.'
        .($balance !== null ? ' Balance Tk '.number_format($balance, 2).'.' : '').' TrxID '.$trx.' at '.now()->format('d/m/Y H:i');
}

it('checks the hook token by bcrypt only once, then by a cheap HMAC', function () {
    trustSms('', null)->assertJson(['status' => 'heartbeat']);
    expect(Setting::get('sms_device_token_mac'))->toStartWith(hash('sha256', (string) Setting::get('sms_device_token')).':');

    // From now on neither the right token nor a guess runs bcrypt.
    Hash::shouldReceive('check')->never();
    trustSms('', null)->assertJson(['status' => 'heartbeat']);
    trustSms('', null, token: str_repeat('x', 40))->assertNotFound();
});

it('lets a new token in and keeps the old one out after the token changes', function () {
    trustSms('', null)->assertOk();

    Setting::put('sms_device_token', Hash::make('another-token-0123456789abcdefghijklmnop'));

    trustSms('', null)->assertNotFound();
    trustSms('', null, token: 'another-token-0123456789abcdefghijklmnop')->assertOk();
    trustSms('', null, token: 'another-token-0123456789abcdefghijklmnop')->assertOk();
    trustSms('', null)->assertNotFound();
});

it('does not settle a TrxID claim from an SMS that came later from another client\'s number', function () {
    $invoiceA = trustInvoice($this->clientA);

    // Client A claims a TrxID before its SMS reached the server, typing client B's number.
    $this->postJson("/i/{$invoiceA->public_token}/claim", ['method' => 'bkash', 'reference' => 'BSPAY00001', 'sender' => '01722222222'])->assertOk();

    // It is B's money: A's invoice does not take it, and the Owner is told.
    trustSms(trustBody(14900, '01722222222', 'BSPAY00001'), null)->assertJson(['status' => 'stored']);

    expect($invoiceA->fresh()->status)->toBe('sent')
        ->and(PaymentSms::where('trx_id', 'BSPAY00001')->value('status'))->toBe('unmatched')
        ->and($this->owner->notifications()->where('data->kind', 'sms_check')->sole()->data['params']['number'])->toBe($invoiceA->number);
});

it('does not give all of an SMS to a claim that asked for far less', function () {
    $invoiceA = trustInvoice($this->clientA);

    $this->postJson("/i/{$invoiceA->public_token}/claim", ['method' => 'bkash', 'reference' => 'ZERO000001', 'sender' => '01733333333', 'amount' => '0'])->assertOk();
    trustSms(trustBody(14900, '01733333333', 'ZERO000001'), null)->assertJson(['status' => 'stored']);

    expect($invoiceA->fresh()->status)->toBe('sent');
});

it('still settles a client\'s own TrxID claim when the SMS comes later', function () {
    $invoiceA = trustInvoice($this->clientA);

    $this->postJson("/i/{$invoiceA->public_token}/claim", ['method' => 'bkash', 'reference' => 'OWNPAY0001', 'sender' => '01711111111'])->assertOk();
    trustSms(trustBody(14900, '01711111111', 'OWNPAY0001'), '+8801700000600')->assertJson(['status' => 'matched']);

    expect($invoiceA->fresh()->status)->toBe('paid');
});

it('learns a sender\'s service centre from its first SMS, even one that is not money', function () {
    trustSms('Your bKash verification code is 123456.', '+8801700000600')->assertJson(['status' => 'ignored']);

    $invoice = trustInvoice($this->clientA);
    app(PaymentMatcher::class)->startSession($invoice, PaymentMethod::Bkash, '01711111111');
    trustSms(trustBody(14900, '01711111111', 'REAL000001', 20000), '8801700000600')->assertJson(['status' => 'matched']);

    expect($invoice->fresh()->status)->toBe('paid')
        ->and(PaymentSms::where('trx_id', 'REAL000001')->first())->service_centre->toBe('01700000600')->balance->toBe(2000000)->held->toBeFalse();
});

it('holds an SMS from an unknown service centre for the Owner, and the Owner can still match it', function () {
    trustSms(trustBody(100, '01799999999', 'FIRST00001', 5100), '+8801700000600')->assertJson(['status' => 'stored']);

    $invoice = trustInvoice($this->clientA);
    app(PaymentMatcher::class)->startSession($invoice, PaymentMethod::Bkash, '01711111111');

    // A forged "bKash" SMS through a foreign gateway: nothing is paid, the Owner checks it.
    trustSms(trustBody(14900, '01711111111', 'FORGED0001', 99999), '+447700900123')->assertJson(['status' => 'stored']);

    $sms = PaymentSms::where('trx_id', 'FORGED0001')->sole();
    expect($invoice->fresh()->status)->toBe('sent')
        ->and($sms->held)->toBeTrue()
        ->and($this->owner->notifications()->where('data->kind', 'sms_check')->sole()->data['title'])->toBe(SmsHookController::HELD_TITLE);

    // A claim typed afterwards does not take it either.
    $this->postJson("/i/{$invoice->public_token}/claim", ['method' => 'bkash', 'reference' => 'FORGED0001', 'sender' => '01711111111'])->assertOk();
    expect($invoice->fresh()->status)->toBe('sent');

    // The Owner saw the money in the wallet and matched it: that centre is now known.
    $this->actingAs($this->owner)->post("/invoices/sms/{$sms->id}/assign", ['invoice_id' => $invoice->id])->assertRedirect();
    expect($invoice->fresh()->status)->toBe('paid')
        ->and(app(PaymentMatcher::class)->genuine('bKash', '447700900123', null, null, now()))->toBeTrue();
});

it('takes an SMS from a new service centre when its balance follows the last one', function () {
    trustSms(trustBody(100, '01799999999', 'FIRST00002', 5100), '+8801700000600')->assertJson(['status' => 'stored']);

    $invoice = trustInvoice($this->clientA);
    app(PaymentMatcher::class)->startSession($invoice, PaymentMethod::Bkash, '01711111111');

    // ৳5,100 + ৳14,900 = ৳20,000: the wallet's own balance, which a forger cannot know.
    trustSms(trustBody(14900, '01711111111', 'ROUTE00001', 20000), '+8801900000700')->assertJson(['status' => 'matched']);

    expect($invoice->fresh()->status)->toBe('paid')
        ->and(app(PaymentMatcher::class)->genuine('bKash', '01900000700', null, null, now()))->toBeTrue();
});

it('holds an SMS without a service centre once the sender\'s centre is known', function () {
    trustSms(trustBody(100, '01799999999', 'FIRST00003'), '+8801700000600')->assertOk();

    trustSms(trustBody(14900, '01711111111', 'NOCENTRE01'), null)->assertJson(['status' => 'stored']);

    expect(PaymentSms::where('trx_id', 'NOCENTRE01')->value('held'))->toBeTrue();
});

it('keeps a numeric bank sender working, and holds a spoof of it', function () {
    $credit = fn (string $trx) => "Your A/C (***1603) has been credited BDT 2,000.00 by NPSB Transfer. Ref {$trx}. Avl Bal BDT 9,000.00";

    trustSms($credit('BANKREF001'), '+8801819000000', '+8801844242613')->assertJson(['status' => 'stored']);
    trustSms($credit('BANKREF002'), '+8801819000000', '01844-242613')->assertJson(['status' => 'stored']);
    trustSms($credit('BANKREF003'), '+15550001111', '01844242613')->assertJson(['status' => 'stored']);

    expect(PaymentSms::where('provider', 'bank')->orderBy('id')->pluck('held')->all())->toBe([false, false, true]);
});

it('does not book a refund from a Send Money SMS that does not look genuine', function () {
    trustSms(trustBody(100, '01799999999', 'FIRST00004'), '+8801700000600')->assertOk();

    trustSms('Send Money Tk 14,900.00 to 01711111111 successful. Fee Tk 0.00. Balance Tk 1.00. TrxID SENT000001 at '.now()->format('d/m/Y H:i'), '+447700900123')
        ->assertJson(['status' => 'ignored']);

    expect(PaymentSms::where('trx_id', 'SENT000001')->exists())->toBeFalse();
});

it('reads the balance and fee wallets and banks show', function () {
    $parser = app(SmsParser::class);

    expect($parser->balance('You have received Tk 500.00 from 01711111111. Fee Tk 0.00. Balance Tk 1,500.50. TrxID ABC123'))->toBe(150050)
        ->and($parser->balance('Money Received. Amount: Tk 500.00 Sender: 01711111111 TxnID: 7XYZ Balance: Tk 2,000.00'))->toBe(200000)
        ->and($parser->balance('credited BDT 80.00 for I BANKING. Avl Bal: BDT 19,632.00 @ 12:41 AM'))->toBe(1963200)
        ->and($parser->balance('Your bKash verification code is 123456.'))->toBeNull()
        ->and($parser->fee('Send Money Tk 100.00 to 01711111111 successful. Fee Tk 5.00.'))->toBe(500)
        ->and($parser->fee('You have received Tk 500.00'))->toBe(0);
});
