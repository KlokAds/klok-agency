<?php

use App\Models\Client;
use App\Models\FxBatch;
use App\Models\Invoice;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\AgencyAlert;
use App\Services\Invoices;
use App\Support\Access;
use Database\Seeders\AccessSeeder;
use Database\Seeders\BooksSeeder;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    $this->seed([AccessSeeder::class, BooksSeeder::class]);
    Notification::fake();
    $this->gffs = Client::create(['name' => 'GFFS', 'type' => 'client', 'status' => 'active']);
    $this->person = User::factory()->create(['client_id' => $this->gffs->id]);
    $this->owner = User::factory()->create();
    $this->owner->assignRole(Access::OWNER);
    // $100 bought at ৳140 and 10% on top: the client pays ৳154 a dollar.
    FxBatch::create(['number' => 1, 'purchased_on' => now()->subDays(5)->toDateString(), 'description' => 'USD bought', 'usd' => 10000, 'bdt' => 1400000]);
    Setting::put('commission_percent', 10);
});

it('lets a client add credit by amount: an invoice at their rate, sent, replacing an unpaid one', function () {
    $rate = Invoices::clientRate();
    expect($rate)->toBe(154.0);

    $this->actingAs($this->person)->post('/portal/top-up', ['amount' => 3080])->assertRedirect();
    $first = Invoice::where('client_id', $this->gffs->id)->sole();
    expect($first->status)->toBe('sent')
        ->and($first->usd)->toBe(2000)
        ->and($first->total)->toBe(308000)
        ->and($first->notes)->toBe('Top-up');
    Notification::assertSentTo($this->person, AgencyAlert::class, fn (AgencyAlert $n) => $n->kind === 'client_invoice');
    Notification::assertSentTo($this->owner, AgencyAlert::class, fn (AgencyAlert $n) => $n->kind === 'topup_invoiced');

    // A second ask replaces the unpaid first.
    $this->actingAs($this->person)->post('/portal/top-up', ['amount' => 1540])->assertRedirect();
    expect($first->fresh()->status)->toBe('void')
        ->and(Invoice::where('client_id', $this->gffs->id)->where('status', 'sent')->sole()->usd)->toBe(1000);
});

it('refuses too little and shows the rate on the portal', function () {
    $this->actingAs($this->person)->post('/portal/top-up', ['amount' => 100])->assertSessionHasErrors('amount');
    $this->actingAs($this->person)->get('/portal')->assertOk()
        ->assertInertia(fn ($page) => $page->where('credit.rate', 154)->where('credit.topup_min', 50000));
});
