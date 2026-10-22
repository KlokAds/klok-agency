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
        'usd' => ['label' => 'USD খাতা', 'actions' => ['view', 'create', 'edit', 'delete']], // i18n
        'bdt' => ['label' => 'BDT খাতা', 'actions' => ['view', 'create', 'edit', 'delete']], // i18n
        'fx' => ['label' => 'FX Batch', 'actions' => ['view', 'create', 'edit', 'delete']],
        'clients' => ['label' => 'Client', 'actions' => ['view', 'create', 'edit', 'delete']],
        'platforms' => ['label' => 'Ad Platform', 'actions' => ['view']],
        'payroll' => ['label' => 'Payroll', 'actions' => ['view', 'create', 'edit', 'delete']],
        'reports' => ['label' => 'Report / P&L', 'actions' => ['view', 'export']],
        'reconcile' => ['label' => 'Card মেলানো', 'actions' => ['view', 'create']], // i18n
        'trash' => ['label' => 'Trash', 'actions' => ['view', 'restore']],
        'activity' => ['label' => 'Activity log', 'actions' => ['view']],
        'users' => ['label' => 'User', 'actions' => ['view', 'create', 'edit', 'delete']],
        'roles' => ['label' => 'Role', 'actions' => ['view', 'create', 'edit', 'delete']],
        'settings' => ['label' => 'Settings', 'actions' => ['view', 'edit']],
    ];

    public const ACTIONS = [
        'view' => 'দেখা', // i18n
        'create' => 'নতুন', // i18n
        'edit' => 'বদলানো', // i18n
        'delete' => 'মোছা', // i18n
        'export' => 'Export',
        'restore' => 'ফেরত আনা', // i18n
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
