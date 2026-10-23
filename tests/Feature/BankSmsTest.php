<?php

use App\Models\Setting;
use App\Models\User;
use App\Payments\PaySettings;
use App\Payments\SmsParser;
use App\Support\Access;
use Database\Seeders\AccessSeeder;

// The bank alerts below are shaped like the ones on the Owner's phone (numbers changed).
beforeEach(function () {
    Setting::put('pay_bank_sms_senders', 'IslamiBank, IBBL, 01844-242613');
});

it('trusts a bank that sends from an ordinary number, however the phone writes it', function () {
    $parser = app(SmsParser::class);

    foreach (['+8801844242613', '8801844242613', '01844242613', '01844-242613'] as $from) {
        expect($parser->providerFor($from))->toBe('bank');
    }

    // Any other number, or a look-alike name, is not the bank.
    expect($parser->providerFor('+8801844242614'))->toBeNull()
        ->and($parser->providerFor('Islami Bank Ltd'))->toBeNull();
});

it('reads the credit alerts, and leaves debits, OTPs and offers alone', function () {
    $parser = app(SmsParser::class);

    $atm = $parser->parse('+8801844242613', 'Dear Customer ATM Tk 8500 has been credited to A/C279**00240307 Balance Tk.8503.7 Islami Bank');
    expect($atm['provider'])->toBe('bank')
        ->and($atm['amount'])->toBe(850000)
        ->and($atm['trx_id'])->toStartWith('SMS');

    $cellfin = $parser->parse('IslamiBank', "Islami Bank CellFin\nBDT 1,015.00 Received from 01712345678. Balance BDT 2,030.00");
    expect($cellfin['amount'])->toBe(101500);

    Setting::put('pay_bank_sms_senders', 'IslamiBank, IBBL, 01844-242613, UCB');
    $ucb = $parser->parse('UCB', 'Your A/C (***1603) has been credited BDT 5,000.00 by NPSB Transfer. Avl Bal BDT 13,500.00');
    expect($ucb['amount'])->toBe(500000);
    Setting::put('pay_bank_sms_senders', 'IslamiBank, IBBL, 01844-242613');

    expect($parser->parse('IslamiBank', 'Your A/C (***1603) has been debited BDT 8,500.00 for I Banking NPSB Transfer Debit.'))->toBeNull()
        ->and($parser->parse('IBBL', 'CellFin OTP is 513420. Never share it.'))->toBeNull()
        ->and($parser->parse('UCB', 'Tk 8,500.00 has been credited to your A/C'))->toBeNull(); // UCB is not in the list
});

it('gives the phone every form of each sender to look for', function () {
    expect(PaySettings::senderForms())->toContain('bkash', 'nagad', '16216', 'islamibank', 'ibbl', '01844242613', '+8801844242613', '8801844242613');
});

it('shows in Settings what one pasted SMS reads as', function () {
    $this->seed(AccessSeeder::class);
    $owner = User::factory()->create();
    $owner->assignRole(Access::OWNER);

    $this->actingAs($owner)->postJson('/admin/payment/sms-test', [
        'from' => '01844-242613',
        'text' => 'Dear Customer ATM Tk 8500 has been credited to A/C279**00240307 Balance Tk.8503.7',
    ])->assertOk()->assertJson(['trusted' => true, 'provider' => 'bank', 'incoming' => true, 'made_up_id' => true]);

    $this->actingAs($owner)->postJson('/admin/payment/sms-test', ['from' => '01999999999', 'text' => 'You have received Tk 500.00'])
        ->assertJson(['trusted' => false, 'incoming' => false]);
});

it('reads the UCB alerts exactly as they come on the Owner phone', function () {
    Setting::put('pay_bank_sms_senders', 'UCB');
    $parser = app(SmsParser::class);

    $credit = $parser->parse('UCB', 'Your A/C (***1603) has been credited BDT 80.00 for I BANKING FUND TRANSFER - CREDIT. Avl Bal: BDT 19,632.00 @ 12:41 AM. For query: 16419');
    expect($credit['amount'])->toBe(8000)->and($credit['provider'])->toBe('bank');

    foreach ([
        'Your A/C (***6318) has been debited BDT 80.00 for Ibanking Fund Transfer - Debit. Avl Bal: BDT 2,500.00 @ 12:41 AM. For query: 16419',
        "2237 is your OTP\nFor UCB One NPSB, Amount BDT 8,500.00.\nValidity is 2.0 minutes.\nNever share your OTP/PIN/Password RLnTZL1W6wd",
        'Your A/C (***1603) has been debited BDT 8.70 for NPSB CHARGE. Avl Bal: BDT 11,122.00 @ 09:38 AM. For query: 16419',
        'Your A/C (***1603) has been debited BDT 8,500.00 for I Banking NPSB Transfer Debit. Avl Bal: BDT 11,122.00 @ 09:38 AM. For query: 16419',
    ] as $text) {
        expect($parser->parse('UCB', $text))->toBeNull();
    }
});
