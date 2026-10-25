<?php

use App\Models\Campaign;
use App\Models\CampaignDay;
use App\Models\Client;
use App\Models\PilotAccount;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\AgencyAlert;
use App\Services\CampaignAlerts;
use App\Services\CampaignLimits;
use App\Support\Access;
use Carbon\CarbonImmutable;
use Database\Seeders\AccessSeeder;
use Database\Seeders\BooksSeeder;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    $this->seed([AccessSeeder::class, BooksSeeder::class]);
    Notification::fake();
    $this->account = PilotAccount::create(['pilot_id' => 9, 'name' => 'Klok Ads Agency', 'platform' => 'meta', 'currency' => 'USD', 'seen_at' => now()]);
    $this->client = Client::create(['name' => 'GFFS', 'type' => 'client', 'status' => 'active']);
    $this->person = User::factory()->create(['client_id' => $this->client->id]);
    $this->owner = User::factory()->create();
    $this->owner->assignRole(Access::OWNER);
});

afterEach(fn () => CarbonImmutable::setTestNow());

function campaignEnding(Client $client, PilotAccount $account, CarbonImmutable $stop, string $name = 'Sales'): Campaign
{
    return Campaign::create(['pilot_campaign_id' => random_int(1000, 99999), 'pilot_account_id' => $account->id, 'name' => $name, 'status' => 'ACTIVE',
        'client_id' => $client->id, 'bill_from' => $stop->subDays(10)->toDateString(), 'start_at' => $stop->subDays(10), 'stop_at' => $stop]);
}

/** The kinds the client's person was sent, in order. */
function kindsTo(User $user): array
{
    $kinds = [];
    Notification::assertSentTo($user, AgencyAlert::class, function (AgencyAlert $n) use (&$kinds) {
        $kinds[] = $n->kind.':'.($n->params['time'] ?? $n->params['to'] ?? '');

        return true;
    });

    return $kinds;
}

it('counts a campaign down: two days, one day, the last morning, then 4, 3 and 1 hours', function () {
    $stop = CarbonImmutable::parse('2026-11-01 23:59:00');
    $campaign = campaignEnding($this->client, $this->account, $stop);
    $alerts = app(CampaignAlerts::class);
    Setting::put('summary_time', '21:00');

    $sent = [];
    foreach (['2026-10-30 21:05' => 1, '2026-10-30 23:00' => 0, '2026-10-31 21:05' => 1, '2026-11-01 09:10' => 1, '2026-11-01 12:00' => 0,
        '2026-11-01 20:05' => 1, '2026-11-01 20:30' => 0, '2026-11-01 21:10' => 1, '2026-11-01 22:00' => 0, '2026-11-01 23:05' => 1, '2026-11-01 23:30' => 0] as $at => $expected) {
        CarbonImmutable::setTestNow(CarbonImmutable::parse($at));
        $sent[$at] = $alerts->ending();
        expect($sent[$at])->toBe($expected, "at {$at}");
    }

    // Six to the client, six to the owner — one a stage.
    Notification::assertSentToTimes($this->person, AgencyAlert::class, 6);
    Notification::assertSentToTimes($this->owner, AgencyAlert::class, 6);
    expect(collect(kindsTo($this->person))->filter(fn ($k) => str_starts_with($k, 'client_ending'))->count())->toBe(6);
});

it('tells the client and the team once when a campaign has ended, with what it did', function () {
    $stop = CarbonImmutable::parse('2026-11-01 23:59:00');
    $campaign = campaignEnding($this->client, $this->account, $stop);
    foreach (['2026-10-30', '2026-10-31', '2026-11-01'] as $day) {
        CampaignDay::create(['campaign_id' => $campaign->id, 'day' => $day, 'spend' => 200, 'results' => 5, 'clicks' => 10]);
    }
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-02 00:05'));
    $alerts = app(CampaignAlerts::class);

    expect($alerts->ended())->toBe(1)->and($alerts->ended())->toBe(0);
    Notification::assertSentTo($this->person, AgencyAlert::class, fn (AgencyAlert $n) => $n->kind === 'client_ended' && $n->params['spend'] === '$6.00' && $n->params['results'] === 15 && $n->params['days'] === 3);
    Notification::assertSentTo($this->owner, AgencyAlert::class, fn (AgencyAlert $n) => $n->kind === 'campaign_ended');
});

it('sums the day for the client with every campaign on its own line', function () {
    $stop = CarbonImmutable::now()->addDays(5);
    $a = campaignEnding($this->client, $this->account, $stop, 'Sales A');
    $b = campaignEnding($this->client, $this->account, $stop, 'Sales B');
    CampaignDay::create(['campaign_id' => $a->id, 'day' => now()->toDateString(), 'spend' => 150, 'results' => 3, 'clicks' => 1]);
    CampaignDay::create(['campaign_id' => $b->id, 'day' => now()->toDateString(), 'spend' => 250, 'results' => 5, 'clicks' => 1]);

    expect(app(CampaignAlerts::class)->spentToday())->toBe(2);
    Notification::assertSentTo($this->person, AgencyAlert::class, fn (AgencyAlert $n) => $n->kind === 'client_summary'
        && str_contains($n->params['lines'], 'Sales A — $1.50 · 3') && str_contains($n->params['lines'], 'Sales B — $2.50 · 5')
        && $n->params['spend'] === '$4.00' && $n->params['results'] === 8);
});

it('tells the client when the team changes the daily budget', function () {
    Http::fake(fn () => Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => ['structuredContent' => [
        'daily_budget' => 3.0, 'spend_cap' => null, 'active' => true, 'end' => now()->addDays(5)->toIso8601String(), 'extendable' => true, 'why_not' => null, 'spent_before' => 0,
    ]]]));
    Setting::put('pilot_url', 'https://pilot.test/mcp');
    Setting::put('pilot_token', Crypt::encryptString('secret'));
    $campaign = campaignEnding($this->client, $this->account, CarbonImmutable::now()->addDays(5));
    $campaign->forceFill(['daily_budget' => 200])->save();

    app(CampaignLimits::class)->teamBudget($campaign->fresh(), 300, $this->owner);
    Notification::assertSentTo($this->person, AgencyAlert::class, fn (AgencyAlert $n) => $n->kind === 'client_budget' && $n->params['from'] === '$2.00' && $n->params['to'] === '$3.00');
});
