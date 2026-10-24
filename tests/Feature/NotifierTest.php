<?php

use App\Models\ExpenseHead;
use App\Models\User;
use App\Notifications\AgencyAlert;
use App\Services\ActivityAlerts;
use App\Services\Notifier;
use App\Support\Access;
use Database\Seeders\AccessSeeder;
use Database\Seeders\BooksSeeder;

beforeEach(function () {
    $this->seed([AccessSeeder::class, BooksSeeder::class]);
    $this->owner = User::factory()->create(['name' => 'Mujaid']);
    $this->owner->assignRole(Access::OWNER);
    $this->anowar = User::factory()->create(['name' => 'Anowar']);
    $this->anowar->assignRole('Accountant');
    $this->viewer = User::factory()->create(['name' => 'Mithu']);
    $this->viewer->assignRole('Viewer');
});

function money(): AgencyAlert
{
    return new AgencyAlert('invoice_paid', 'Invoice paid', '{client}', ['client' => 'GFFS'], ['url' => '/invoices']);
}

it('sends the work to whoever has the permission for it, not to the Owner', function () {
    app(Notifier::class)->send(null, money());

    expect($this->anowar->notifications()->count())->toBe(1)
        ->and($this->owner->notifications()->count())->toBe(0)
        ->and($this->viewer->notifications()->count())->toBe(0);
});

it('lets the Owner switch a group on, and gives it to them when nobody else would', function () {
    $this->owner->forceFill(['notify_prefs' => ['money' => ['on' => true, 'push' => true, 'email' => false]]])->save();
    app(Notifier::class)->send(null, money());
    expect($this->owner->notifications()->count())->toBe(1);

    // Anowar switched money off and nobody else keeps the money book: the Owner still hears of it.
    $this->owner->forceFill(['notify_prefs' => null])->save();
    $this->anowar->delete();
    app(Notifier::class)->send(null, money());
    expect($this->owner->notifications()->count())->toBe(2);
});

it('never tells someone about their own work, and an important one always reaches the Owner', function () {
    $alert = new AgencyAlert('did_delete', '{who} deleted', null, ['who' => 'Anowar'], ['url' => '/trash', 'important' => true]);
    app(Notifier::class)->send(null, $alert, $this->anowar->id);

    expect($this->anowar->notifications()->count())->toBe(0)
        ->and($this->owner->notifications()->count())->toBe(1);
});

it('tells the Owner when a campaign is about to stop, also with ads switched off', function () {
    app(Notifier::class)->send($this->anowar->id, new AgencyAlert('renewal_open', 'Campaign ending', '{client}', ['client' => 'GFFS']));

    expect($this->anowar->notifications()->count())->toBe(1)
        ->and($this->owner->notifications()->count())->toBe(1);
});

it('tells the Owner, quietly, what the accountant did', function () {
    ActivityAlerts::reset();
    $this->actingAs($this->anowar)->post('/quick/expense', [
        'entry_date' => now()->toDateString(), 'description' => 'Office tea', 'expense_head_id' => ExpenseHead::first()->id,
        'wallet' => 'bank', 'amount' => '250',
    ])->assertRedirect();

    $note = $this->owner->notifications()->first();
    expect($note?->data['kind'])->toBe('did_money_out')
        ->and($note->data['params']['who'])->toBe('Anowar')
        ->and($this->anowar->notifications()->count())->toBe(0)
        // Quiet: only the bell (no phone by default) for this group.
        ->and(Notifier::prefs($this->owner, 'activity'))->toMatchArray(['on' => true, 'push' => false])
        ->and((new AgencyAlert('did_money_out', 'x'))->via($this->owner))->toBe(['database']);

    // A large expense rings: it is marked important.
    ActivityAlerts::reset();
    $this->actingAs($this->anowar)->post('/quick/expense', [
        'entry_date' => now()->toDateString(), 'description' => 'Laptop', 'expense_head_id' => ExpenseHead::first()->id,
        'wallet' => 'bank', 'amount' => '60000',
    ])->assertRedirect();
    $big = $this->owner->notifications()->get()->first(fn ($n) => ($n->data['params']['what'] ?? null) === 'Laptop');
    expect($big->data['important'] ?? false)->toBeTrue()
        ->and($big->data['title'])->toBe('{who} বড় খরচ লিখেছেন');
});

it('lets each person choose the phone and email per group in their profile', function () {
    $this->actingAs($this->anowar)->put('/settings/notifications', ['groups' => [
        ['key' => 'money', 'on' => true, 'push' => false, 'email' => true],
    ]])->assertRedirect();

    expect(Notifier::prefs($this->anowar->fresh(), 'money'))->toBe(['on' => true, 'push' => false, 'email' => true]);
    $this->actingAs($this->anowar)->get('/settings/profile')->assertInertia(fn ($page) => $page->has('notify.groups', count(Notifier::GROUPS)));
});
