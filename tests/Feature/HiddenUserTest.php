<?php

use App\Models\Client;
use App\Models\User;
use App\Support\Access;
use Database\Seeders\AccessSeeder;
use Database\Seeders\BooksSeeder;

beforeEach(function () {
    $this->seed([AccessSeeder::class, BooksSeeder::class]);

    $this->owner = User::factory()->create(['name' => 'Klok Ads']);
    $this->owner->assignRole(Access::OWNER);

    $this->hidden = User::factory()->create(['name' => 'Super Admin']);
    $this->hidden->forceFill(['is_hidden' => true])->save();
    $this->hidden->assignRole(Access::OWNER);
});

it('keeps the hidden super admin out of the user list and every picker', function () {
    $this->actingAs($this->owner)->get('/admin/users')->assertOk()
        ->assertInertia(fn ($page) => $page->has('users', 1)->where('users.0.name', 'Klok Ads'));

    $client = Client::create(['name' => 'GFFS', 'type' => 'client', 'status' => 'active']);
    $this->actingAs($this->owner)->get('/clients/'.$client->id)
        ->assertInertia(fn ($page) => $page->where('options.users', fn ($users) => collect($users)->pluck('label')->all() === ['Klok Ads']));
    $this->actingAs($this->owner)->get('/tasks')
        ->assertInertia(fn ($page) => $page->where('options.users', fn ($users) => collect($users)->pluck('label')->all() === ['Klok Ads']));
});

it('lets nobody but a hidden account change or remove it', function () {
    $this->actingAs($this->owner)->put('/admin/users/'.$this->hidden->id, [
        'name' => 'X', 'email' => $this->hidden->email, 'role' => 'Viewer', 'is_active' => false,
    ])->assertNotFound();
    $this->actingAs($this->owner)->delete('/admin/users/'.$this->hidden->id)->assertNotFound();
    $this->actingAs($this->owner)->post('/admin/users/'.$this->hidden->id.'/reset-2fa')->assertNotFound();

    expect($this->hidden->refresh()->is_active)->toBeTrue()->and($this->hidden->hasRole(Access::OWNER))->toBeTrue();

    // The hidden account sees everyone, itself included, and has full access.
    $this->actingAs($this->hidden)->get('/admin/users')->assertInertia(fn ($page) => $page->has('users', 2));
    $this->actingAs($this->hidden)->get('/admin/settings')->assertOk();
});

it('does not let the hidden account stand in as the last Owner', function () {
    $staff = User::factory()->create();
    $staff->assignRole(Access::OWNER);

    $this->actingAs($staff)->put('/admin/users/'.$this->owner->id, [
        'name' => 'Klok Ads', 'email' => $this->owner->email, 'role' => 'Viewer', 'is_active' => true,
    ])->assertRedirect();
    expect($this->owner->refresh()->hasRole('Viewer'))->toBeTrue();

    // Now $staff is the only Owner people can see: the hidden one does not count.
    $this->actingAs($this->hidden)->put('/admin/users/'.$staff->id, [
        'name' => $staff->name, 'email' => $staff->email, 'role' => 'Viewer', 'is_active' => true,
    ])->assertSessionHasErrors('role');
});
