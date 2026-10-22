<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Ad runs and tasks get their permissions; the starter roles that already
 * exist get sensible access (an install's own custom roles are left alone).
 */
return new class extends Migration
{
    private const NAMES = [
        'runs.view', 'runs.create', 'runs.edit', 'runs.delete',
        'tasks.view', 'tasks.create', 'tasks.edit', 'tasks.delete',
    ];

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (self::NAMES as $name) {
            Permission::findOrCreate($name, 'web');
        }

        Role::where('name', 'Accountant')->first()?->givePermissionTo(['runs.view', 'runs.create', 'runs.edit', 'tasks.view', 'tasks.create', 'tasks.edit']);
        Role::where('name', 'Viewer')->first()?->givePermissionTo(['runs.view', 'tasks.view']);
    }

    public function down(): void
    {
        Permission::whereIn('name', self::NAMES)->delete();
    }
};
