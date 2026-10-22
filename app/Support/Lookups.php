<?php

namespace App\Support;

use App\Models\Account;
use App\Models\BdtEntry;
use App\Models\Client;
use App\Models\Employee;
use App\Models\ExpenseHead;
use App\Models\UsdEntry;
use App\Models\Wallet;

/**
 * Option lists the forms need, in the { value, label } shape the
 * custom select takes.
 */
class Lookups
{
    /** @return array<int, array<string, mixed>> */
    public static function clients(bool $activeOnly = false): array
    {
        return Client::query()
            ->when($activeOnly, fn ($q) => $q->where('status', 'active'))
            ->orderBy('name')
            ->get(['id', 'name', 'type', 'status'])
            ->map(fn ($c) => ['value' => $c->id, 'label' => $c->name, 'hint' => $c->status === 'inactive' ? 'Inactive' : null])
            ->all();
    }

    public static function clientName(int $id): string
    {
        return (string) (Client::withTrashed()->whereKey($id)->value('name') ?? '#'.$id);
    }

    public static function headName(int $id): string
    {
        return (string) (ExpenseHead::whereKey($id)->value('name') ?? '#'.$id);
    }

    /** @return array<int, array<string, mixed>> */
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

    /** @return array<int, array<string, mixed>> */
    public static function heads(): array
    {
        return ExpenseHead::where('is_active', true)->orderBy('name')->get(['id', 'name'])
            ->map(fn ($h) => ['value' => $h->id, 'label' => $h->name])->all();
    }

    /** @return array<int, array<string, mixed>> */
    public static function employees(): array
    {
        return Employee::where('status', 'active')->orderBy('name')->get(['id', 'name', 'designation'])
            ->map(fn ($e) => ['value' => $e->id, 'label' => $e->name, 'hint' => $e->designation])->all();
    }

    /** @return array<int, array<string, mixed>> */
    public static function usdCategories(): array
    {
        return collect(UsdEntry::CATEGORIES)->map(fn ($c, $k) => ['value' => $k, 'label' => __($c['label'])])->values()->all();
    }

    /** @return array<int, array{value: string, label: string, hint: string|null}> */
    public static function wallets(): array
    {
        return Wallet::places()->where('is_active', true)
            ->map(fn (Wallet $w) => ['value' => $w->key, 'label' => $w->name, 'hint' => $w->detail])->values()->all();
    }

    /** @return array<int, array<string, mixed>> */
    public static function bdtCategories(): array
    {
        return collect(BdtEntry::CATEGORIES)->map(fn ($c, $k) => ['value' => $k, 'label' => __($c['label'])])->values()->all();
    }

    /**
     * @param  array<string, mixed>  $map
     * @return array<int, array<string, mixed>>
     */
    public static function options(array $map): array
    {
        return collect($map)->map(fn ($label, $value) => ['value' => $value, 'label' => __($label)])->values()->all();
    }
}
