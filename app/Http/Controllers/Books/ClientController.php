<?php

namespace App\Http\Controllers\Books;

use App\Http\Controllers\Controller;
use App\Models\BdtEntry;
use App\Models\Campaign;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\PilotAccount;
use App\Models\Task;
use App\Models\UsdEntry;
use App\Models\User;
use App\Services\Books;
use App\Services\PilotAccounts;
use App\Services\PilotClient;
use App\Support\InvoiceView;
use App\Support\Lookups;
use App\Support\Paging;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ClientController extends Controller
{
    public function index(Request $request, Books $books): Response
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in([...array_keys(Client::STATUSES), 'all'])],
            'type' => ['nullable', Rule::in(array_keys(Client::TYPES))],
        ]);
        $filters['status'] ??= 'active';
        $figures = collect($books->clients())->keyBy('id');
        // The cards add up every client, not just this page (deleted ones left out).
        $live = $figures->only(Client::pluck('id')->all());

        $clients = Client::query()
            ->when($filters['q'] ?? null, fn ($q, $term) => $q->where(fn ($q) => $q
                ->where('name', 'like', "%{$term}%")
                ->orWhere('company', 'like', "%{$term}%")
                ->orWhere('notes', 'like', "%{$term}%")))
            ->when($filters['status'] !== 'all', fn ($q) => $q->where('status', $filters['status']))
            ->when($filters['type'] ?? null, fn ($q, $type) => $q->where('type', $type))
            ->orderBy('name')
            ->paginate(Paging::size($request))
            ->withQueryString()
            ->through(fn (Client $c) => [
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
            'filters' => $filters,
            'totals' => [
                'margin' => $live->sum('margin'),
                'spent' => $live->sum('usd_settled'),
                'pending' => $live->sum('usd_pending'),
            ],
            'options' => [
                'types' => Lookups::options(Client::TYPES),
                'statuses' => Lookups::options(Client::STATUSES),
            ],
        ]);
    }

    public function show(Request $request, Client $client, Books $books): Response
    {
        $figures = collect($books->clients())->firstWhere('id', $client->id);

        // One statement: spend in USD and payments in BDT, in date order.
        $spend = $books->usdEntries()->where('client_id', $client->id)->where('category', 'client_spend')
            ->map(fn ($e) => [
                'key' => 'u'.$e->id, 'id' => $e->id, 'book' => 'usd', 'date' => $e->entry_date?->toDateString(),
                'sort' => [$e->entry_date->timestamp ?? 0, 0, $e->sequence],
                'description' => $e->description, 'account' => $e->account?->name,
                'usd' => -$e->amount, 'cost' => $books->costOf($e), 'bdt' => null, 'status' => $e->status,
            ]);

        $payments = $books->bdtEntries()->where('client_id', $client->id)->whereIn('category', BdtEntry::REVENUE)
            ->map(fn ($e) => [
                'key' => 'b'.$e->id, 'id' => $e->id, 'book' => 'bdt', 'date' => $e->entry_date?->toDateString(),
                'sort' => [$e->entry_date->timestamp ?? 0, 1, $e->sequence],
                'description' => $e->description, 'account' => BdtEntry::categoryLabel($e->category),
                'usd' => null, 'cost' => null, 'bdt' => $e->amount, 'status' => null,
            ]);

        $statement = $spend->concat($payments)->sortBy('sort')->values()->map(function ($row) {
            unset($row['sort']);

            return $row;
        });

        return Inertia::render('clients/Show', [
            'client' => $client->only(['id', 'name', 'type', 'status', 'phone', 'email', 'company', 'notes', 'manager_id']),
            'figures' => $figures,
            'statement' => $statement,
            'campaigns' => Campaign::with('account:id,name')->where('client_id', $client->id)
                ->orderByRaw('case when last_spend_day is null then 1 else 0 end')->orderByDesc('last_spend_day')->limit(10)->get()
                ->map(fn (Campaign $c) => [
                    'id' => $c->id, 'name' => $c->name, 'live' => $c->isLive(), 'account' => $c->account?->name,
                    'spend_7d' => $c->spend_7d, 'bill_from' => $c->bill_from?->toDateString(),
                ]),
            'credit' => collect($books->clients())->firstWhere('id', $client->id),
            'tasks' => Task::with('assignee:id,name')->where('client_id', $client->id)->whereNull('done_at')->orderBy('due_at')->limit(10)->get()
                ->map(fn (Task $t) => [
                    'id' => $t->id, 'title' => $t->title, 'kind' => $t->kind, 'due_at' => $t->due_at?->format('Y-m-d\TH:i'),
                    'assignee' => $t->assignee?->name, 'overdue' => $t->due_at !== null && $t->due_at->isPast(),
                ]),
            'invoices' => $client->invoices()->with('client:id,name')->latest('seq')->limit(8)->get()
                ->map(fn (Invoice $i) => InvoiceView::row($i)),
            'pilot' => $this->pilotReports($client, $request->integer('pilot_days', 7)),
            'options' => [
                'users' => User::visible()->where('is_active', true)->orderBy('name')->get(['id', 'name'])->map(fn ($u) => ['value' => $u->id, 'label' => $u->name])->all(),
            ],
        ]);
    }

    /**
     * Numbers from Klok Pilot for each ad account linked to this client.
     *
     * @return array{days: int, accounts: array<int, array<string, mixed>>}|null
     */
    private function pilotReports(Client $client, int $days): ?array
    {
        $accounts = $client->pilotAccounts()->orderBy('name')->get();

        if ($accounts->isEmpty() || ! PilotClient::connected()) {
            return null;
        }

        $pilot = app(PilotAccounts::class);
        $days = in_array($days, PilotAccounts::DAYS, true) ? $days : 7;

        return [
            'days' => $days,
            'accounts' => $accounts->map(fn (PilotAccount $a) => [
                'id' => $a->id, 'name' => $a->name, 'external_id' => $a->external_id,
                ...$pilot->report($a, $days),
            ])->values()->all(),
        ];
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

    /** @return array<string, mixed> */
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
            'manager_id' => ['nullable', 'exists:users,id'],
        ], [], ['name' => __('নাম')]);
    }
}
