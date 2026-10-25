<?php

use App\Models\User;
use App\Support\Access;
use Database\Seeders\AccessSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    $this->seed(AccessSeeder::class);
    $this->owner = User::factory()->create(['name' => 'Owner']);
    $this->owner->assignRole(Access::OWNER);
    $this->anowar = User::factory()->create(['name' => 'Anowar', 'remember_token' => 'old-token']);
    $this->anowar->assignRole('Accountant');
});

function signedInAt(User $user, string $id, string $agent, int $ago = 60): void
{
    DB::table('sessions')->insert([
        'id' => $id, 'user_id' => $user->id, 'ip_address' => '103.1.1.1',
        'user_agent' => $agent, 'payload' => '', 'last_activity' => now()->subSeconds($ago)->getTimestamp(),
    ]);
}

it('shows who is signed in where, and the Owner can end a session', function () {
    signedInAt($this->anowar, 'anowar-phone', 'Mozilla/5.0 (Linux; Android 14; SM-A155F) Chrome/130 Mobile KlokFinanceApp/3');
    signedInAt($this->owner, 'old-owner', 'Mozilla/5.0 (Windows NT 10.0) Chrome/130 Safari/537', 10 * 86400);

    $this->actingAs($this->owner)->get('/admin/sessions')->assertOk()
        ->assertInertia(fn ($page) => $page->component('admin/Sessions')
            ->has('sessions', 1)
            ->where('sessions.0.user', 'Anowar')
            ->where('sessions.0.device', 'Android app · SM-A155F')
            ->where('sessions.0.active', true));

    $this->actingAs($this->owner)->delete('/admin/sessions/anowar-phone')->assertRedirect();

    expect(DB::table('sessions')->where('id', 'anowar-phone')->exists())->toBeFalse()
        ->and($this->anowar->fresh()->remember_token)->not->toBe('old-token');

    $this->actingAs($this->anowar)->get('/admin/sessions')->assertForbidden();
});

it('shows the Owner what went wrong, and nobody else', function () {
    $log = storage_path('logs/test-errors.log');
    // Now, so it is among the newest whatever else this machine's logs hold.
    $at = now()->format('Y-m-d H:i:s');
    File::put($log, "[{$at}] production.ERROR: Klok Pilot: An internal server error occurred. {\"exception\":\"[object] (RuntimeException(code: 0): Klok Pilot at /var/app/app/Services/PilotClient.php:147)\"}\n#0 trace\n[{$at}] production.INFO: fine\n");

    try {
        $this->actingAs($this->owner)->get('/admin/errors')->assertOk()
            ->assertInertia(fn ($page) => $page->component('admin/Errors')
                ->where('entries.data', fn ($entries) => collect($entries)->contains(
                    fn ($e) => $e['message'] === 'Klok Pilot: An internal server error occurred.' && $e['level'] === 'ERROR' && $e['detail'] === '/var/app/app/Services/PilotClient.php:147',
                ))
                // INFO lines are not problems.
                ->where('entries.data', fn ($entries) => ! collect($entries)->contains('message', 'fine')));

        $this->actingAs($this->anowar)->get('/admin/errors')->assertForbidden();
    } finally {
        File::delete($log);
    }
});
