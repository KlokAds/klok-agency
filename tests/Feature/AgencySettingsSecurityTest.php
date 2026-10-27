<?php

use App\Models\Setting;
use App\Models\User;
use App\Notifications\AgencyAlert;
use App\Services\PilotClient;
use App\Support\Access;
use Database\Seeders\AccessSeeder;
use Database\Seeders\BooksSeeder;
use Illuminate\Support\Facades\Crypt;

beforeEach(function () {
    $this->seed([AccessSeeder::class, BooksSeeder::class]);
    $this->owner = User::factory()->create();
    $this->owner->assignRole(Access::OWNER);
    Setting::put('pilot_token', Crypt::encryptString('agency-token'));
});

function agencyForm(string $url): array
{
    return ['summary_time' => '21:00', 'pilot_url' => $url, 'credit_guard' => true];
}

it('never takes a Pilot URL outside klokads.com, so the token cannot be sent elsewhere', function () {
    $this->actingAs($this->owner)->put('/admin/agency', agencyForm('https://attacker.example/mcp'))
        ->assertSessionHasErrors('pilot_url');

    expect(PilotClient::url())->toBe('https://pilot.klokads.com/mcp')
        ->and(PilotClient::token())->toBe('agency-token');
});

it('drops the saved token when the Pilot URL changes, and keeps it when it does not', function () {
    $this->actingAs($this->owner)->put('/admin/agency', agencyForm('https://pilot.klokads.com/mcp'))->assertSessionHasNoErrors();
    expect(PilotClient::token())->toBe('agency-token');

    $this->actingAs($this->owner)->put('/admin/agency', agencyForm('https://pilot2.klokads.com/mcp'))->assertSessionHasNoErrors();
    expect(PilotClient::url())->toBe('https://pilot2.klokads.com/mcp')
        ->and(PilotClient::connected())->toBeFalse();
});

it('shows a name typed at sign-up as text in the email, never as a link or image', function () {
    $user = User::factory()->create(['name' => '[Your invoice is overdue](https://evil.example/pay) ![](https://evil.example/t.png)']);
    $alert = new AgencyAlert('client_welcome', 'Welcome', 'Hello');
    $alert->id = 'test-id';

    $html = (string) $alert->toMail($user)->render();

    expect($html)->not->toContain('href="https://evil.example')
        ->and($html)->not->toContain('src="https://evil.example')
        ->and($html)->toContain('Your invoice is overdue');
});
