<?php

namespace Tests\Feature\Settings;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProfileUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_page_is_displayed()
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('profile.edit'))->assertOk();
    }

    public function test_name_can_be_updated_but_email_cannot()
    {
        $user = User::factory()->create();
        $email = $user->email;

        $this->actingAs($user)
            ->patch(route('profile.update'), ['name' => 'Test User', 'email' => 'someone-else@example.com'])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('profile.edit'));

        $user->refresh();

        $this->assertSame('Test User', $user->name);
        $this->assertSame($email, $user->email);
    }

    public function test_users_cannot_delete_their_own_account()
    {
        $user = User::factory()->create();

        $this->actingAs($user)->delete('/settings/profile')->assertStatus(405);
        $this->assertNotNull($user->fresh());
    }
}
