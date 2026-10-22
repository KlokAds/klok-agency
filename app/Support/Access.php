<?php

namespace App\Support;

/**
 * The permission map. Each module gets its own set of actions and a
 * permission is named "{module}.{action}", e.g. "usd.create".
 */
class Access
{
    public const OWNER = 'Owner';

    public const MODULES = [
        'dashboard' => ['label' => 'Dashboard', 'actions' => ['view']],
        'usd' => ['label' => 'USD খাতা', 'actions' => ['view', 'create', 'edit', 'delete']],
        'bdt' => ['label' => 'BDT খাতা', 'actions' => ['view', 'create', 'edit', 'delete']],
        'fx' => ['label' => 'FX Batch', 'actions' => ['view', 'create', 'edit', 'delete']],
        'clients' => ['label' => 'Client', 'actions' => ['view', 'create', 'edit', 'delete']],
        'platforms' => ['label' => 'Ad Platform', 'actions' => ['view']],
        'payroll' => ['label' => 'Payroll', 'actions' => ['view', 'create', 'edit', 'delete']],
        'reports' => ['label' => 'Report / P&L', 'actions' => ['view', 'export']],
        'reconcile' => ['label' => 'Card মেলানো', 'actions' => ['view', 'create']],
        'trash' => ['label' => 'Trash', 'actions' => ['view', 'restore']],
        'activity' => ['label' => 'Activity log', 'actions' => ['view']],
        'users' => ['label' => 'User', 'actions' => ['view', 'create', 'edit', 'delete']],
        'roles' => ['label' => 'Role', 'actions' => ['view', 'create', 'edit', 'delete']],
        'settings' => ['label' => 'Settings', 'actions' => ['view', 'edit']],
    ];

    public const ACTIONS = [
        'view' => 'দেখা',
        'create' => 'নতুন',
        'edit' => 'বদলানো',
        'delete' => 'মোছা',
        'export' => 'Export',
        'restore' => 'ফেরত আনা',
    ];

    /** @return list<string> */
    public static function all(): array
    {
        $names = [];

        foreach (self::MODULES as $module => $meta) {
            foreach ($meta['actions'] as $action) {
                $names[] = "{$module}.{$action}";
            }
        }

        return $names;
    }
}
