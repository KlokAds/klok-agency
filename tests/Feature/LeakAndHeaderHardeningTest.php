<?php

use App\Models\BdtEntry;
use App\Models\Client;
use App\Models\User;
use App\Services\DailySummary;
use App\Support\Access;
use Database\Seeders\AccessSeeder;
use Illuminate\Support\Facades\Log;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    $this->seed(AccessSeeder::class);

    $this->owner = User::factory()->create();
    $this->owner->assignRole(Access::OWNER);
});

function hardeningUser(string $role, array $permissions = []): User
{
    if ($permissions) {
        Role::findOrCreate($role, 'web')->syncPermissions($permissions);
    }

    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

test('the quick-entry lists go only to those who may write an entry', function () {
    $tasksOnly = hardeningUser('Tasks only', ['tasks.view']);

    $this->actingAs($tasksOnly)->get('/tasks')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->reloadOnly('lookups', fn (Assert $reload) => $reload->where('lookups', null)));

    $this->actingAs(hardeningUser('Accountant'))->get('/tasks')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->reloadOnly('lookups', fn (Assert $reload) => $reload->has('lookups.clients')->has('lookups.pending')));
});

test('the daily summary never reaches a portal user set as a client manager', function () {
    $portal = User::factory()->create();
    $portal->assignRole(Access::CLIENT);
    Client::create(['name' => 'GFFS', 'type' => 'client', 'status' => 'active', 'manager_id' => $portal->id]);

    app(DailySummary::class)->send();

    expect($portal->notifications()->count())->toBe(0)
        ->and($this->owner->notifications()->where('data->kind', 'summary')->count())->toBe(1);
});

test('an app crash report cannot add a line of its own to the log, and a long one is cut, not refused', function () {
    $logger = Mockery::mock();
    $logger->shouldReceive('error')->once()->with(Mockery::on(
        fn (string $line) => ! preg_match('/[\r\n]/', $line) && str_contains($line, '[2026-10-27 09:00:00] production.CRITICAL') && mb_strlen($line) < 4300,
    ));
    Log::shouldReceive('build')->once()->andReturn($logger);

    $this->postJson(route('hooks.app-crash'), [
        'where' => 'native',
        'error' => str_repeat("at com.klok.Main\n", 1000),
        'device' => "x\n[2026-10-27 09:00:00] production.CRITICAL: Pilot token expired",
    ])->assertOk();
});

test('exported text that looks like a formula is written as plain text', function () {
    BdtEntry::create(['entry_date' => now()->toDateString(), 'description' => '=HYPERLINK("https://evil.example","Open")',
        'category' => 'office', 'amount' => -10000, 'notes' => '@SUM(A1)']);

    $csv = $this->actingAs($this->owner)->get('/reports/export/bdt')->assertOk()->streamedContent();

    expect($csv)->toContain("'=HYPERLINK")->toContain("'@SUM(A1)")->not->toContain(',"=HYPERLINK');
});

test('an export needs the reports page too, the P&L included', function () {
    $exporter = hardeningUser('BDT exporter', ['bdt.view', 'reports.export']);

    $this->actingAs($exporter)->get('/reports/export/pl?preset=all')->assertForbidden();
    $this->actingAs($exporter)->get('/reports/export/bdt')->assertForbidden();
    $this->actingAs(hardeningUser('Accountant'))->get('/reports/export/pl?preset=all')->assertOk();
});

test('signed-in answers name their user for the offline worker; signed-out ones do not', function () {
    $this->actingAs($this->owner)->get('/dashboard')->assertHeader('X-Kept-For', (string) $this->owner->id);

    auth()->logout();
    $this->get('/login')->assertHeaderMissing('X-Kept-For');
});
