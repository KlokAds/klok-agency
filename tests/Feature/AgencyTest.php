<?php

use App\Models\Account;
use App\Models\AdRun;
use App\Models\Client;
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

it('reminds the person a task is given to and the owner', function () {
    $staff = User::factory()->create();
    $staff->assignRole('Accountant');

    Task::create(['title' => 'Send invoice', 'kind' => 'task', 'priority' => 'normal', 'repeat' => 'none',
        'assigned_to' => $staff->id, 'remind_at' => now()->subMinute(), 'due_at' => now()]);

    app(Ticker::class)->run();

    expect($staff->notifications()->where('data->kind', 'task')->count())->toBe(1)
        ->and($this->owner->notifications()->where('data->kind', 'task')->count())->toBe(1);

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
    $this->client->update(['pilot_shop_id' => 7]);

    Http::fake([
        'pilot.klokads.com/*' => Http::response([
            'jsonrpc' => '2.0', 'id' => 1,
            'result' => ['content' => [['type' => 'text', 'text' => json_encode([
                'currency' => 'USD',
                'campaigns' => [['platform_conversions' => 30], ['platform_conversions' => 10]],
                'totals' => ['spend' => 14.0, 'blended_real_roas' => 2.4],
            ])]]],
        ]),
    ]);

    $summary = app(DailySummary::class)->build();

    expect($summary['ads'][0])->toMatchArray(['client' => 'GFFS', 'spend' => 14.0, 'results' => 40, 'cpr' => 0.35, 'roas' => 2.4]);

    Http::assertSent(fn ($r) => $r->hasHeader('Authorization', 'Bearer agency-token')
        && $r['method'] === 'tools/call' && $r['params']['name'] === 'campaign_performance' && $r['params']['arguments']['shop_id'] === 7);
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
