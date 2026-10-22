<?php

namespace App\Http\Controllers\Books;

use App\Http\Controllers\Controller;
use App\Models\BdtEntry;
use App\Models\UsdEntry;
use App\Services\Books;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Books $books): Response
    {
        $recentUsd = UsdEntry::with(['client', 'account'])->latest('id')->limit(6)->get()
            ->map(fn ($e) => [
                'id' => $e->id, 'book' => 'usd', 'date' => $e->entry_date?->toDateString(),
                'description' => $e->description, 'category' => UsdEntry::categoryLabel($e->category),
                'client' => $e->client?->name, 'amount' => $e->amount, 'status' => $e->status, 'at' => $e->created_at,
            ]);

        $recentBdt = BdtEntry::with('client')->latest('id')->limit(6)->get()
            ->map(fn ($e) => [
                'id' => $e->id, 'book' => 'bdt', 'date' => $e->entry_date?->toDateString(),
                'description' => $e->description, 'category' => BdtEntry::categoryLabel($e->category),
                'client' => $e->client?->name, 'amount' => $e->amount, 'status' => null, 'at' => $e->created_at,
            ]);

        $pending = UsdEntry::with('client')->where('category', 'client_spend')->where('status', 'pending')
            ->ledgerOrder()->get()
            ->map(fn ($e) => [
                'id' => $e->id, 'date' => $e->entry_date?->toDateString(), 'client' => $e->client?->name,
                'client_id' => $e->client_id, 'description' => $e->description, 'amount' => -$e->amount,
                'cost' => $books->costOf($e),
            ]);

        return Inertia::render('Dashboard', [
            'summary' => $books->summary(),
            'monthly' => $books->monthly(6),
            'clients' => collect($books->clients())->sortByDesc('margin')->values(),
            'pending' => $pending,
            'recent' => $recentUsd->concat($recentBdt)->sortByDesc('at')->take(8)->values(),
        ]);
    }
}
