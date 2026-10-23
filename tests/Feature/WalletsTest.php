<?php

use App\Models\BdtEntry;
use App\Models\Client;
use App\Models\Setting;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletMove;
use App\Services\Books;
use App\Services\MoveFees;
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

it('counts each place from its real balance once the places are counted, and books the mismatch', function () {
    $total = app(Books::class)->summary()['bdt'];

    // Counted on a day: bank and bKash as they really were. Older rows no longer need a place.
    $this->actingAs($this->owner)->put('/wallets/opening', [
        'opening_on' => now()->subDay()->toDateString(),
        'balances' => ['bank' => '11122', 'bkash' => '2100', 'cash' => '0'],
    ])->assertRedirect();

    $wallets = app(Books::class)->wallets();
    $places = collect($wallets['places'])->keyBy('key');

    expect($places['bank']['balance'])->toBe(1112200)
        ->and($places['bkash']['balance'])->toBe(210000)
        ->and($wallets['untagged_count'])->toBe(0)
        ->and($wallets['unexplained'])->toBe($total - 1322200);

    // A later payment into bKash counts there.
    $this->actingAs($this->owner)->post('/quick/client-payment', [
        'entry_date' => now()->toDateString(), 'client_id' => Client::where('name', 'GFFS')->value('id'),
        'category' => 'client_payment', 'pay_via' => 'mfs', 'wallet' => 'bkash', 'amount' => '1000',
    ])->assertRedirect();
    expect(collect(app(Books::class)->wallets()['places'])->keyBy('key')['bkash']['balance'])->toBe(310000);

    // Booking the mismatch makes the books and the places agree, and the books still balance.
    $this->actingAs($this->owner)->post('/wallets/adjust')->assertRedirect();
    $books = app(Books::class);
    expect($books->wallets()['unexplained'])->toBe(0)
        ->and($books->summary()['equity_ok'])->toBeTrue();
});

it('adds, renames and removes places; a place with history is only switched off', function () {
    $this->actingAs($this->owner)->post('/wallets/places', ['name' => 'City Bank', 'type' => 'bank', 'detail' => 'A/C 1234'])->assertRedirect();
    $city = Wallet::where('name', 'City Bank')->sole();

    expect(Wallet::keys())->toContain($city->key);

    // Moving out of a bank-type place costs the bank transfer fee; an MFS-type place the %.
    expect(MoveFees::forMove($city->key, 100000)['fee'])->toBe(1000);

    $this->actingAs($this->owner)->put("/wallets/places/{$city->id}", ['name' => 'City Bank Ltd', 'type' => 'bank', 'is_active' => true])->assertRedirect();
    expect($city->fresh()->name)->toBe('City Bank Ltd');

    $this->actingAs($this->owner)->delete("/wallets/places/{$city->id}")->assertRedirect();
    expect(Wallet::find($city->id))->toBeNull();

    $bank = Wallet::where('key', 'bank')->sole();
    BdtEntry::query()->limit(1)->update(['wallet' => 'bank']);
    $this->actingAs($this->owner)->delete("/wallets/places/{$bank->id}")->assertRedirect();
    expect($bank->fresh()->is_active)->toBeFalse();
});

it('counts the real balance typed in today as of that moment, so later rows of today still count', function () {
    $this->travelTo(now()->setTime(10, 0));
    $this->actingAs($this->owner)->put('/wallets/opening', [
        'opening_on' => now()->toDateString(),
        'balances' => ['bank' => '1000', 'bkash' => '0', 'cash' => '0'],
    ])->assertRedirect();

    // An hour later the same day, money comes in: it is on top of the counted 1,000.
    $this->travelTo(now()->addHour());
    BdtEntry::create(['entry_date' => now()->toDateString(), 'description' => 'Later today', 'category' => 'other_income', 'wallet' => 'bank', 'amount' => 25000]);

    $bank = collect(app(Books::class)->wallets()['places'])->firstWhere('key', 'bank');
    expect($bank['balance'])->toBe(125000);
});
