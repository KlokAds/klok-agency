<?php

use App\Models\ExpenseHead;
use App\Models\User;
use App\Support\Access;
use Database\Seeders\AccessSeeder;

beforeEach(function () {
    $this->seed(AccessSeeder::class);
    $this->owner = User::factory()->create();
    $this->owner->assignRole(Access::OWNER);
});

it('turns an expense head off and on again, the way the button sends it', function () {
    $head = ExpenseHead::create(['name' => 'Bank charge', 'is_active' => true]);

    $this->actingAs($this->owner)
        ->putJson("/expenses/heads/{$head->id}", ['name' => 'Bank charge', 'is_active' => false], ['X-Inertia' => 'true'])
        ->assertRedirect();
    expect($head->fresh()->is_active)->toBeFalse();

    $this->actingAs($this->owner)
        ->putJson("/expenses/heads/{$head->id}", ['name' => 'Bank charge', 'is_active' => true], ['X-Inertia' => 'true'])
        ->assertRedirect();
    expect($head->fresh()->is_active)->toBeTrue();
});
