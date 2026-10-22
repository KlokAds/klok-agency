<?php

namespace App\Http\Controllers\Books;

use App\Http\Controllers\Controller;
use App\Models\BdtEntry;
use App\Models\Client;
use App\Models\UsdEntry;
use App\Services\Books;
use App\Support\Lookups;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ClientController extends Controller
{
    public function index(Books $books): Response
    {
        $figures = collect($books->clients())->keyBy('id');

        $clients = Client::orderBy('name')->get()->map(fn ($c) => [
            'id' => $c->id,
            'name' => $c->name,
            'type' => $c->type,
            'status' => $c->status,
            'phone' => $c->phone,
            'email' => $c->email,
            'company' => $c->company,
            'notes' => $c->notes,
            'figures' => $figures[$c->id] ?? null,
        ]);

        return Inertia::render('clients/Index', [
            'clients' => $clients,
            'options' => [
                'types' => Lookups::options(Client::TYPES),
                'statuses' => Lookups::options(Client::STATUSES),
            ],
        ]);
    }

    public function show(Client $client, Books $books): Response
    {
        $figures = collect($books->clients())->firstWhere('id', $client->id);

        // One statement: spend in USD and payments in BDT, in date order.
        $spend = $books->usdEntries()->where('client_id', $client->id)->where('category', 'client_spend')
            ->map(fn ($e) => [
                'key' => 'u'.$e->id, 'id' => $e->id, 'book' => 'usd', 'date' => $e->entry_date?->toDateString(),
                'sort' => [$e->entry_date?->timestamp ?? 0, 0, $e->sequence],
                'description' => $e->description, 'account' => $e->account?->name,
                'usd' => -$e->amount, 'cost' => $books->costOf($e), 'bdt' => null, 'status' => $e->status,
            ]);

        $payments = $books->bdtEntries()->where('client_id', $client->id)->whereIn('category', BdtEntry::REVENUE)
            ->map(fn ($e) => [
                'key' => 'b'.$e->id, 'id' => $e->id, 'book' => 'bdt', 'date' => $e->entry_date?->toDateString(),
                'sort' => [$e->entry_date?->timestamp ?? 0, 1, $e->sequence],
                'description' => $e->description, 'account' => BdtEntry::categoryLabel($e->category),
                'usd' => null, 'cost' => null, 'bdt' => $e->amount, 'status' => null,
            ]);

        $statement = $spend->concat($payments)->sortBy('sort')->values()->map(function ($row) {
            unset($row['sort']);

            return $row;
        });

        return Inertia::render('clients/Show', [
            'client' => $client->only(['id', 'name', 'type', 'status', 'phone', 'email', 'company', 'notes']),
            'figures' => $figures,
            'statement' => $statement,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Client::create($this->validated($request));
        $this->toast(__('নতুন client যোগ হয়েছে।'));

        return back();
    }

    public function update(Request $request, Client $client): RedirectResponse
    {
        $client->update($this->validated($request, $client));
        $this->toast(__('Client আপডেট হয়েছে।'));

        return back();
    }

    public function destroy(Client $client): RedirectResponse
    {
        if (UsdEntry::where('client_id', $client->id)->exists() || BdtEntry::where('client_id', $client->id)->exists()) {
            throw ValidationException::withMessages(['client' => __('এই client-এর লেনদেন আছে — মোছা যাবে না। Inactive করে রাখুন।')]);
        }

        $client->delete();
        $this->toast(__('Client Trash-এ গেছে।'));

        return redirect()->route('clients.index');
    }

    private function validated(Request $request, ?Client $client = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120', Rule::unique('clients', 'name')->ignore($client?->id)],
            'type' => ['required', Rule::in(array_keys(Client::TYPES))],
            'status' => ['required', Rule::in(array_keys(Client::STATUSES))],
            'phone' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:255'],
            'company' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ], [], ['name' => __('নাম')]);
    }
}
