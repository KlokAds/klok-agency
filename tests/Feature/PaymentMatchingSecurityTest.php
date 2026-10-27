<?php

use App\Models\Client;
use App\Models\Invoice;
use App\Models\PaymentSms;
use App\Models\Setting;
use App\Models\User;
use App\Payments\PaymentMatcher;
use App\Payments\PaymentMethod;
use App\Payments\PaySettings;
use App\Services\Invoices;
use App\Support\Access;
use Database\Seeders\AccessSeeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    $this->seed(AccessSeeder::class);

    $this->owner = User::factory()->create();
    $this->owner->assignRole(Access::OWNER);
    $this->clientA = Client::create(['name' => 'Client A', 'type' => 'client', 'status' => 'active', 'phone' => '01711111111']);
    $this->clientB = Client::create(['name' => 'Client B', 'type' => 'client', 'status' => 'active', 'phone' => '01722222222']);

    Setting::put('sms_device_token', Hash::make('pm-security-token-0123456789'));
    foreach (['pay_bkash_on' => '1', 'pay_bkash_number' => '01700000001', 'pay_qr_on' => '1', 'pay_qr_image' => 'payments/qr.png', 'qr_fee_percent' => '0.4'] as $key => $value) {
        Setting::put($key, $value);
    }
    foreach (array_keys(PaySettings::CHARGE_DEFAULTS) as $key) {
        Setting::put($key, '0');
    }
});

/** A sent invoice for ৳14,900. */
function pmSentInvoice(Client $client): Invoice
{
    $invoice = app(Invoices::class)->save([
        'client_id' => $client->id, 'issue_date' => now()->toDateString(), 'due_date' => now()->addWeek()->toDateString(), 'notes' => null,
        'items' => [
            ['kind' => 'ad_budget', 'description' => '', 'usd' => 10000, 'rate' => 137.0, 'amount' => null, 'campaign_id' => null, 'period_from' => null, 'period_to' => null, 'results' => null, 'clicks' => null],
            ['kind' => 'service', 'description' => 'Page management', 'usd' => null, 'rate' => null, 'amount' => 120000, 'campaign_id' => null, 'period_from' => null, 'period_to' => null, 'results' => null, 'clicks' => null],
        ],
    ]);
    app(Invoices::class)->markSent($invoice);

    return $invoice->fresh();
}

function pmSms(string $text): TestResponse
{
    return test()->postJson(route('hooks.sms', 'pm-security-token-0123456789'), ['from' => 'bKash', 'text' => $text]);
}

it('does not settle a new Bangla QR session with money that came in before it opened', function () {
    pmSms('You have received payment Tk 14,900.00 from 01855555555. TrxID EARLY11111 at '.now()->format('d/m/Y H:i'))->assertJson(['status' => 'stored']);

    $invoice = pmSentInvoice($this->clientA);
    $this->postJson("/i/{$invoice->public_token}/pay", ['method' => 'qr'])->assertOk()->assertJsonPath('session.method', 'qr');

    expect($invoice->fresh()->status)->toBe('sent')
        ->and(PaymentSms::where('trx_id', 'EARLY11111')->value('status'))->toBe('unmatched');
});

it('leaves a wallet payment from an unlisted number for the Owner instead of paying another client\'s QR session', function () {
    $invoiceA = pmSentInvoice($this->clientA);
    $invoiceB = pmSentInvoice($this->clientB);

    // Client A waits on Bangla QR, client B on bKash from a number they gave, both ৳14,900.
    $this->postJson("/i/{$invoiceA->public_token}/pay", ['method' => 'qr'])->assertOk();
    $this->postJson("/i/{$invoiceB->public_token}/pay", ['method' => 'bkash', 'payer' => '01799999999'])->assertOk()
        ->assertJsonPath('session.amount', 1490000);

    // Client B pays from another number: it is B's money, so A's QR session must not take it.
    pmSms('You have received Tk 14,900.00 from 01855555555. TrxID WRONGNUM01 at '.now()->format('d/m/Y H:i'))->assertJson(['status' => 'stored']);

    expect($invoiceA->fresh()->status)->toBe('sent')
        ->and($invoiceB->fresh()->status)->toBe('sent')
        ->and($this->owner->notifications()->where('data->kind', 'sms_check')->sole()->data['params']['number'])->toBe($invoiceB->number);
});

it('starts a session for a number known as another client\'s without saying so, and never takes its money', function () {
    $invoice = pmSentInvoice($this->clientA);

    // Client B's phone: the answer is the same as for any number.
    $this->postJson(route('invoice.public.start', $invoice->public_token), ['method' => 'bkash', 'payer' => '01722222222'])
        ->assertOk()->assertJsonPath('session.method', 'bkash');

    pmSms('You have received Tk 14,900.00 from 01722222222. TrxID OTHERCL001 at '.now()->format('d/m/Y H:i'))->assertJson(['status' => 'stored']);
    expect($invoice->fresh()->status)->toBe('sent');
});

it('does not let a portal phone claim a number another client has already paid from', function () {
    // Client B pays once from 01866666666.
    $paid = pmSentInvoice($this->clientB);
    app(PaymentMatcher::class)->startSession($paid, PaymentMethod::Bkash, '01866666666');
    pmSms('You have received Tk 14,900.00 from 01866666666. TrxID BPAID00001 at '.now()->format('d/m/Y H:i'))->assertJson(['status' => 'matched']);

    // A portal person of client A then types that number as their own phone.
    User::factory()->create(['client_id' => $this->clientA->id, 'phone' => '01866666666']);

    expect(PaymentMatcher::clientOfNumber('1866666666'))->toBe($this->clientB->id);

    // Client A's session with that number does not take money from it.
    $invoiceA = pmSentInvoice($this->clientA);
    $this->postJson("/i/{$invoiceA->public_token}/pay", ['method' => 'bkash', 'payer' => '01866666666'])->assertOk();
    pmSms('You have received Tk 14,900.00 from 01866666666. TrxID ASTEAL0001 at '.now()->format('d/m/Y H:i'))->assertJson(['status' => 'stored']);
    expect($invoiceA->fresh()->status)->toBe('sent');

    // Client B still pays from it as before.
    $next = pmSentInvoice($this->clientB);
    $session = app(PaymentMatcher::class)->startSession($next, PaymentMethod::Bkash, '01866666666');
    pmSms('You have received Tk '.number_format($session->amount / 100, 2).' from 01866666666. TrxID BPAID00002 at '.now()->format('d/m/Y H:i'))->assertJson(['status' => 'matched']);
    expect($next->fresh()->status)->toBe('paid');
});
