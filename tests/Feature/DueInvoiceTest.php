<?php

use App\Models\Account;
use App\Models\Client;
use App\Models\FxBatch;
use App\Models\Invoice;
use App\Models\Setting;
use App\Models\UsdEntry;
use App\Models\User;
use App\Services\Invoices;
use App\Support\Access;
use Database\Seeders\AccessSeeder;
use Database\Seeders\BooksSeeder;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    $this->seed([AccessSeeder::class, BooksSeeder::class]);
    Notification::fake();
    FxBatch::create(['number' => 1, 'purchased_on' => now()->subDays(5)->toDateString(), 'description' => 'USD bought', 'usd' => 10000, 'bdt' => 1400000]);
    Setting::put('commission_percent', 10);
    $this->owner = User::factory()->create();
    $this->owner->assignRole(Access::OWNER);
    $this->samir = Client::create(['name' => 'Samir', 'type' => 'client', 'status' => 'active']);
    $this->person = User::factory()->create(['client_id' => $this->samir->id]);
    // $4.10 of ads run for Samir, nothing paid: owed.
    UsdEntry::create(['entry_date' => now()->subDay()->toDateString(), 'description' => 'Samir - ad spend', 'category' => 'client_spend', 'status' => 'pending', 'client_id' => $this->samir->id, 'amount' => -410, 'account_id' => Account::where('code', 'meta')->value('id')]);
});

it('numbers invoices per client and makes the owed spend an invoice in one click', function () {
    $this->actingAs($this->owner)->post("/clients/{$this->samir->id}/invoice-due")->assertRedirect();
    $invoice = Invoice::where('client_id', $this->samir->id)->sole();

    expect($invoice->number)->toBe('KA-SAMIR-01')
        ->and($invoice->status)->toBe('sent')
        ->and($invoice->usd)->toBe(410)
        ->and($invoice->total)->toBe((int) round(410 * 154))
        ->and($invoice->notes)->toBe(Invoices::DUE_NOTE);

    // Again: the unpaid one is replaced, the number keeps counting for this client.
    $this->actingAs($this->owner)->post("/clients/{$this->samir->id}/invoice-due")->assertRedirect();
    expect($invoice->fresh()->status)->toBe('void')
        ->and(Invoice::where('client_id', $this->samir->id)->where('status', 'sent')->sole()->number)->toBe('KA-SAMIR-02');
});

it('lets the client pay what they owe from the portal', function () {
    $this->actingAs($this->person)->get('/portal')->assertOk()
        ->assertInertia(fn ($page) => $page->where('credit.owed', 410));

    $this->actingAs($this->person)->post('/portal/pay-due')->assertRedirect();
    $invoice = Invoice::where('client_id', $this->samir->id)->sole();
    expect($invoice->usd)->toBe(410)->and($invoice->status)->toBe('sent');

    // A second tap reuses the open invoice instead of making another.
    $this->actingAs($this->person)->post('/portal/pay-due')->assertRedirect();
    expect(Invoice::where('client_id', $this->samir->id)->count())->toBe(1);
});
