<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RateLimitKeysTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_invoice_status_poll_does_not_block_the_trxid_claim_or_pay(): void
    {
        // Over a minute of the page's 5-second poll, then more: past the claim (6) and pay (12) limits.
        for ($i = 0; $i < 20; $i++) {
            $this->getJson('/i/some-token/status')->assertStatus(404);
        }

        $this->postJson('/i/some-token/claim', ['trxid' => 'ABC123'])->assertStatus(404);
        $this->postJson('/i/some-token/pay', ['method' => 'bkash'])->assertStatus(404);
        $this->getJson('/i/some-token')->assertStatus(404);
    }

    public function test_each_public_route_still_has_its_own_limit(): void
    {
        for ($i = 0; $i < 60; $i++) {
            $this->getJson('/i/some-token/status');
        }
        $this->getJson('/i/some-token/status')->assertStatus(429);
        // The page itself counts separately from its poll.
        $this->getJson('/i/some-token')->assertStatus(404);

        for ($i = 0; $i < 6; $i++) {
            $this->postJson('/i/some-token/claim');
        }
        $this->postJson('/i/some-token/claim')->assertStatus(429);
        $this->postJson('/i/some-token/cancel')->assertStatus(404);
    }

    public function test_a_signed_in_users_app_polling_does_not_use_up_other_actions(): void
    {
        $user = User::factory()->create();

        // app/latest (30/min) first, so the old shared counter would already be past feedback's 5.
        for ($i = 0; $i < 10; $i++) {
            $this->actingAs($user)->getJson('/app/latest')->assertOk();
        }

        $this->actingAs($user)->postJson('/feedback', [])->assertStatus(422);
    }
}
