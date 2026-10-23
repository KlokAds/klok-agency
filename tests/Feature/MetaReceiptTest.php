<?php

use App\Models\Account;
use App\Models\Campaign;
use App\Models\CampaignDay;
use App\Models\Client;
use App\Models\PilotAccount;
use App\Models\UsdEntry;
use App\Models\User;
use App\Services\Books;
use App\Services\CampaignBilling;
use App\Services\MetaReceipts;
use App\Services\PlatformDue;
use App\Support\Access;
use Carbon\CarbonImmutable;
use Database\Seeders\AccessSeeder;
use Database\Seeders\BooksSeeder;

beforeEach(function () {
    $this->seed([AccessSeeder::class, BooksSeeder::class]);
    $this->owner = User::factory()->create();
    $this->owner->assignRole(Access::OWNER);

    $this->account = PilotAccount::create(['pilot_id' => 9, 'name' => 'Anowar Hossain (KA)', 'external_id' => 'act_1234567890', 'platform' => 'meta', 'currency' => 'USD', 'seen_at' => now()]);
    $this->gffs = Client::create(['name' => 'GFFS', 'type' => 'client', 'status' => 'active']);
    $this->sales = Campaign::create(['pilot_campaign_id' => 1, 'pilot_account_id' => $this->account->id, 'name' => 'GFFS 07/23/26 - Sales Campaign', 'status' => 'ACTIVE', 'client_id' => $this->gffs->id, 'bill_from' => '2026-10-19']);
    $this->leads = Campaign::create(['pilot_campaign_id' => 2, 'pilot_account_id' => $this->account->id, 'name' => 'GFFS 07/23/26 - Sales Campaign 2', 'status' => 'ACTIVE', 'client_id' => $this->gffs->id, 'bill_from' => '2026-10-19']);
});

/** A receipt in the shape Meta sends (text of the HTML mail). */
function receiptText(string $amount = '20.00', string $currency = 'USD', string $tx = '7654321098765432-1234567'): string
{
    return <<<TXT
    Receipt for Your Payment
    This is not an invoice.
    Transaction for. Anowar Hossain (KA) (1234567890).
    Amount billed
    \${$amount} {$currency}
    Payment date
    October 15, 2026, 11:47 PM
    Payment method
    Visa · 4242
    Transaction ID. {$tx}
    Campaigns
    GFFS 07/23/26 - Sales Campaign 2
    Oct 10, 2026 - Oct 15, 2026
    \$6.00
    GFFS 07/23/26 - Sales Campaign
    Oct 10, 2026 - Oct 15, 2026
    \$8.00
    Old page likes
    \$6.00
    TXT;
}

it('reads a Meta receipt whatever its layout: amount, day, card, transaction and each campaign', function () {
    $parsed = MetaReceipts::parse("Your Meta ads receipt (Account ID: 1234567890)\n".receiptText(), CarbonImmutable::parse('2026-10-16'), fn () => Campaign::all()->all());

    expect($parsed['amount'])->toBe(2000)
        ->and($parsed['currency'])->toBe('USD')
        ->and($parsed['account_ref'])->toBe('1234567890')
        ->and($parsed['transaction_id'])->toBe('7654321098765432-1234567')
        ->and($parsed['card_last4'])->toBe('4242')
        ->and($parsed['charged_at']->toDateString())->toBe('2026-10-15')
        // "…Campaign 2" is not taken for "…Campaign": longest names match first.
        ->and(array_column($parsed['campaigns'], 'cents', 'name'))->toBe([
            'GFFS 07/23/26 - Sales Campaign 2' => 600,
            'GFFS 07/23/26 - Sales Campaign' => 800,
        ]);
});

it('books a receipt once as a card charge with its campaign split, and keeps the ones it cannot read', function () {
    $receipts = app(MetaReceipts::class);
    $receipt = $receipts->take('<m1@meta>', 'Your Meta ads receipt', receiptText(), CarbonImmutable::parse('2026-10-16'));

    expect($receipt->status)->toBe('booked');
    $entry = UsdEntry::findOrFail($receipt->usd_entry_id);
    expect($entry->category)->toBe('card_charge')
        ->and($entry->amount)->toBe(-2000)
        ->and($entry->entry_date->toDateString())->toBe('2026-10-15')
        ->and($entry->account_id)->toBe(Account::where('code', 'meta')->value('id'))
        // "Old page likes" is not in the books: its $6 pays the oldest spend.
        ->and($entry->allocations)->toBe(["c:{$this->leads->id}" => 600, "c:{$this->sales->id}" => 800])
        ->and((string) $receipt->problem)->toMatch('/\$6\.00/');

    // The same transaction forwarded again, or the same mail read again: nothing new.
    expect($receipts->take('<m2@meta>', 'Fwd: Your Meta ads receipt', receiptText(), CarbonImmutable::parse('2026-10-16'))->status)->toBe('duplicate')
        ->and($receipts->take('<m1@meta>', 'x', 'y', CarbonImmutable::now())->id)->toBe($receipt->id)
        ->and(UsdEntry::where('category', 'card_charge')->count())->toBe(1);

    // A BDT ad account's receipt: kept, quietly, not booked.
    PilotAccount::create(['pilot_id' => 10, 'name' => 'EDGE Education Advertise', 'external_id' => 'act_1170923540578070', 'platform' => 'meta', 'currency' => 'BDT', 'seen_at' => now()]);
    $edge = $receipts->take('<m3@meta>', 'Your Meta ads receipt (Account ID: 1170923540578070)', 'Transaction for. EDGE Education Advertise (1170923540578070). Transaction ID. 28867966246224-2886 Amount billed BDT 2,500.00 Meta', CarbonImmutable::now());
    expect($edge->status)->toBe('ignored');

    // Other mail is left alone.
    expect($receipts->take('<m4@x>', 'Lunch?', 'See you at 1', CarbonImmutable::now())->status)->toBe('ignored');

    expect((new Books)->summary()['equity_ok'])->toBeTrue()
        ->and($this->owner->notifications()->where('data->kind', 'meta_receipt')->count())->toBe(1);

    $this->actingAs($this->owner)->get('/dollars/receipts')->assertOk()
        ->assertInertia(fn ($page) => $page->component('books/MetaReceipts')->where('receipts.total', 2));
});

it('keeps the split honest: what a receipt pays a campaign comes off that campaign', function () {
    CampaignDay::create(['campaign_id' => $this->sales->id, 'day' => '2026-10-19', 'spend' => 800, 'results' => 1, 'clicks' => 1]);
    CampaignDay::create(['campaign_id' => $this->leads->id, 'day' => '2026-10-19', 'spend' => 600, 'results' => 1, 'clicks' => 1]);
    app(CampaignBilling::class)->rebuild([$this->gffs->id]);

    app(MetaReceipts::class)->take('<m1@meta>', 'Your Meta ads receipt', receiptText('14.00'), CarbonImmutable::parse('2026-10-20'));

    $pending = (new PlatformDue(new Books))->pending(Account::where('code', 'meta')->first());
    expect($pending["c:{$this->sales->id}"] ?? 0)->toBe(0)
        ->and($pending["c:{$this->leads->id}"] ?? 0)->toBe(0);
});

it('joins a card charge already written by hand instead of booking it twice', function () {
    $meta = Account::where('code', 'meta')->first();
    $byHand = UsdEntry::create(['entry_date' => '2026-10-15', 'description' => 'FACEBK* S5LHCASSD4 (manual pay)', 'category' => 'card_charge', 'account_id' => $meta->id, 'amount' => -2000, 'status' => 'na']);

    $receipt = app(MetaReceipts::class)->take('<m1@meta>', 'Your Meta ads receipt', receiptText(), CarbonImmutable::parse('2026-10-16'));

    expect($receipt->usd_entry_id)->toBe($byHand->id)
        ->and(UsdEntry::where('category', 'card_charge')->count())->toBe(1)
        ->and($byHand->fresh()->allocations)->toBe(["c:{$this->leads->id}" => 600, "c:{$this->sales->id}" => 800]);
});

it('dates a charge at the end of the billed period, as on a real receipt', function () {
    $real = <<<'TXT'
    This is not an invoice
    Transaction for
    Anowar Hossain (KA) (1234567890)
     Transaction ID
    28843656451987316-29221893927496905
    Payment summary
    Amount billed
    $10.00 USD
     Date range
    Oct 10, 2026, 12:00 AM - Oct 15, 2026, 11:47 PM
    Payment method
    Visa ···· 6903
    Campaign Results Amount
    GFFS 07/23/26 - Sales Campaign 25,938
    Impressions
     $10.00
    Total $10.00
    TXT;

    $parsed = MetaReceipts::parse("Your Meta ads receipt (Account ID: 1234567890)\n".$real, CarbonImmutable::parse('2026-10-16 09:00'), fn () => Campaign::all()->all());

    expect($parsed['charged_at']->format('Y-m-d H:i'))->toBe('2026-10-15 23:47')
        ->and($parsed['amount'])->toBe(1000)
        ->and($parsed['card_last4'])->toBe('6903')
        ->and($parsed['transaction_id'])->toBe('28843656451987316-29221893927496905')
        ->and($parsed['campaigns'])->toBe([['name' => 'GFFS 07/23/26 - Sales Campaign', 'cents' => 1000, 'campaign_id' => $this->sales->id]]);
});
