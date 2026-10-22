<?php

use App\Models\Account;
use App\Models\BdtEntry;
use App\Models\Client;
use App\Models\FxBatch;
use App\Models\Setting;
use App\Models\UsdEntry;
use App\Models\User;
use App\Services\Books;
use App\Support\Access;
use Database\Seeders\AccessSeeder;
use Database\Seeders\BooksSeeder;

beforeEach(function () {
    $this->seed([AccessSeeder::class, BooksSeeder::class]);
    $this->artisan('books:import-sheet')->assertSuccessful();

    $this->owner = User::factory()->create();
    $this->owner->assignRole(Access::OWNER);
});

function userWithRole(string $role): User
{
    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

// The import itself fails unless the sheet's totals match before anything is added.
it('imports the sheet and the 20 Oct GFFS payment, with no fee on payments', function () {
    $summary = app(Books::class)->summary();
    $fees = -BdtEntry::whereNotNull('fee_for_id')->sum('amount');

    expect($summary['card'])->toBe(12066)
        ->and($summary['business_usd'])->toBe(9366)
        ->and($summary['platform_due'])->toBe(2700)
        ->and($fees)->toBe(0)
        ->and($summary['bdt'])->toBe(1963300 + 210000)
        ->and($summary['receivable_usd'])->toBe(410)
        ->and($summary['usd_check']['ok'])->toBeTrue()
        ->and($summary['equity_ok'])->toBeTrue();
});

it('can still take a fee off an MFS payment when a rate is set, and nothing off bank or cash', function () {
    Setting::put('payment_fee_percent', '1');
    $gffs = Client::where('name', 'GFFS')->first();

    $this->actingAs($this->owner)->post('/quick/client-payment', [
        'entry_date' => now()->toDateString(), 'client_id' => $gffs->id, 'category' => 'client_payment', 'pay_via' => 'mfs', 'wallet' => 'bkash', 'amount' => '5000',
    ])->assertRedirect();

    $payment = BdtEntry::where('client_id', $gffs->id)->latest('id')->first();
    $fee = $payment->fee;

    expect($payment->pay_via)->toBe('mfs')
        ->and($fee->amount)->toBe(-5000)
        ->and($fee->description)->toStartWith('MFS fee 1%')
        ->and($fee->head->name)->toBe('Payment fee');

    $this->actingAs($this->owner)->delete("/bdt/{$fee->id}")->assertSessionHasErrors('entry');

    // Marked as a bank transfer instead: the fee goes.
    $this->actingAs($this->owner)->put("/bdt/{$payment->id}", [
        'entry_date' => now()->toDateString(), 'description' => $payment->description, 'category' => 'client_payment',
        'client_id' => $gffs->id, 'pay_via' => 'bank', 'amount' => '5000',
    ])->assertRedirect();
    expect($fee->fresh()->trashed())->toBeTrue();

    foreach (['bank', 'cash'] as $via) {
        $this->actingAs($this->owner)->post('/quick/client-payment', [
            'entry_date' => now()->toDateString(), 'client_id' => $gffs->id, 'category' => 'client_payment', 'pay_via' => $via, 'wallet' => 'bank', 'amount' => '3000',
        ])->assertRedirect();
        expect(BdtEntry::where('client_id', $gffs->id)->latest('id')->first()->fee)->toBeNull();
    }

    $this->actingAs($this->owner)->delete("/bdt/{$payment->id}")->assertRedirect();
});

it('opens every page for the owner', function (string $url) {
    $this->actingAs($this->owner)->get($url)->assertOk();
})->with([
    '/dashboard', '/usd', '/bdt', '/fx', '/clients', '/platforms', '/payroll', '/reports',
    '/reports?preset=this_month', '/reconcile', '/trash', '/activity', '/admin/users', '/admin/roles',
    '/admin/settings', '/settings/profile',
]);

it('opens a client statement', function () {
    $client = Client::where('name', 'GFFS')->first();

    $this->actingAs($this->owner)->get("/clients/{$client->id}")->assertOk();
});

it('has no public sign-up', function () {
    $this->get('/register')->assertNotFound();
});

it('keeps a viewer read-only on the server', function () {
    $viewer = userWithRole('Viewer');

    $this->actingAs($viewer)->get('/usd')->assertOk();
    $this->actingAs($viewer)->get('/admin/users')->assertForbidden();
    $this->actingAs($viewer)->post('/quick/expense', [])->assertForbidden();
    $this->actingAs($viewer)->delete('/usd/'.UsdEntry::first()->id)->assertForbidden();
});

it('signs out a deactivated user', function () {
    $user = userWithRole('Accountant');
    $user->update(['is_active' => false]);

    $this->actingAs($user)->get('/dashboard')->assertRedirect('/login');
    $this->assertGuest();
});

it('makes a new user change the password first', function () {
    $user = userWithRole('Accountant');
    $user->update(['must_change_password' => true]);

    $this->actingAs($user)->get('/dashboard')->assertRedirect('/settings/security');
});

it('posts buying USD to both books and a new batch', function () {
    $this->actingAs($this->owner)->post('/quick/buy-usd', [
        'entry_date' => now()->toDateString(),
        'usd' => '100',
        'bdt' => '12,800',
        'wallet' => 'bank',
    ])->assertRedirect();

    $batch = FxBatch::orderByDesc('number')->first();

    expect($batch->number)->toBe(4)
        ->and($batch->usd)->toBe(10000)
        ->and(UsdEntry::where('fx_batch_id', $batch->id)->value('amount'))->toBe(10000)
        ->and(BdtEntry::where('fx_batch_id', $batch->id)->value('amount'))->toBe(-1280000);
});

it('settles the chosen pending spend when a client pays', function () {
    $samir = Client::where('name', 'Samir')->first();
    $pending = UsdEntry::where('client_id', $samir->id)->where('status', 'pending')->first();

    $this->actingAs($this->owner)->post('/quick/client-payment', [
        'entry_date' => now()->toDateString(),
        'client_id' => $samir->id,
        'category' => 'client_payment',
        'pay_via' => 'mfs',
        'wallet' => 'bkash',
        'amount' => '615',
        'settle_ids' => [$pending->id],
    ])->assertRedirect();

    expect($pending->fresh()->status)->toBe('settled')
        ->and(app(Books::class)->summary()['receivable_usd'])->toBe(0);
});

it('moves card balance only on a card charge', function () {
    $meta = Account::where('code', 'meta')->first();
    $gffs = Client::where('name', 'GFFS')->first();

    $this->actingAs($this->owner)->post('/quick/ad-spend', [
        'entry_date' => now()->toDateString(), 'client_id' => $gffs->id, 'account_id' => $meta->id,
        'amount' => '14', 'status' => 'pending',
    ]);

    expect(app(Books::class)->summary()['card'])->toBe(12066);

    // Meta was owed $8 + $14 (20 Oct) + $14 (above), Google $5.
    $this->actingAs($this->owner)->post('/quick/card-charge', [
        'entry_date' => now()->toDateString(), 'account_id' => $meta->id, 'amount' => '36',
    ]);
    $google = Account::where('code', 'google')->first();
    $this->actingAs($this->owner)->post('/quick/card-charge', [
        'entry_date' => now()->toDateString(), 'account_id' => $google->id, 'amount' => '5',
    ]);

    $summary = app(Books::class)->summary();

    expect($summary['card'])->toBe(12066 - 4100)
        ->and($summary['platform_due'])->toBe(0)
        ->and($summary['usd_check']['ok'])->toBeTrue();
});

it('refuses changes inside a locked period', function () {
    Setting::put('lock_date', now()->subDay()->toDateString());

    $this->actingAs($this->owner)->post('/bdt', [
        'entry_date' => now()->subDays(3)->toDateString(),
        'description' => 'Back-dated',
        'category' => 'expense',
        'amount' => '100',
    ])->assertSessionHasErrors('entry_date');
});

it('soft deletes and restores a transfer as a pair', function () {
    $row = UsdEntry::whereNotNull('pair_key')->first();

    $this->actingAs($this->owner)->delete("/usd/{$row->id}")->assertRedirect();
    expect(UsdEntry::where('pair_key', $row->pair_key)->count())->toBe(0);

    $this->actingAs($this->owner)->post("/trash/usd/{$row->id}")->assertRedirect();
    expect(UsdEntry::where('pair_key', $row->pair_key)->count())->toBe(2);
});

it('sends security headers', function () {
    $this->actingAs($this->owner)->get('/dashboard')
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Cache-Control', 'no-store, private');
});
