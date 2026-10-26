<?php

namespace App\Http\Controllers\Books;

use App\Http\Controllers\Controller;
use App\Services\Books;
use Inertia\Inertia;
use Inertia\Response;

class PlatformController extends Controller
{
    public function __invoke(Books $books): Response
    {
        // The summary's rows: Meta's own "still to charge" where Pilot could read it, the book's elsewhere.
        $summary = $books->summary();
        $platforms = collect($books->platformsShown())->map(function ($platform) use ($books) {
            $platform['charges'] = $books->usdEntries()
                ->where('account_id', $platform['id'])
                ->where('category', 'card_charge')
                ->reverse()->take(8)->values()
                ->map(fn ($e) => [
                    'id' => $e->id, 'date' => $e->entry_date?->toDateString(),
                    'description' => $e->description, 'amount' => -$e->amount, 'notes' => $e->notes,
                ]);

            return $platform;
        });

        return Inertia::render('books/Platforms', [
            'platforms' => $platforms,
            'card' => $summary['card'],
        ]);
    }
}
