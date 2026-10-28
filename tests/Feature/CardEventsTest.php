<?php

use App\Models\Account;
use App\Models\CardEvent;
use App\Models\FxBatch;
use App\Models\Setting;
use App\Models\UsdEntry;
use App\Models\User;
use App\Services\Books;
use App\Services\CardEvents;
use App\Support\Access;
use Database\Seeders\AccessSeeder;
use Database\Seeders\BooksSeeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;

const NSAVE_TOKEN = 'nsave-test-token-0123456789abcdefghijklm';

beforeEach(function () {
    $this->seed([AccessSeeder::class, BooksSeeder::class]);
    $this->owner = User::factory()->create();
    $this->owner->assignRole(Access::OWNER);
    Setting::put('sms_device_token', Hash::make(NSAVE_TOKEN));

    // $500 bought, on the main card (NSave main, card 6903).
    FxBatch::create(['number' => 1, 'purchased_on' => now()->subDays(10)->toDateString(), 'description' => 'USD bought', 'usd' => 50000, 'bdt' => 6100000]);
    $this->main = Account::card();
    $this->main->update(['last4' => '6903']);
    UsdEntry::create(['entry_date' => now()->subDays(10)->toDateString(), 'description' => 'Deposit 1', 'category' => 'deposit', 'account_id' => $this->main->id, 'amount' => 50000, 'status' => 'na']);
});

/** The phone forwarding one NSave notification, as the app sends it. */
function nsave(string $text, ?int $at = null): TestResponse
{
    return test()->postJson('/hooks/sms/'.NSAVE_TOKEN, [
        'from' => 'NSave', 'text' => $text, 'receivedStamp' => ($at ?? now()->getTimestamp()) * 1000, 'serviceCentre' => '', 'package' => 'com.nsave.app',
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

it('books a charge on the copy card from its own balance, and both cards add up to the card', function () {
    $copy = Account::create(['code' => 'nsave_copy', 'name' => 'NSave copy', 'kind' => 'card', 'last4' => '4321', 'is_active' => true, 'sort' => 2]);
    nsave('$50.00 added to your card ****4321')->assertJson(['status' => 'card_booked']);
    nsave("Card transaction\nYou spent \$1.00 at ELEPHANTS LAB with card ending 4321.")->assertJson(['status' => 'card_booked']);

    $cards = collect(app(Books::class)->cardBalances())->keyBy('id');
    $summary = (new Books)->summary();
    expect($cards[$copy->id]['balance'])->toBe(4900)
        ->and($cards[$this->main->id]['balance'])->toBe(45000)
        ->and($summary['card'])->toBe(49900)
        ->and($summary['usd_check']['ok'])->toBeTrue()
        ->and(UsdEntry::where('description', 'NSave · ELEPHANTS LAB')->sole()->account_id)->toBe($copy->id);

    // The phone posts the same notification again: still once.
    nsave("Card transaction\nYou spent \$1.00 at ELEPHANTS LAB with card ending 4321.");
    expect(CardEvent::count())->toBe(2)->and((new Books)->summary()['card'])->toBe(49900);
});

it('joins a Meta charge with the one already read from Meta, on the card that paid it', function () {
    $copy = Account::create(['code' => 'nsave_copy', 'name' => 'NSave copy', 'kind' => 'card', 'last4' => '4321', 'is_active' => true, 'sort' => 2]);
    $meta = Account::where('code', 'meta')->first();
    $fromMeta = UsdEntry::create(['entry_date' => now()->toDateString(), 'description' => 'Meta charge · card 4321', 'category' => 'card_charge', 'account_id' => $meta->id, 'amount' => -3800, 'status' => 'na']);

    nsave('You spent $38.00 at FACEBK *ADS with card ending 4321')->assertJson(['status' => 'card_duplicate']);

    expect(UsdEntry::where('category', 'card_charge')->count())->toBe(1)
        ->and($fromMeta->fresh()->card_id)->toBe($copy->id)
        ->and(collect(app(Books::class)->cardBalances())->firstWhere('id', $copy->id)['balance'])->toBe(-3800);
});

it('holds a card it does not know, and books it once the card is added', function () {
    nsave('You spent $12.00 at Canva with card ending 7777')->assertJson(['status' => 'card_held']);
    expect((new Books)->summary()['card'])->toBe(50000);

    $this->actingAs($this->owner)->post('/dollars/accounts', ['name' => 'NSave copy', 'kind' => 'card', 'last4' => '7777'])->assertSessionHasNoErrors();

    expect(CardEvent::sole()->status)->toBe('booked')
        ->and((new Books)->summary()['card'])->toBe(48800);
});

it('lets the Owner put a held line on a card, or set it aside', function () {
    // Two cards, and the notification names neither: the Owner says which.
    Account::create(['code' => 'nsave_copy', 'name' => 'NSave copy', 'kind' => 'card', 'last4' => '4321', 'is_active' => true, 'sort' => 2]);
    nsave('Payment of $9.00 to Figma')->assertJson(['status' => 'card_held']);
    $event = CardEvent::sole();
    expect($event->problem)->toContain('card');

    $this->actingAs($this->owner)->post("/dollars/card-events/{$event->id}/place", ['card_id' => $this->main->id])->assertRedirect();
    expect($event->fresh()->status)->toBe('booked')->and((new Books)->summary()['card'])->toBe(49100);

    nsave('Payment of $4.00 to Notion');
    $other = CardEvent::where('id', '!=', $event->id)->sole();
    $this->actingAs($this->owner)->post("/dollars/card-events/{$other->id}/dismiss")->assertRedirect();
    expect($other->fresh()->status)->toBe('ignored')->and((new Books)->summary()['card'])->toBe(49100);
});

it('gives a refund back with its cost, and leaves money added to the main card for the Owner', function () {
    nsave('You spent $20.00 at Canva with card ending 6903');
    nsave('Refund of $20.00 from Canva to card ending 6903')->assertJson(['status' => 'card_booked']);

    $books = new Books;
    expect($books->summary()['card'])->toBe(50000)
        ->and($books->summary()['usd_check']['ok'])->toBeTrue()
        ->and($books->fifo()['consumed'])->toBe(0);

    // New dollars on the main card were bought with taka: the Owner writes that with its rate.
    nsave('$100.00 added to your card ending 6903')->assertJson(['status' => 'card_held']);
    expect((new Books)->summary()['card'])->toBe(50000);
});

it('changes nothing for a decline, an OTP or an offer', function () {
    nsave('Transaction declined: $3.00 at Netflix, card *6903')->assertJson(['status' => 'card_ignored']);
    nsave('Your OTP for $1.00 at ELEPHANTS LAB is 482913')->assertJson(['status' => 'card_ignored']);
    nsave('Get 5% cashback this weekend!')->assertJson(['status' => 'card_ignored']);

    expect((new Books)->summary()['card'])->toBe(50000)->and(UsdEntry::count())->toBe(1);
});
