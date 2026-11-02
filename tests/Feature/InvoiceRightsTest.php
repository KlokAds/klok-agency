<?php

use App\Models\BdtEntry;
use App\Models\Client;
use App\Models\FxBatch;
use App\Models\Invoice;
use App\Models\Setting;
use App\Models\User;
use App\Services\Invoices;
use App\Support\Access;
use Database\Seeders\AccessSeeder;
use Database\Seeders\BooksSeeder;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    $this->seed([AccessSeeder::class, BooksSeeder::class]);
    Notification::fake();
    $this->gffs = Client::create(['name' => 'GFFS', 'type' => 'client', 'status' => 'active']);
    $this->person = User::factory()->create(['client_id' => $this->gffs->id]);
    $this->owner = User::factory()->create();
    $this->owner->assignRole(Access::OWNER);
    // Team: may change and delete clients' things, but is not an Owner.
    $this->team = User::factory()->create();
    $this->team->assignRole(Role::findOrCreate('Manager', 'web')->syncPermissions(['clients.view', 'clients.edit', 'clients.delete', 'bdt.create']));
    FxBatch::create(['number' => 1, 'purchased_on' => now()->subDays(5)->toDateString(), 'description' => 'USD bought', 'usd' => 10000, 'bdt' => 1400000]);
    Setting::put('commission_percent', 10);
});

/** One the team made and sent: $10 at ৳154. */
function teamInvoice(User $by, Client $client): Invoice
{
    test()->actingAs($by);
    $invoices = app(Invoices::class);
    $invoice = $invoices->save(['client_id' => $client->id, 'issue_date' => now()->toDateString(), 'due_date' => null, 'notes' => null, 'items' => [[
        'kind' => 'ad_budget', 'description' => 'Ads', 'usd' => 1000, 'rate' => 154.0, 'amount' => null,
        'campaign_id' => null, 'period_from' => null, 'period_to' => null, 'results' => null, 'clicks' => null,
    ]]]);
    $invoices->markSent($invoice);

    return $invoice;
}

it('lets the client change and cancel a top-up they made, but never one we made', function () {
    $this->actingAs($this->person)->post('/portal/top-up', ['amount' => 3080])->assertRedirect();
    $mine = Invoice::where('client_id', $this->gffs->id)->sole();
    $ours = teamInvoice($this->team, $this->gffs);

    $this->actingAs($this->person)->get('/portal/invoices')->assertInertia(fn ($page) => $page
        ->where('invoices.0.mine', false)->where('invoices.1.mine', true)->where('invoices.1.change', 'amount'));

    // Their own: ৳1,540 is $10 at ৳154, the same invoice and number.
    $this->actingAs($this->person)->post("/portal/invoices/{$mine->uid}/amount", ['amount' => 1540])->assertRedirect()->assertSessionHasNoErrors();
    expect($mine->fresh())->usd->toBe(1000)->total->toBe(154000)->number->toBe($mine->number);

    // Ours: not theirs to change or cancel.
    $this->actingAs($this->person)->post("/portal/invoices/{$ours->uid}/amount", ['amount' => 1540])->assertNotFound();
    $this->actingAs($this->person)->post("/portal/invoices/{$ours->uid}/void")->assertNotFound();

    $this->actingAs($this->person)->post("/portal/invoices/{$mine->uid}/void")->assertRedirect();
    expect($mine->fresh()->status)->toBe('void')->and($ours->fresh()->status)->toBe('sent');
});

it('lets the team delete what it made, but only an Owner one the client made or one with money on it', function () {
    $this->actingAs($this->person)->post('/portal/top-up', ['amount' => 3080])->assertRedirect();
    $clients = Invoice::where('client_id', $this->gffs->id)->sole();
    $ours = teamInvoice($this->team, $this->gffs);
    $paid = teamInvoice($this->team, $this->gffs);
    $this->actingAs($this->owner)->post("/invoices/{$paid->uid}/pay", [
        'method' => 'bkash', 'wallet' => 'bkash', 'paid_on' => now()->toDateString(), 'amount' => '1540', 'reference' => 'RIGHTS0001',
    ])->assertSessionHasNoErrors();
    $money = BdtEntry::where('notes', 'like', "%{$paid->number}%")->pluck('id');
    expect($paid->fresh()->status)->toBe('paid')->and($money)->not->toBeEmpty();

    // The team: its own unpaid one yes; the client's and the paid one no.
    $this->actingAs($this->team)->delete("/invoices/{$clients->uid}")->assertSessionHasErrors('invoice');
    $this->actingAs($this->team)->delete("/invoices/{$paid->uid}")->assertSessionHasErrors('invoice');
    $this->actingAs($this->team)->delete("/invoices/{$ours->uid}")->assertRedirect('/invoices');
    expect(Invoice::find($ours->id))->toBeNull();

    // A client can never delete, not even their own.
    $this->actingAs($this->person)->delete("/invoices/{$clients->uid}")->assertForbidden();

    // The Owner: both, and the paid one's money goes to Trash with it.
    $this->actingAs($this->owner)->delete("/invoices/{$clients->uid}")->assertRedirect('/invoices');
    $this->actingAs($this->owner)->delete("/invoices/{$paid->uid}")->assertRedirect('/invoices');
    expect(BdtEntry::whereIn('id', $money)->count())->toBe(0)
        ->and(BdtEntry::onlyTrashed()->whereIn('id', $money)->count())->toBe($money->count());

    // Back from Trash: the invoice and its money together.
    $this->actingAs($this->owner)->post("/trash/invoice/{$paid->id}")->assertRedirect();
    expect(Invoice::find($paid->id)?->status)->toBe('paid')
        ->and(BdtEntry::whereIn('id', $money)->count())->toBe($money->count());

    // A new invoice never takes a deleted one's number.
    expect(teamInvoice($this->team, $this->gffs)->number)->not->toBeIn([$ours->number, $clients->number, $paid->number]);
});
