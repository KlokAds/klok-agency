<?php

use App\Models\Client;
use App\Models\Employee;
use App\Models\User;
use App\Support\Access;
use Database\Seeders\AccessSeeder;

beforeEach(function () {
    $this->seed(AccessSeeder::class);
    $this->accountant = User::factory()->create();
    $this->accountant->assignRole('Accountant');
    $this->viewer = User::factory()->create();
    $this->viewer->assignRole('Viewer');
});

// What the page hides, the server refuses too: a link or an old tab gets 403, not a change.
it('refuses an Accountant the payroll actions the page does not show', function () {
    $employee = Employee::create(['name' => 'Rafi', 'monthly_salary' => 2000000, 'status' => 'active']);

    $this->actingAs($this->accountant)->get('/payroll')->assertOk();
    $this->actingAs($this->accountant)->post('/payroll/employees', ['name' => 'X', 'monthly_salary' => 1000])->assertForbidden();
    $this->actingAs($this->accountant)->put("/payroll/employees/{$employee->id}", ['name' => 'Y', 'monthly_salary' => 1000, 'status' => 'active'])->assertForbidden();
    $this->actingAs($this->accountant)->delete("/payroll/employees/{$employee->id}")->assertForbidden();
    $this->actingAs($this->accountant)->post('/payroll/pay', ['employee_id' => $employee->id, 'amount' => 10])->assertForbidden();
});

it('keeps a Viewer out of every admin page and every write', function () {
    foreach (['/admin/users', '/admin/roles', '/admin/settings', '/trash', '/activity', '/admin/system'] as $url) {
        $this->actingAs($this->viewer)->get($url)->assertForbidden();
    }
    $this->actingAs($this->viewer)->post('/clients', ['name' => 'Nope', 'status' => 'active'])->assertForbidden();
    $this->actingAs($this->viewer)->post('/tasks', ['title' => 'Nope', 'kind' => 'task'])->assertForbidden();
    $client = Client::create(['name' => 'Keep', 'type' => 'client', 'status' => 'active']);
    $this->actingAs($this->viewer)->delete("/clients/{$client->id}")->assertForbidden();
});

it('gives an Owner the same page the others are refused', function () {
    $owner = User::factory()->create();
    $owner->assignRole(Access::OWNER);

    $this->actingAs($owner)->get('/admin/users')->assertOk();
});
