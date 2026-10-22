<?php

namespace App\Http\Controllers\Books;

use App\Http\Controllers\Controller;
use App\Models\FxBatch;
use App\Services\Books;
use App\Services\Postings;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class FxBatchController extends Controller
{
    public function index(Request $request, Books $books): Response
    {
        $fifo = $books->fifo();

        $batches = FxBatch::orderBy('number')->get()->map(fn ($b) => [
            'id' => $b->id,
            'number' => $b->number,
            'date' => $b->purchased_on?->toDateString(),
            'description' => $b->description,
            'usd' => $b->usd,
            'bdt' => $b->bdt,
            'rate' => round($b->rate(), 2),
            'used' => $fifo['batches'][$b->id]['used'] ?? 0,
            'left' => $fifo['batches'][$b->id]['left'] ?? $b->usd,
            'notes' => $b->notes,
        ]);

        $usd = $batches->sum('usd');
        $bdt = $batches->sum('bdt');

        // Newest first in the table; the pool bar shows the latest batches in FIFO order.
        $newest = $batches->reverse()->values();
        $page = LengthAwarePaginator::resolveCurrentPage();

        return Inertia::render('books/FxBatches', [
            'batches' => new LengthAwarePaginator(
                $newest->forPage($page, 30)->values(), $newest->count(), 30, $page,
                ['path' => $request->url(), 'query' => $request->query()],
            ),
            'pool' => $batches->take(-12)->values(),
            'totals' => [
                'usd' => $usd,
                'bdt' => $bdt,
                'avg_rate' => $usd ? round($bdt / $usd, 2) : 0,
                'consumed' => $fifo['consumed'],
                'left' => $fifo['bought'] - $fifo['consumed'],
                'overdrawn' => $fifo['overdrawn'],
            ],
        ]);
    }

    public function update(Request $request, FxBatch $fx, Postings $postings): RedirectResponse
    {
        $data = $request->validate([
            'entry_date' => [$fx->purchased_on ? 'required' : 'nullable', 'date'],
            'description' => ['required', 'string', 'max:255'],
            'usd' => ['required', Money::RULE],
            'bdt' => ['required', Money::RULE],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $usd = Money::toMinor($data['usd']);
        $bdt = Money::toMinor($data['bdt']);

        if ($usd <= 0 || $bdt <= 0) {
            throw ValidationException::withMessages(['usd' => __('USD আর BDT দুটোই ০-এর বেশি হতে হবে।')]);
        }

        DB::transaction(function () use ($fx, $data, $usd, $bdt, $postings) {
            $fx->update([
                'purchased_on' => $data['entry_date'],
                'description' => $data['description'],
                'usd' => $usd,
                'bdt' => $bdt,
                'notes' => $data['notes'],
            ]);

            $postings->syncBatchEntries($fx);
        });

        $this->toast(__('Batch :n আপডেট হয়েছে — FIFO cost নতুন করে হিসাব হলো।', ['n' => $fx->number]));

        return back();
    }

    public function destroy(FxBatch $fx, Books $books, Postings $postings): RedirectResponse
    {
        if (($books->fifo()['batches'][$fx->id]['used'] ?? 0) > 0) {
            throw ValidationException::withMessages(['batch' => __('এই batch-এর USD খরচ হয়ে গেছে — মোছা যাবে না।')]);
        }

        $postings->deleteBatch($fx);
        $this->toast(__('Batch :n Trash-এ গেছে।', ['n' => $fx->number]));

        return back();
    }
}
