<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BdtEntry;
use App\Models\Client;
use App\Models\Employee;
use App\Models\FxBatch;
use App\Models\UsdEntry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Nothing is ever hard-deleted from the books; deleted rows wait here.
 */
class TrashController extends Controller
{
    private const TYPES = [
        'usd' => [UsdEntry::class, 'USD খাতা'],
        'bdt' => [BdtEntry::class, 'BDT খাতা'],
        'fx' => [FxBatch::class, 'FX Batch'],
        'client' => [Client::class, 'Client'],
        'employee' => [Employee::class, 'কর্মী'],
    ];

    public function index(): Response
    {
        $items = collect(self::TYPES)->flatMap(function ($meta, $type) {
            [$model, $label] = $meta;

            return $model::onlyTrashed()->latest('deleted_at')->limit(100)->get()->map(fn ($row) => [
                'type' => $type,
                'type_label' => $label,
                'id' => $row->id,
                'title' => $row->description ?? $row->name,
                'amount' => $row->amount ?? $row->usd ?? null,
                'currency' => in_array($type, ['usd', 'fx']) ? 'usd' : 'bdt',
                'deleted_at' => $row->deleted_at->toIso8601String(),
            ]);
        })->sortByDesc('deleted_at')->values();

        return Inertia::render('admin/Trash', ['items' => $items]);
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

        $this->toast('ফেরত আনা হয়েছে।');

        return back();
    }
}
