<?php

use App\Models\Client;
use App\Models\User;
use App\Services\Books;
use App\Support\Access;
use Database\Seeders\AccessSeeder;
use Database\Seeders\BooksSeeder;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->seed([AccessSeeder::class, BooksSeeder::class]);
    $this->artisan('books:import-sheet')->assertSuccessful();
});

function dashboardUser(string $role): User
{
    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

it('shows the card, what Meta will still charge and the client rows', function (string $role) {
    $summary = app(Books::class)->summary();
    $gffs = Client::where('name', 'GFFS')->firstOrFail();

    $this->actingAs(dashboardUser($role))->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard')
            ->where('summary.card', $summary['card'])
            ->where('summary.platform_due', $summary['platform_due'])
            ->where('summary.card_after', $summary['card'] - $summary['platform_due'])
            ->where('fx.remaining', $summary['usd_check']['remaining'])
            ->has('fx.current.number')
            ->has('wallets.places')
            ->has('month_net')
            ->where('clients', fn ($clients) => collect($clients)->contains(
                fn ($c) => $c['id'] === $gffs->id && $c['unbilled_usd'] > 0
            ))
            ->has('clients.0', fn (Assert $row) => $row
                ->hasAll(['id', 'name', 'credit_usd', 'spent_usd', 'balance_usd', 'unbilled_usd', 'days_left'])
                ->etc()));
})->with([Access::OWNER, 'Viewer']);

it('puts clients who owe money first', function () {
    $this->actingAs(dashboardUser(Access::OWNER))->get('/dashboard')
        ->assertInertia(fn (Assert $page) => $page
            ->where('clients', function ($clients) {
                $balances = collect($clients)->pluck('balance_usd')->all();
                $owing = collect($balances)->takeWhile(fn ($b) => $b < 0)->count();

                return collect($balances)->slice($owing)->every(fn ($b) => $b >= 0);
            }));
});

it('renders the report for a period with KPIs against the period before', function () {
    $pl = app(Books::class)->profitAndLoss(now()->startOfMonth(), now()->endOfMonth());

    $this->actingAs(dashboardUser('Viewer'))->get('/reports?preset=this_month')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('reports/Index')
            ->where('period.preset', 'this_month')
            ->where('period.from', now()->startOfMonth()->toDateString())
            ->where('kpi.net', $pl['net'])
            ->where('kpi.gross', $pl['margin'])
            ->where('kpi.office', $pl['total_expense'])
            ->where('kpi.revenue', $pl['margin'] + collect($pl['clients'])->sum('cost_settled'))
            ->where('previous_period.from', now()->subMonthNoOverflow()->startOfMonth()->toDateString())
            ->has('previous.net')
            ->has('trend', 6)
            ->where('trend.5.in_period', true)
            ->where('trend.0.in_period', false)
            ->has('pl.expenses')
            ->has('outside.unearned')
            ->has('outside.owner_due'));

    $this->actingAs(dashboardUser(Access::OWNER))
        ->get('/reports?preset=custom&from=2026-01-01&to=2026-01-31')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('period.from', '2026-01-01')
            ->where('previous_period', ['from' => '2025-12-01', 'to' => '2025-12-31']));
});

it('shows no comparison for all time', function () {
    $this->actingAs(dashboardUser(Access::OWNER))->get('/reports')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('previous', null)
            ->has('trend', 12)
            ->where('kpi.net', app(Books::class)->profitAndLoss()['net']));
});

it('still exports the P&L as CSV', function () {
    $response = $this->actingAs(dashboardUser(Access::OWNER))->get('/reports/export/pl?preset=all');

    $response->assertOk();
    expect($response->streamedContent())->toContain('Net profit / (loss)');
});
