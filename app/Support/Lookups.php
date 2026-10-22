<?php

namespace App\Support;

use App\Models\Account;
use App\Models\BdtEntry;
use App\Models\Client;
use App\Models\Employee;
use App\Models\ExpenseHead;
use App\Models\UsdEntry;

/**
 * Option lists the forms need, in the { value, label } shape the
 * custom select takes.
 */
class Lookups
{
    public static function clients(bool $activeOnly = false): array
    {
        return Client::query()
            ->when($activeOnly, fn ($q) => $q->where('status', 'active'))
            ->orderBy('name')
            ->get(['id', 'name', 'type', 'status'])
            ->map(fn ($c) => ['value' => $c->id, 'label' => $c->name, 'hint' => $c->status === 'inactive' ? 'Inactive' : null])
            ->all();
    }

    public static function accounts(?string $kind = null): array
    {
        return Account::query()
            ->where('is_active', true)
            ->when($kind, fn ($q) => $q->where('kind', $kind))
            ->orderBy('sort')
            ->get(['id', 'name', 'kind', 'code'])
            ->map(fn ($a) => ['value' => $a->id, 'label' => $a->name, 'kind' => $a->kind, 'code' => $a->code, 'hint' => Account::KINDS[$a->kind]])
            ->all();
    }

    public static function heads(): array
    {
        return ExpenseHead::where('is_active', true)->orderBy('name')->get(['id', 'name'])
            ->map(fn ($h) => ['value' => $h->id, 'label' => $h->name])->all();
    }

    public static function employees(): array
    {
        return Employee::where('status', 'active')->orderBy('name')->get(['id', 'name', 'designation'])
            ->map(fn ($e) => ['value' => $e->id, 'label' => $e->name, 'hint' => $e->designation])->all();
    }

    public static function usdCategories(): array
    {
        return collect(UsdEntry::CATEGORIES)->map(fn ($c, $k) => ['value' => $k, 'label' => __($c['label'])])->values()->all();
    }

    public static function bdtCategories(): array
    {
        return collect(BdtEntry::CATEGORIES)->map(fn ($c, $k) => ['value' => $k, 'label' => __($c['label'])])->values()->all();
    }

    public static function options(array $map): array
    {
        return collect($map)->map(fn ($label, $value) => ['value' => $value, 'label' => __($label)])->values()->all();
    }
}
