<?php

use App\Models\Account;
use App\Models\AdRun;
use App\Models\Client;
use App\Models\PilotAccount;
use App\Models\Setting;
use App\Models\Task;
use App\Models\User;
use App\Services\Agency;
use App\Services\DailySummary;
use App\Services\Ticker;
use App\Support\Access;
use Database\Seeders\AccessSeeder;
use Database\Seeders\BooksSeeder;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->seed([AccessSeeder::class, BooksSeeder::class]);

    $this->owner = User::factory()->create();
    $this->owner->assignRole(Access::OWNER);

    $this->client = Client::create(['name' => 'GFFS', 'type' => 'client', 'status' => 'active', 'phone' => '01711-223344']);
    $this->meta = Account::where('code', 'meta')->first();
});

function makeRun(array $overrides = []): AdRun
{
    $run = AdRun::create($overrides + [
        'client_id' => test()->client->id,
        'account_id' => test()->meta->id,
        'title' => 'GFFS - Meta ad spend',
        'budget_usd' => 1400,
        'daily_usd' => 200,
        'days' => 7,
        'start_on' => now()->toDateString(),
        'end_on' => now()->addDays(6)->toDateString(),
        'status' => 'running',
    ]);
    app(Agency::class)->plan($run);

    return $run;
}

it('tracks an ad from quick entry when its days are given', function () {
    $this->actingAs($this->owner)->post('/quick/ad-spend', [
        'entry_date' => now()->toDateString(), 'client_id' => $this->client->id, 'account_id' => $this->meta->id,
        'amount' => '14', 'status' => 'pending', 'run_days' => 7,
    ])->assertRedirect();

    $run = AdRun::first();

    expect($run->budget_usd)->toBe(1400)
        ->and($run->end_on->toDateString())->toBe(now()->addDays(6)->toDateString())
        ->and($run->tasks()->pluck('kind')->sort()->values()->all())->toBe(['renewal', 'result'])
        ->and($run->tasks()->where('kind', 'renewal')->first()->remind_at->toDateString())->toBe(now()->addDays(5)->toDateString());
});

it('sends a due renew reminder once, with a WhatsApp link to the client', function () {
    $run = makeRun();
    $run->tasks()->where('kind', 'renewal')->update(['remind_at' => now()->subMinute()]);

    app(Ticker::class)->run();
    app(Ticker::class)->run();

    $alerts = $this->owner->notifications()->where('data->kind', 'renewal')->get();

    expect($alerts)->toHaveCount(1)
        ->and($alerts->first()->data['whatsapp'])->toStartWith('https://wa.me/8801711223344?text=');
});

it('asks to scale a good ad and to fix a bad one', function () {
    $run = makeRun();

    $this->actingAs($this->owner)->put("/runs/{$run->id}/result", ['result' => 'good', 'results' => 40, 'cost_per_result' => '0.35'])->assertRedirect();

    expect($run->fresh()->cost_per_result)->toBe(35)
        ->and($run->tasks()->where('kind', 'result')->first()->done_at)->not->toBeNull()
        ->and($run->tasks()->where('kind', 'scale')->first()->title)->toBe(Agency::SCALE_TITLE);

    $this->actingAs($this->owner)->put("/runs/{$run->id}/result", ['result' => 'bad'])->assertRedirect();

    expect($run->tasks()->where('kind', 'scale')->whereNull('done_at')->pluck('title')->all())->toBe([Agency::FIX_TITLE]);
});

it('renews a run and closes the old one', function () {
    $old = makeRun();

    $this->actingAs($this->owner)->post("/runs/{$old->id}/renew", ['budget_usd' => '21', 'days' => 7])->assertRedirect();

    $new = AdRun::latest('id')->first();

    expect($old->fresh()->status)->toBe('renewed')
        ->and($new->renewed_from_id)->toBe($old->id)
        ->and($new->start_on->toDateString())->toBe($old->end_on->addDay()->toDateString())
        ->and($new->budget_usd)->toBe(2100);
});

it('marks finished runs as ended and tells the owner', function () {
    makeRun(['start_on' => now()->subDays(8)->toDateString(), 'end_on' => now()->subDay()->toDateString()]);

    app(Ticker::class)->run();

    expect(AdRun::first()->status)->toBe('ended')
        ->and($this->owner->notifications()->where('data->kind', 'ended')->count())->toBe(1);
});

it('brings a repeating task back after it is done', function () {
    $task = Task::create(['title' => 'Weekly call', 'kind' => 'meeting', 'priority' => 'normal', 'repeat' => 'weekly',
        'client_id' => $this->client->id, 'due_at' => now()->addHour(), 'remind_at' => now()->addHour()]);

    $this->actingAs($this->owner)->post("/tasks/{$task->id}/toggle")->assertRedirect();

    $next = Task::whereNull('done_at')->first();

    expect($task->fresh()->done_at)->not->toBeNull()
        ->and($next->due_at->toDateString())->toBe(now()->addHour()->addWeek()->toDateString());
});

it('reminds the person a task is given to, and the owner only when they follow tasks', function () {
    $staff = User::factory()->create();
    $staff->assignRole('Accountant');

    Task::create(['title' => 'Send invoice', 'kind' => 'task', 'priority' => 'normal', 'repeat' => 'none',
        'assigned_to' => $staff->id, 'remind_at' => now()->subMinute(), 'due_at' => now()]);

    app(Ticker::class)->run();

    expect($staff->notifications()->where('data->kind', 'task')->count())->toBe(1)
        ->and($this->owner->notifications()->where('data->kind', 'task')->count())->toBe(0);

    $this->actingAs($staff)->getJson('/notifications/feed')->assertJsonPath('items.0.kind', 'task');
    $this->actingAs($staff)->post('/notifications/read-all')->assertRedirect();
    expect($staff->unreadNotifications()->count())->toBe(0);
});

it('runs the tick from the secret URL only', function () {
    $this->get('/agency/tick/wrong')->assertNotFound();

    Setting::put('tick_token', 'secret-token-123');
    $this->get('/agency/tick/secret-token-123')->assertOk()->assertJsonPath('ok', true);
});

it('reads ad results from Klok Pilot for the daily summary', function () {
    Setting::put('pilot_token', Crypt::encryptString('agency-token'));
    PilotAccount::create(['pilot_id' => 7, 'name' => 'GFFS Ads', 'client_id' => $this->client->id]);

    Http::fake([
        'pilot.klokads.com/*' => Http::response([
            'jsonrpc' => '2.0', 'id' => 1,
            'result' => ['content' => [['type' => 'text', 'text' => json_encode([
                'currency' => 'USD',
                'campaigns' => [['results' => 30], ['results' => 10]],
                'totals' => ['spend' => 14.0, 'results' => 40, 'clicks' => 210],
            ])]]],
        ]),
    ]);

    $summary = app(DailySummary::class)->build();

    expect($summary['ads'][0])->toMatchArray(['client' => 'GFFS', 'account' => 'GFFS Ads', 'spend' => 14.0, 'results' => 40, 'cpr' => 0.35, 'clicks' => 210]);

    Http::assertSent(fn ($r) => $r->hasHeader('Authorization', 'Bearer agency-token')
        && $r['method'] === 'tools/call' && $r['params']['name'] === 'ad_account_performance' && $r['params']['arguments']['ad_account_id'] === 7);
});

it('sends a missed summary for yesterday the next morning, then today\'s in the evening', function () {
    $this->travelTo(now()->setTime(8, 0));
    Setting::putQuietly('summary_sent_on', now()->subDays(2)->toDateString());

    app(Ticker::class)->run();

    $first = $this->owner->notifications()->where('data->kind', 'summary')->sole();
    expect($first->data['title'])->toBe('গতকালের ads আর কাজের সারসংক্ষেপ')
        ->and($first->data['summary']['date'])->toBe(now()->subDay()->toDateString());

    app(Ticker::class)->run();
    expect($this->owner->notifications()->where('data->kind', 'summary')->count())->toBe(1);

    $this->travelTo(now()->setTime(21, 5));
    app(Ticker::class)->run();
    expect($this->owner->notifications()->where('data->kind', 'summary')->count())->toBe(2);
});

it('works out a past day from Pilot as the difference of two windows', function () {
    Setting::put('pilot_token', Crypt::encryptString('agency-token'));
    PilotAccount::create(['pilot_id' => 7, 'name' => 'GFFS Ads', 'client_id' => $this->client->id]);

    Http::fake(function ($request) {
        $days = $request['params']['arguments']['days'];

        return Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => ['structuredContent' => [
            'currency' => 'USD',
            'totals' => $days === 2 ? ['spend' => 20.0, 'results' => 50, 'clicks' => 300] : ['spend' => 6.0, 'results' => 10, 'clicks' => 90],
        ]]]);
    });

    $summary = app(DailySummary::class)->build(now()->subDay());

    expect($summary['ads'][0])->toMatchArray(['spend' => 14.0, 'results' => 40, 'cpr' => 0.35, 'clicks' => 210]);
});

it('counts what every Pilot account spent today and the Meta campaigns that end tomorrow', function () {
    $this->travelTo(now()->setTime(21, 0));
    Setting::put('pilot_token', Crypt::encryptString('agency-token'));
    PilotAccount::create(['pilot_id' => 7, 'name' => 'GFFS Ads', 'client_id' => $this->client->id]);
    PilotAccount::create(['pilot_id' => 8, 'name' => 'Edge Meta', 'currency' => 'BDT']);
    $midnight = now()->addDays(2)->startOfDay()->toIso8601String();

    Http::fake(function ($request) use ($midnight) {
        $args = $request['params']['arguments'];
        $content = $request['params']['name'] === 'ad_account_campaigns'
            ? ['campaigns' => $args['ad_account_id'] === 7 ? [
                ['campaign_id' => 1, 'name' => 'Sales', 'status' => 'ACTIVE', 'stop_time' => $midnight, 'spend_7d' => 10.2],
                ['campaign_id' => 2, 'name' => 'Old', 'status' => 'ACTIVE', 'stop_time' => now()->subMonth()->toIso8601String(), 'spend_7d' => 0],
                ['campaign_id' => 3, 'name' => 'Later', 'status' => 'ACTIVE', 'stop_time' => now()->addDays(5)->toIso8601String(), 'spend_7d' => 4],
            ] : []]
            : ['currency' => $args['ad_account_id'] === 7 ? 'USD' : 'BDT', 'totals' => ['spend' => $args['ad_account_id'] === 7 ? 12.4 : 310.0, 'results' => 3]];

        return Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => ['structuredContent' => $content]]);
    });

    $summary = app(DailySummary::class)->build();

    expect($summary['spent_usd'])->toBe(1240)
        ->and($summary['spent_bdt'])->toBe(31000)
        ->and(array_column($summary['ads'], 'client'))->toBe(['Edge Meta', 'GFFS'])
        ->and($summary['ending'])->toHaveCount(1)
        ->and($summary['ending'][0])->toMatchArray(['client' => 'GFFS', 'title' => 'Sales', 'end_on' => now()->addDay()->toDateString()]);

    app(DailySummary::class)->send();
    expect($this->owner->notifications()->where('data->kind', 'summary')->sole()->data['params'])->toMatchArray(['spent' => "12.40 + \u{09F3}310", 'ending' => 1]);
});

it('lists Pilot ad accounts with their campaigns and links them to clients', function () {
    Setting::put('pilot_token', Crypt::encryptString('agency-token'));
    Http::fake(function ($request) {
        $content = $request['params']['name'] === 'list_ad_accounts'
            ? ['ad_accounts' => [
                ['ad_account_id' => 7, 'external_id' => 'act_1', 'name' => 'GFFS Ads', 'platform' => 'meta', 'currency' => 'USD', 'shop' => 'Klok Ads'],
                ['ad_account_id' => 8, 'external_id' => 'act_2', 'name' => 'GFFS Page 2', 'platform' => 'meta', 'currency' => 'BDT', 'shop' => 'Klok Ads'],
            ]]
            : ['currency' => 'USD', 'totals' => ['spend' => 14.0, 'results' => 40], 'campaigns' => [
                ['name' => 'Messages', 'status' => 'ACTIVE', 'spend' => 14.0, 'results' => 40, 'cost_per_result' => 0.35],
                ['name' => 'Old', 'status' => 'PAUSED', 'spend' => 0, 'results' => 0],
            ]];

        return Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => ['structuredContent' => $content]]);
    });

    $this->actingAs($this->owner)->get('/ad-accounts')->assertOk()
        ->assertInertia(fn ($page) => $page->component('agency/AdAccounts')->has('accounts', 2)
            ->where('accounts.0.name', 'GFFS Ads')
            ->where('accounts.0.campaigns', fn ($c) => count($c) === 1 && $c[0]['name'] === 'Messages'));

    // A client can have more than one ad account.
    foreach (PilotAccount::all() as $account) {
        $this->actingAs($this->owner)->put("/ad-accounts/{$account->id}/client", ['client_id' => $this->client->id])->assertRedirect();
    }

    $this->actingAs($this->owner)->get('/clients/'.$this->client->id)->assertOk()
        ->assertInertia(fn ($page) => $page->has('pilot.accounts', 2)->where('pilot.accounts.0.totals.results', 40));

    $viewer = User::factory()->create();
    $viewer->assignRole('Viewer');
    $this->actingAs($viewer)->put('/ad-accounts/'.PilotAccount::first()->id.'/client', ['client_id' => null])->assertForbidden();
});

it('logs a secret setting without its value, keyed by name', function () {
    Setting::put('pilot_token', Crypt::encryptString('agency-token'));
    Setting::put('summary_time', '21:30');

    $secret = Activity::where('subject_id', 'pilot_token')->sole();
    $plain = Activity::where('subject_id', 'summary_time')->sole();

    expect(json_encode($secret->attribute_changes))->not->toContain('eyJ')
        ->and($plain->attribute_changes['attributes']['value'])->toBe('21:30');
});

it('opens the agency pages', function (string $url) {
    makeRun();
    $this->actingAs($this->owner)->get($url)->assertOk();
})->with(['/runs', '/runs?view=ending', '/tasks', '/tasks?view=done', '/notifications', '/admin/agency']);

it('keeps a viewer from changing runs and tasks', function () {
    $viewer = User::factory()->create();
    $viewer->assignRole('Viewer');
    $run = makeRun();

    $this->actingAs($viewer)->get('/runs')->assertOk();
    $this->actingAs($viewer)->put("/runs/{$run->id}/result", ['result' => 'good'])->assertForbidden();
    $this->actingAs($viewer)->post('/tasks', ['title' => 'x'])->assertForbidden();
});
