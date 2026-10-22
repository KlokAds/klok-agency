<?php

use App\Models\BdtEntry;
use App\Models\Client;
use App\Models\Setting;
use App\Models\User;
use App\Models\WalletMove;
use App\Services\Books;
use App\Services\PaymentFees;
use App\Support\Access;
use Database\Seeders\AccessSeeder;
use Database\Seeders\BooksSeeder;

beforeEach(function () {
    $this->seed([AccessSeeder::class, BooksSeeder::class]);
    $this->artisan('books:import-sheet')->assertSuccessful();

    $this->owner = User::factory()->create();
    $this->owner->assignRole(Access::OWNER);
});

it('keeps money the owner took to give back as owed to the business, not a drawing', function () {
    $before = app(Books::class)->summary();

    $this->actingAs($this->owner)->post('/quick/owner', [
        'entry_date' => now()->toDateString(), 'direction' => 'loan', 'wallet' => 'bank', 'amount' => '8500',
    ])->assertRedirect();

    $after = app(Books::class)->summary();

    expect(BdtEntry::latest('id')->first()->category)->toBe('owner_loan')
        ->and($after['bdt'])->toBe($before['bdt'] - 850000)
        ->and($after['owner_due'])->toBe($before['owner_due'] + 850000)
        ->and($after['drawings'])->toBe($before['drawings'])
        ->and($after['equity_ok'])->toBeTrue();

    $this->actingAs($this->owner)->post('/quick/owner', [
        'entry_date' => now()->toDateString(), 'direction' => 'repay', 'wallet' => 'bkash', 'amount' => '3000',
    ])->assertRedirect();

    expect(app(Books::class)->summary()['owner_due'])->toBe($before['owner_due'] + 550000);
});

it('shows each place\'s balance from tagged entries and moves between places', function () {
    BdtEntry::query()->update(['wallet' => 'bank']);
    $total = app(Books::class)->summary()['bdt'];

    $this->actingAs($this->owner)->post('/wallets/moves', [
        'moved_on' => now()->toDateString(), 'from_wallet' => 'bank', 'to_wallet' => 'bkash', 'amount' => '2100',
    ])->assertRedirect();

    $wallets = collect(app(Books::class)->wallets()['places'])->keyBy('key');

    // A bank transfer costs its fee on top (৳10, a Bank charge from the bank).
    expect($wallets['bank']['balance'])->toBe($total - 210000 - 1000)
        ->and($wallets['bkash']['balance'])->toBe(210000)
        ->and(app(Books::class)->wallets()['untagged_count'])->toBe(0)
        ->and(app(Books::class)->wallets()['total'])->toBe($total - 1000);

    // A row can be given a place from the places page; its fee follows.
    Setting::put('payment_fee_percent', '1');
    PaymentFees::backfill();
    $payment = BdtEntry::where('category', 'client_payment')->whereHas('fee')->first();
    $this->actingAs($this->owner)->put("/wallets/tag/{$payment->id}", ['wallet' => 'cash'])->assertRedirect();
    expect($payment->fee->fresh()->wallet)->toBe('cash');

    $this->actingAs($this->owner)->get('/wallets')->assertOk();
    $this->actingAs($this->owner)->post('/wallets/moves', [
        'moved_on' => now()->toDateString(), 'from_wallet' => 'bank', 'to_wallet' => 'bank', 'amount' => '10',
    ])->assertSessionHasErrors('to_wallet');
});

it('takes no fee off payments dated before the fee start day', function () {
    Setting::put('payment_fee_percent', '1');
    Setting::put('payment_fee_from', now()->toDateString());
    $gffs = Client::where('name', 'GFFS')->first();

    foreach ([now()->subDay(), now()] as $day) {
        $this->actingAs($this->owner)->post('/quick/client-payment', [
            'entry_date' => $day->toDateString(), 'client_id' => $gffs->id, 'category' => 'client_payment',
            'pay_via' => 'mfs', 'wallet' => 'bkash', 'amount' => '1000',
        ])->assertRedirect();
    }

    [$older, $newer] = BdtEntry::with('fee')->where('client_id', $gffs->id)->latest('id')->limit(2)->get()->reverse()->values()->all();

    expect($older->fee)->toBeNull()
        ->and($newer->fee->amount)->toBe(-1000)
        ->and($newer->fee->wallet)->toBe('bkash');
});

it('takes 1% out of a move from bKash and a fixed fee on top of a bank transfer, as Bank charge', function () {
    BdtEntry::query()->update(['wallet' => 'bkash']);
    $places = fn () => collect(app(Books::class)->wallets()['places'])->keyBy('key');
    $bkash = $places()['bkash']['balance'];
    $total = app(Books::class)->summary()['bdt'];

    $this->actingAs($this->owner)->post('/wallets/moves', [
        'moved_on' => now()->toDateString(), 'from_wallet' => 'bkash', 'to_wallet' => 'bank', 'amount' => '2100',
    ])->assertRedirect();

    expect($places()['bkash']['balance'])->toBe($bkash - 210000)
        ->and($places()['bank']['balance'])->toBe(207900)
        ->and(app(Books::class)->summary()['bdt'])->toBe($total - 2100)
        ->and(BdtEntry::latest('id')->first()->head->name)->toBe('Bank charge');

    $this->actingAs($this->owner)->post('/wallets/moves', [
        'moved_on' => now()->toDateString(), 'from_wallet' => 'bank', 'to_wallet' => 'cash', 'amount' => '1000',
    ])->assertRedirect();

    expect($places()['bank']['balance'])->toBe(207900 - 100000 - 1000)
        ->and($places()['cash']['balance'])->toBe(100000);

    // Deleting a move takes its fee with it.
    $move = WalletMove::latest('id')->first();
    $this->actingAs($this->owner)->delete("/wallets/moves/{$move->id}")->assertRedirect();
    expect($places()['bank']['balance'])->toBe(207900);
});

it('adds the bank transfer fee when owner money leaves the bank by transfer', function () {
    $before = app(Books::class)->summary()['bdt'];

    $this->actingAs($this->owner)->post('/quick/owner', [
        'entry_date' => now()->toDateString(), 'direction' => 'loan', 'wallet' => 'bank', 'amount' => '8500', 'bank_fee' => true,
    ])->assertRedirect();

    expect(app(Books::class)->summary()['bdt'])->toBe($before - 850000 - 1000)
        ->and(BdtEntry::latest('id')->first()->description)->toStartWith('Bank transfer fee');
});
