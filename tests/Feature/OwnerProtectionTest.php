<?php

use App\Http\Controllers\Admin\SessionController;
use App\Models\User;
use App\Support\Access;
use Database\Seeders\AccessSeeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    $this->seed(AccessSeeder::class);

    $this->owner = User::factory()->create(['name' => 'Owner']);
    $this->owner->assignRole(Access::OWNER);
    $this->owner->forceFill(['two_factor_secret' => 'secret', 'two_factor_confirmed_at' => now()])->save();

    $this->second = User::factory()->create(['name' => 'Second Owner']);
    $this->second->assignRole(Access::OWNER);

    // A team member trusted with the user pages, but not an Owner.
    Role::findOrCreate('Team Lead', 'web')->syncPermissions(['users.view', 'users.edit', 'users.delete', 'roles.view', 'roles.edit', 'roles.delete']);
    $this->lead = User::factory()->create(['name' => 'Lead']);
    $this->lead->assignRole('Team Lead');

    $this->staff = User::factory()->create(['name' => 'Staff', 'remember_token' => 'old-token']);
    $this->staff->assignRole('Accountant');
    $this->staff->forceFill(['two_factor_secret' => 'secret', 'two_factor_confirmed_at' => now()])->save();
});

function sessionRow(User $user, string $id): void
{
    DB::table('sessions')->insert([
        'id' => $id, 'user_id' => $user->id, 'ip_address' => '103.1.1.1',
        'user_agent' => 'Mozilla/5.0 Chrome/120', 'payload' => '', 'last_activity' => now()->getTimestamp(),
    ]);
}

it('does not let a non-Owner reset an Owner\'s 2FA or delete an Owner', function () {
    $this->actingAs($this->lead)->post('/admin/users/'.$this->owner->id.'/reset-2fa')->assertSessionHasErrors('user');
    expect($this->owner->fresh()->two_factor_confirmed_at)->not->toBeNull();

    $this->actingAs($this->lead)->delete('/admin/users/'.$this->owner->id)->assertSessionHasErrors('user');
    expect(User::find($this->owner->id))->not->toBeNull();
});

it('still lets a non-Owner reset 2FA for and remove other team members', function () {
    $this->actingAs($this->lead)->post('/admin/users/'.$this->staff->id.'/reset-2fa')->assertSessionHasNoErrors();
    expect($this->staff->fresh()->two_factor_confirmed_at)->toBeNull();

    $this->actingAs($this->lead)->delete('/admin/users/'.$this->staff->id)->assertSessionHasNoErrors();
    expect(User::find($this->staff->id))->toBeNull();
});

it('lets an Owner reset another Owner\'s 2FA', function () {
    $this->actingAs($this->second)->post('/admin/users/'.$this->owner->id.'/reset-2fa')->assertSessionHasNoErrors();
    expect($this->owner->fresh()->two_factor_confirmed_at)->toBeNull();
});

it('never sends a real session id to the page', function () {
    sessionRow($this->staff, 'staff-real-session-id');

    $this->actingAs($this->owner)->get('/admin/sessions')->assertOk()
        ->assertDontSee('staff-real-session-id')
        ->assertInertia(fn ($page) => $page
            ->where('sessions.0.id', SessionController::key('staff-real-session-id'))
            ->where('sessions.0.owner', false));
});

it('ends a session by its hashed id, and refuses the raw one', function () {
    sessionRow($this->staff, 'staff-phone');

    $this->actingAs($this->owner)->delete('/admin/sessions/staff-phone')->assertNotFound();
    expect(DB::table('sessions')->where('id', 'staff-phone')->exists())->toBeTrue();

    $this->actingAs($this->owner)->delete('/admin/sessions/'.SessionController::key('staff-phone'))->assertRedirect();
    expect(DB::table('sessions')->where('id', 'staff-phone')->exists())->toBeFalse()
        ->and($this->staff->fresh()->remember_token)->not->toBe('old-token');
});

it('does not let a non-Owner sign an Owner out', function () {
    sessionRow($this->owner, 'owner-laptop');

    $this->actingAs($this->lead)->delete('/admin/sessions/'.SessionController::key('owner-laptop'))->assertForbidden();
    expect(DB::table('sessions')->where('id', 'owner-laptop')->exists())->toBeTrue();
});

it('keeps the Client role fixed on the Roles page', function () {
    $client = Role::findByName(Access::CLIENT, 'web');

    $this->actingAs($this->owner)->put('/admin/roles/'.$client->id, ['name' => 'Customer', 'permissions' => ['clients.view']])
        ->assertSessionHasErrors('role');
    $this->actingAs($this->owner)->delete('/admin/roles/'.$client->id)->assertSessionHasErrors('role');

    $client->refresh();
    expect($client->name)->toBe(Access::CLIENT)
        ->and($client->permissions)->toBeEmpty();

    // Nor can another role take the Client name.
    $this->actingAs($this->owner)->post('/admin/roles', ['name' => Access::CLIENT, 'permissions' => []])
        ->assertSessionHasErrors('name');

    $this->actingAs($this->owner)->get('/admin/roles')->assertOk()
        ->assertInertia(fn ($page) => $page->where('roles', fn ($roles) => collect($roles)->firstWhere('name', Access::CLIENT)['is_client'] === true));
});
