<?php

namespace Database\Seeders;

use App\Support\Access;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class AccessSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (Access::all() as $name) {
            Permission::findOrCreate($name, 'web');
        }

        // Owner passes every check through Gate::before, so it holds no rows.
        Role::findOrCreate(Access::OWNER, 'web');
        // Clients hold no permissions: the portal checks the link to their client instead.
        Role::findOrCreate(Access::CLIENT, 'web');

        Role::findOrCreate('Accountant', 'web')->syncPermissions([
            'dashboard.view',
            'usd.view', 'usd.create', 'usd.edit',
            'bdt.view', 'bdt.create', 'bdt.edit',
            'fx.view', 'fx.create',
            'clients.view', 'clients.create', 'clients.edit',
            'platforms.view',
            'payroll.view',
            'reports.view', 'reports.export',
            'reconcile.view', 'reconcile.create',
            'runs.view', 'runs.create', 'runs.edit',
            'tasks.view', 'tasks.create', 'tasks.edit',
        ]);

        Role::findOrCreate('Viewer', 'web')->syncPermissions([
            'dashboard.view', 'usd.view', 'bdt.view', 'fx.view', 'clients.view', 'platforms.view', 'reports.view',
            'runs.view', 'tasks.view',
        ]);
    }
}
