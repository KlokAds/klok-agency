<?php

use App\Models\BdtEntry;
use App\Models\Client;
use App\Models\Task;
use App\Models\User;
use App\Support\Access;
use Database\Seeders\AccessSeeder;
use Database\Seeders\BooksSeeder;

beforeEach(function () {
    $this->seed([AccessSeeder::class, BooksSeeder::class]);

    $this->owner = User::factory()->create();
    $this->owner->assignRole(Access::OWNER);
});

it('pages the client list and filters it by status and name', function () {
    foreach (range(1, 35) as $i) {
        Client::create(['name' => sprintf('Client %02d', $i), 'type' => 'client', 'status' => 'active']);
    }
    Client::create(['name' => 'Old Shop', 'status' => 'inactive']);

    $this->actingAs($this->owner)->get('/clients')
        ->assertInertia(fn ($page) => $page->component('clients/Index')
            ->has('clients.data', 10)
            ->where('clients.total', 35)
            ->where('clients.last_page', 4)
            ->where('filters.status', 'active'));

    $this->actingAs($this->owner)->get('/clients?page=4')
        ->assertInertia(fn ($page) => $page->has('clients.data', 5)->where('clients.data.0.name', 'Client 31'));

    // The reader can ask for more rows per page.
    $this->actingAs($this->owner)->get('/clients?per_page=25')
        ->assertInertia(fn ($page) => $page->has('clients.data', 25)->where('clients.per_page', 25));

    $this->actingAs($this->owner)->get('/clients?status=inactive')
        ->assertInertia(fn ($page) => $page->has('clients.data', 1)->where('clients.data.0.name', 'Old Shop'));

    $this->actingAs($this->owner)->get('/clients?status=all&q=Client 1')
        ->assertInertia(fn ($page) => $page->where('clients.total', 10));
});

it('pages tasks and opens the page a focused task is on', function () {
    foreach (range(1, 45) as $i) {
        Task::create(['title' => "Task {$i}", 'kind' => 'task', 'priority' => 'normal', 'repeat' => 'none', 'due_at' => now()->addHours($i)]);
    }
    $last = Task::where('title', 'Task 45')->sole();

    $this->actingAs($this->owner)->get('/tasks')
        ->assertInertia(fn ($page) => $page->component('agency/Tasks')
            ->has('tasks.data', 10)
            ->where('tasks.total', 45)
            ->where('counts.open', 45));

    $this->actingAs($this->owner)->get("/tasks?focus={$last->id}")
        ->assertInertia(fn ($page) => $page->where('tasks.current_page', 5)->has('tasks.data', 5));
});

it('pages the rows that still need a place, and counts all of them for the bulk action', function () {
    foreach (range(1, 35) as $i) {
        BdtEntry::create([
            'entry_date' => now()->subDays($i)->toDateString(), 'sequence' => $i, 'description' => "Row {$i}",
            'category' => 'expense', 'amount' => -100 * $i,
        ]);
    }

    $this->actingAs($this->owner)->get('/wallets')
        ->assertInertia(fn ($page) => $page->component('books/Wallets')
            ->has('untagged.data', 10)
            ->where('untagged.total', 35));

    $this->actingAs($this->owner)->get('/wallets?untagged_page=4')
        ->assertInertia(fn ($page) => $page->has('untagged.data', 5));

    // Tagging the last rows of page 2 falls back to page 1 instead of an empty list.
    BdtEntry::whereNull('wallet')->orderBy('entry_date')->limit(30)->get()->each->update(['wallet' => 'bank']);

    $this->actingAs($this->owner)->get('/wallets?untagged_page=4')
        ->assertInertia(fn ($page) => $page->has('untagged.data', 5)->where('untagged.current_page', 1));
});
