<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BdtEntry;
use App\Models\Client;
use App\Models\Employee;
use App\Models\FxBatch;
use App\Models\UsdEntry;
use App\Support\Paging;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Nothing is ever hard-deleted from the books; deleted rows wait here.
 */
class TrashController extends Controller
{
    private const TYPES = [
        'usd' => [UsdEntry::class, 'USD খাতা'], // i18n
        'bdt' => [BdtEntry::class, 'BDT খাতা'], // i18n
        'fx' => [FxBatch::class, 'FX Batch'],
        'client' => [Client::class, 'Client'],
        'employee' => [Employee::class, 'কর্মী'], // i18n
    ];

    public function index(Request $request): Response
    {
        $filters = $request->validate(['type' => ['nullable', Rule::in(array_keys(self::TYPES))]]);
        $types = isset($filters['type']) ? [$filters['type'] => self::TYPES[$filters['type']]] : self::TYPES;

        $items = collect($types)->flatMap(function (array $meta, string $type): Collection {
            [$model, $label] = $meta;

            return $model::onlyTrashed()->latest('deleted_at')->get()->map(fn ($row) => [
                'type' => $type,
                'type_label' => __($label),
                'id' => $row->id,
                'title' => $row->description ?? $row->name,
                'amount' => $row->amount ?? $row->usd ?? null,
                'currency' => in_array($type, ['usd', 'fx']) ? 'usd' : 'bdt',
                'deleted_at' => $row->deleted_at->toIso8601String(),
            ]);
        })->sortByDesc('deleted_at')->values();

        $page = LengthAwarePaginator::resolveCurrentPage();

        return Inertia::render('admin/Trash', [
            'items' => new LengthAwarePaginator(
                $items->forPage($page, Paging::size($request))->values(), $items->count(), Paging::size($request), $page,
                ['path' => $request->url(), 'query' => $request->query()],
            ),
            'filters' => ['type' => $filters['type'] ?? null],
            'types' => collect(self::TYPES)->map(fn (array $meta, string $type) => ['value' => $type, 'label' => __($meta[1])])->values(),
        ]);
    }

    public function restore(string $type, int $id): RedirectResponse
    {
        abort_unless(isset(self::TYPES[$type]), 404);

        [$model] = self::TYPES[$type];
        $row = $model::onlyTrashed()->findOrFail($id);

        DB::transaction(function () use ($row) {
            $row->restore();

            // Transfers and FX batches come back whole.
            if ($row instanceof UsdEntry && $row->pair_key) {
                UsdEntry::onlyTrashed()->where('pair_key', $row->pair_key)->get()->each->restore();
            }

            if ($row instanceof FxBatch) {
                UsdEntry::onlyTrashed()->where('fx_batch_id', $row->id)->get()->each->restore();
                BdtEntry::onlyTrashed()->where('fx_batch_id', $row->id)->get()->each->restore();
            }
        });

        $this->toast(__('ফেরত আনা হয়েছে।'));

        return back();
    }
}
