<?php

use App\Models\Account;
use App\Models\CardEvent;
use App\Models\FxBatch;
use App\Models\MoneyApp;
use App\Models\Setting;
use App\Models\UsdEntry;
use App\Models\User;
use App\Notifications\AgencyAlert;
use App\Services\Books;
use App\Services\CardEvents;
use App\Support\Access;
use Database\Seeders\AccessSeeder;
use Database\Seeders\BooksSeeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;

const MONEY_TOKEN = 'money-app-test-token-0123456789abcdefghij';

beforeEach(function () {
    $this->seed([AccessSeeder::class, BooksSeeder::class]);
    $this->owner = User::factory()->create();
    $this->owner->assignRole(Access::OWNER);
    Setting::put('sms_device_token', Hash::make(MONEY_TOKEN));

    // $500 bought, on the main card: NSave main, card 6903, an app already approved.
    FxBatch::create(['number' => 1, 'purchased_on' => now()->subDays(10)->toDateString(), 'description' => 'USD bought', 'usd' => 50000, 'bdt' => 6100000]);
    $this->main = Account::card();
    $this->main->update(['last4' => '6903', 'app_package' => 'com.nsave.app']);
    MoneyApp::create(['package' => 'com.nsave.app', 'name' => 'NSave', 'status' => 'on']);
    UsdEntry::create(['entry_date' => now()->subDays(10)->toDateString(), 'description' => 'Deposit 1', 'category' => 'deposit', 'account_id' => $this->main->id, 'amount' => 50000, 'status' => 'na']);
});

/** NSave's copy card: its money comes from the main card. */
function copyCard(string $last4 = '4321'): Account
{
    return Account::create(['code' => 'nsave_copy', 'name' => 'NSave copy', 'kind' => 'card', 'last4' => $last4, 'app_package' => 'com.nsave.app', 'topup_from_id' => Account::card()->id, 'is_active' => true, 'sort' => 2]);
}

/** The phone forwarding one card or bank app's notification, as the app sends it. */
function moneyNote(string $text, string $package = 'com.nsave.app', string $name = 'NSave'): TestResponse
{
    return test()->postJson('/hooks/sms/'.MONEY_TOKEN, [
        'from' => 'app', 'text' => $text, 'receivedStamp' => now()->getTimestamp() * 1000, 'serviceCentre' => '', 'package' => $package, 'appName' => $name,
    ]);
}

it('reads the usual ways a card app tells a charge, refund, money added, a decline and an OTP', function () {
    expect(CardEvents::read("Card transaction\nYou spent \$1.00 at ELEPHANTS LAB with card ending 4321."))
        ->toMatchArray(['kind' => 'charge', 'amount' => 100, 'currency' => 'USD', 'merchant' => 'ELEPHANTS LAB', 'last4' => '4321'])
        ->and(CardEvents::read('Refund of USD 5.50 from Google Ads to card •••• 6903')['kind'])->toBe('refund')
        ->and(CardEvents::read('$50.00 added to your card ****4321')['kind'])->toBe('topup')
        ->and(CardEvents::read('Transaction declined: $3.00 at Netflix, card *4321')['kind'])->toBe('declined')
        ->and(CardEvents::read('Your OTP for $1.00 at ELEPHANTS LAB is 482913')['kind'])->toBe('other')
        ->and(CardEvents::read('Get 5% cashback this weekend!')['amount'])->toBeNull();
});

it("reads NSave's own words, as MH-Bot received them", function () {
    expect(CardEvents::read('Card payment approved. 💳 Paid $1.00 at Elevenlabs.io with your card ending 6903'))
        ->toMatchArray(['kind' => 'charge', 'amount' => 100, 'merchant' => 'Elevenlabs.io', 'last4' => '6903'])
        ->and(CardEvents::read('Card payment approved. 💳 Paid $19.00 at FACEBK* Z25JQBJSD4 with your card ending 6903'))
        ->toMatchArray(['kind' => 'charge', 'amount' => 1900, 'merchant' => 'FACEBK* Z25JQBJSD4', 'last4' => '6903'])
        // The ask before the charge is not the charge.
        ->and(CardEvents::read('A card payment needs your approval. ELEVENLABS-IO 1.00 USD')['kind'])->toBe('other')
        ->and(CardEvents::read('Money received. 💰 Received $327.00 from Md Mujaid Hossain'))
        ->toMatchArray(['kind' => 'topup', 'amount' => 32700, 'merchant' => 'Md Mujaid Hossain'])
        ->and(CardEvents::read('4 days left to win $500 💰. Spend $50 this month and enter the draw automatically.')['kind'])->toBe('other');

    // Approved, then the ask arrives late: one charge only.
    moneyNote('Card payment approved. 💳 Paid $1.00 at Elevenlabs.io with your card ending 6903')->assertJson(['status' => 'card_booked']);
    moneyNote('A card payment needs your approval. ELEVENLABS-IO 1.00 USD')->assertJson(['status' => 'card_ignored']);
    expect((new Books)->summary()['card'])->toBe(49900);
});

it('books a charge on the copy card from its own balance, and both cards add up to the card', function () {
    $copy = copyCard();
    moneyNote('$50.00 added to your card ****4321')->assertJson(['status' => 'card_booked']);
    moneyNote("Card transaction\nYou spent \$1.00 at ELEPHANTS LAB with card ending 4321.")->assertJson(['status' => 'card_booked']);

    $cards = collect(app(Books::class)->cardBalances())->keyBy('id');
    $summary = (new Books)->summary();
    expect($cards[$copy->id]['balance'])->toBe(4900)
        ->and($cards[$this->main->id]['balance'])->toBe(45000)
        ->and($summary['card'])->toBe(49900)
        ->and($summary['usd_check']['ok'])->toBeTrue()
        ->and(UsdEntry::where('description', 'NSave · ELEPHANTS LAB')->sole()->account_id)->toBe($copy->id);

    // The phone posts the same notification again: still once.
    moneyNote("Card transaction\nYou spent \$1.00 at ELEPHANTS LAB with card ending 4321.");
    expect(CardEvent::count())->toBe(2)->and((new Books)->summary()['card'])->toBe(49900);
});

it('still takes the first app build, which sent NSave by name', function () {
    $this->postJson('/hooks/sms/'.MONEY_TOKEN, ['from' => 'NSave', 'text' => 'You spent $2.00 at Canva with card ending 6903', 'package' => 'com.nsave.app'])
        ->assertJson(['status' => 'card_booked']);
    expect((new Books)->summary()['card'])->toBe(49800);
});

it('joins a Meta charge with the one already read from Meta, on the card that paid it', function () {
    $copy = copyCard();
    $meta = Account::where('code', 'meta')->first();
    $fromMeta = UsdEntry::create(['entry_date' => now()->toDateString(), 'description' => 'Meta charge · card 4321', 'category' => 'card_charge', 'account_id' => $meta->id, 'amount' => -3800, 'status' => 'na']);

    moneyNote('You spent $38.00 at FACEBK *ADS with card ending 4321')->assertJson(['status' => 'card_duplicate']);

    expect(UsdEntry::where('category', 'card_charge')->count())->toBe(1)
        ->and($fromMeta->fresh()->card_id)->toBe($copy->id)
        ->and(collect(app(Books::class)->cardBalances())->firstWhere('id', $copy->id)['balance'])->toBe(-3800);
});

it('asks the Owner once about a new app, and books what waited once it is approved', function () {
    Notification::fake();

    moneyNote('You spent $12.00 at Canva with card ending 7777', 'com.fasset.app', 'Fasset')->assertJson(['status' => 'card_held']);
    moneyNote('You spent $3.00 at Figma with card ending 7777', 'com.fasset.app', 'Fasset')->assertJson(['status' => 'card_held']);
    $app = MoneyApp::where('package', 'com.fasset.app')->sole();

    expect($app->status)->toBe('pending')->and($app->name)->toBe('Fasset')
        ->and((new Books)->summary()['card'])->toBe(50000);
    Notification::assertSentTo($this->owner, AgencyAlert::class, fn (AgencyAlert $n) => $n->title === CardEvents::NEW_APP_TITLE);
    Notification::assertSentToTimes($this->owner, AgencyAlert::class, 1);

    $this->actingAs($this->owner)->post("/dollars/money-apps/{$app->id}/approve", [
        'mode' => 'new', 'name' => 'Fasset card', 'kind' => 'card', 'last4' => '7777', 'topup_from_id' => $this->main->id,
    ])->assertSessionHasNoErrors();

    $fasset = Account::where('app_package', 'com.fasset.app')->sole();
    expect($app->fresh()->status)->toBe('on')
        ->and(CardEvent::where('package', 'com.fasset.app')->pluck('status')->unique()->all())->toBe(['booked'])
        ->and(collect(app(Books::class)->cardBalances())->firstWhere('id', $fasset->id)['balance'])->toBe(-1500);
});

it('keeps nothing from an app that only sent something without a dollar amount', function () {
    moneyNote('Your weekly summary is ready', 'com.random.app', 'Random')->assertJson(['status' => 'card_ignored']);
    expect(MoneyApp::where('package', 'com.random.app')->exists())->toBeFalse();
});

it('lets the Owner turn an app off and on again; what came while off stays out', function () {
    $app = MoneyApp::where('package', 'com.nsave.app')->sole();
    $this->actingAs($this->owner)->post("/dollars/money-apps/{$app->id}/turn", ['on' => false])->assertRedirect();

    moneyNote('You spent $5.00 at Canva with card ending 6903')->assertJson(['status' => 'card_ignored']);
    expect((new Books)->summary()['card'])->toBe(50000);

    $this->actingAs($this->owner)->post("/dollars/money-apps/{$app->id}/turn", ['on' => true])->assertRedirect();
    moneyNote('You spent $6.00 at Notion with card ending 6903')->assertJson(['status' => 'card_booked']);
    expect($app->fresh()->status)->toBe('on')->and((new Books)->summary()['card'])->toBe(49400);
});

it('joins a new app to an account already in the books', function () {
    UsdEntry::create(['entry_date' => now()->toDateString(), 'description' => 'Elevate', 'category' => 'transfer', 'account_id' => $this->main->id, 'amount' => -1000, 'status' => 'na', 'pair_key' => 'x']);
    $holding = Account::where('kind', 'holding')->first();
    UsdEntry::create(['entry_date' => now()->toDateString(), 'description' => 'Elevate', 'category' => 'transfer', 'account_id' => $holding->id, 'amount' => 1000, 'status' => 'na', 'pair_key' => 'x']);

    moneyNote('Payment of $4.00 to Notion', 'com.elevate.pay', 'Elevate Pay');
    $app = MoneyApp::where('package', 'com.elevate.pay')->sole();
    $this->actingAs($this->owner)->post("/dollars/money-apps/{$app->id}/approve", ['mode' => 'link', 'account_id' => $holding->id])->assertSessionHasNoErrors();

    expect($holding->fresh()->app_package)->toBe('com.elevate.pay')
        ->and((int) UsdEntry::where('account_id', $holding->id)->sum('amount'))->toBe(600)
        ->and((new Books)->summary()['usd_check']['ok'])->toBeTrue();
});

it('lets the Owner put a held line on a card, or set it aside', function () {
    // Two cards of the app, and the notification names neither: the Owner says which.
    copyCard();
    moneyNote('Payment of $9.00 to Figma')->assertJson(['status' => 'card_held']);
    $event = CardEvent::sole();
    expect($event->problem)->toContain('card');

    $this->actingAs($this->owner)->post("/dollars/card-events/{$event->id}/place", ['card_id' => $this->main->id])->assertRedirect();
    expect($event->fresh()->status)->toBe('booked')->and((new Books)->summary()['card'])->toBe(49100);

    moneyNote('Payment of $4.00 to Notion');
    $other = CardEvent::where('id', '!=', $event->id)->sole();
    $this->actingAs($this->owner)->post("/dollars/card-events/{$other->id}/dismiss")->assertRedirect();
    expect($other->fresh()->status)->toBe('ignored')->and((new Books)->summary()['card'])->toBe(49100);
});

it('gives a refund back with its cost, and leaves money added to the main card for the Owner', function () {
    moneyNote('You spent $20.00 at Canva with card ending 6903');
    moneyNote('Refund of $20.00 from Canva to card ending 6903')->assertJson(['status' => 'card_booked']);

    $books = new Books;
    expect($books->summary()['card'])->toBe(50000)
        ->and($books->summary()['usd_check']['ok'])->toBeTrue()
        ->and($books->fifo()['consumed'])->toBe(0);

    // New dollars on the main card were bought with taka: the Owner writes that with its rate.
    moneyNote('$100.00 added to your card ending 6903')->assertJson(['status' => 'card_held']);
    expect((new Books)->summary()['card'])->toBe(50000);
});

it('changes nothing for a decline, an OTP or an offer', function () {
    moneyNote('Transaction declined: $3.00 at Netflix, card *6903')->assertJson(['status' => 'card_ignored']);
    moneyNote('Your OTP for $1.00 at ELEPHANTS LAB is 482913')->assertJson(['status' => 'card_ignored']);
    moneyNote('Get 5% cashback this weekend!')->assertJson(['status' => 'card_ignored']);

    expect((new Books)->summary()['card'])->toBe(50000)->and(UsdEntry::count())->toBe(1);
});
