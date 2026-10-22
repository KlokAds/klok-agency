<?php

use App\Models\BdtEntry;
use App\Models\Client;
use App\Models\ExpenseHead;
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

it('shows only client payments on the Payment page, filtered by client', function () {
    $gffs = Client::where('name', 'GFFS')->first();
    $all = BdtEntry::whereIn('category', BdtEntry::REVENUE)->count();

    $this->actingAs($this->owner)->get('/payments')->assertOk()
        ->assertInertia(fn ($page) => $page->component('books/MoneyBook')->where('mode', 'in')
            ->where('totals.count', $all)
            ->where('rows.data', fn ($rows) => collect($rows)->every(fn ($r) => in_array($r['category'], BdtEntry::REVENUE, true))));

    $this->actingAs($this->owner)->get('/payments?client_id='.$gffs->id)
        ->assertInertia(fn ($page) => $page->where('rows.data', fn ($rows) => collect($rows)->every(fn ($r) => $r['client_id'] === $gffs->id)));
});

it('shows only expenses on the expense page, with the heads kept there', function () {
    $this->actingAs($this->owner)->get('/expenses')->assertOk()
        ->assertInertia(fn ($page) => $page->where('mode', 'out')
            ->where('rows.data', fn ($rows) => collect($rows)->every(fn ($r) => in_array($r['category'], BdtEntry::EXPENSE, true)))
            ->has('heads'));

    $this->actingAs($this->owner)->post('/expenses/heads', ['name' => 'Office rent'])->assertRedirect();
    $head = ExpenseHead::where('name', 'Office rent')->sole();
    $this->actingAs($this->owner)->put("/expenses/heads/{$head->id}", ['name' => 'Office rent', 'is_active' => false])->assertRedirect();
    expect($head->fresh()->is_active)->toBeFalse();

    // Settings no longer carries the heads.
    $this->actingAs($this->owner)->get('/admin/settings')->assertInertia(fn ($page) => $page->missing('heads'));
});

it('searches clients and entries for people allowed to see them', function () {
    $this->actingAs($this->owner)->getJson('/search?q=GFFS')->assertOk()
        ->assertJsonPath('groups.0.key', 'clients')
        ->assertJsonPath('groups.0.items.0.title', 'GFFS');

    $this->actingAs($this->owner)->getJson('/search?q=G')->assertJsonPath('groups', []);

    // A viewer without the BDT book does not get its rows.
    $viewer = User::factory()->create();
    $viewer->assignRole('Viewer');
    $viewer->revokePermissionTo('bdt.view');
    $viewer->roles->first()->revokePermissionTo('bdt.view');

    $keys = collect($this->actingAs($viewer)->getJson('/search?q=reimbursement')->json('groups'))->pluck('key');
    expect($keys)->not->toContain('bdt');
});

it('shows the card on the dollar page with the same balance as everywhere else', function () {
    $card = app(Books::class)->summary()['card'];

    $this->actingAs($this->owner)->get('/dollars')->assertOk()
        ->assertInertia(fn ($page) => $page->where('summary.card', $card)
            ->where('accounts', fn ($accounts) => collect($accounts)->firstWhere('kind', 'card')['balance'] === $card));
});
