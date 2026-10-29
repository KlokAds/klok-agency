<?php

use App\Models\Campaign;
use App\Models\Client;
use App\Models\MetaReceipt;
use App\Models\PilotAccount;
use App\Models\UsdEntry;
use App\Models\User;
use App\Services\MetaReceipts;
use App\Support\Access;
use Carbon\CarbonImmutable;
use Database\Seeders\AccessSeeder;
use Database\Seeders\BooksSeeder;

beforeEach(function () {
    $this->seed([AccessSeeder::class, BooksSeeder::class]);
    User::factory()->create()->assignRole(Access::OWNER);

    $account = PilotAccount::create(['pilot_id' => 9, 'name' => 'Anowar Hossain (KA)', 'external_id' => 'act_1234567890', 'platform' => 'meta', 'currency' => 'USD', 'seen_at' => now()]);
    $client = Client::create(['name' => 'GFFS', 'type' => 'client', 'status' => 'active']);
    Campaign::create(['pilot_campaign_id' => 1, 'pilot_account_id' => $account->id, 'name' => 'GFFS - Sales Campaign', 'status' => 'ACTIVE', 'client_id' => $client->id, 'bill_from' => '2026-10-19']);

    $this->receipt = fn (string $from, string $tx = '7654321098765432-1234567') => MetaReceipts::SENDER_PREFIX.$from."\n".<<<TXT
    Receipt for Your Payment
    Transaction for. Anowar Hossain (KA) (1234567890).
    Amount billed
    \$500.00 USD
    Payment date
    October 15, 2026, 11:47 PM
    Payment method
    Visa · 4242
    Transaction ID. {$tx}
    Campaigns
    GFFS - Sales Campaign
    \$500.00
    TXT;
});

it('books a receipt that Meta sent', function (string $from) {
    $receipt = app(MetaReceipts::class)->take('<m1@meta>', 'Your Meta ads receipt', ($this->receipt)($from), CarbonImmutable::parse('2026-10-16'));

    expect($receipt->status)->toBe('booked')
        ->and(UsdEntry::where('category', 'card_charge')->count())->toBe(1);
})->with(['advertise-noreply@support.facebook.com', 'noreply@facebookmail.com']);

it('never books a receipt that someone else mailed, even when a person reads it again', function (string $from) {
    $receipts = app(MetaReceipts::class);
    $receipt = $receipts->take('<fake@evil>', 'Your Meta ads receipt', ($this->receipt)($from), CarbonImmutable::parse('2026-10-16'));

    expect($receipt->status)->toBe('unread')
        ->and($receipt->problem)->toContain($from !== '' ? $from : '—')
        ->and($receipt->body)->toStartWith(MetaReceipts::SENDER_PREFIX)
        ->and(UsdEntry::count())->toBe(0);

    $again = $receipts->retry($receipt);
    expect($again->status)->toBe('unread')
        ->and(UsdEntry::count())->toBe(0)
        ->and(MetaReceipt::count())->toBe(1);
})->with([
    'another sender' => 'billing@evil.example',
    'a look-alike domain' => 'noreply@facebookmail.com.evil.example',
    'a forged Meta sender' => 'noreply@facebookmail.com (DMARC fail)',
    'no sender' => '',
]);

it('tells Meta senders from others', function () {
    expect(MetaReceipts::fromMeta('advertise-noreply@support.facebook.com'))->toBeTrue()
        ->and(MetaReceipts::fromMeta('x@meta.com'))->toBeTrue()
        ->and(MetaReceipts::fromMeta('x@notfacebook.com'))->toBeFalse()
        ->and(MetaReceipts::fromMeta('x@facebook.com.evil.example'))->toBeFalse();
});

it('leaves a KlokOne company\'s mail on the shared mailbox alone', function () {
    // Gmail's forward to a company's meta-… address, brought in by the catch-all.
    expect(MetaReceipts::forKlokOne("To: owner@gmail.com\r\nX-Forwarded-To: meta-rupkotha-ads-k3x9q2za@klokads.com\r\n"))->toBeTrue()
        ->and(MetaReceipts::forKlokOne("Received: from mx1\r\n\tfor <meta-k3x9q2za@klokads.com>; Thu\r\n"))->toBeTrue()
        // Ours: the mailbox's own address.
        ->and(MetaReceipts::forKlokOne("Delivered-To: receipts@klokads.com\r\nTo: <receipts@klokads.com>\r\n"))->toBeFalse()
        ->and(MetaReceipts::forKlokOne("To: <metadata@klokads.com>\r\n"))->toBeFalse();
});
