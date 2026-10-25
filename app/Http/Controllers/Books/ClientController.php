<?php

namespace App\Http\Controllers\Books;

use App\Http\Controllers\Controller;
use App\Models\BdtEntry;
use App\Models\Campaign;
use App\Models\CampaignDay;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\PilotAccount;
use App\Models\Task;
use App\Models\UsdEntry;
use App\Models\User;
use App\Models\Wallet;
use App\Notifications\AgencyAlert;
use App\Services\AdAccountBalances;
use App\Services\Books;
use App\Services\CampaignBilling;
use App\Services\ClientNotifier;
use App\Services\PilotAccounts;
use App\Services\PilotClient;
use App\Support\Access;
use App\Support\InvoiceView;
use App\Support\Lookups;
use App\Support\Money;
use App\Support\Paging;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
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
            ->orderBy('name')
            ->paginate(Paging::size($request))
            ->withQueryString()
            ->through(fn (Client $c) => [
                'id' => $c->id,
                'name' => $c->name,
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
                'statuses' => Lookups::options(Client::STATUSES),
            ],
        ]);
    }

    public function show(Request $request, Client $client, Books $books, AdAccountBalances $balances): Response
    {
        $figures = collect($books->clients())->firstWhere('id', $client->id);
        // Of the ad spend: what Meta has charged our card (final) and what it has not yet.
        $figures = $figures ? [...$figures, 'meta' => $balances->client($client->id)] : null;

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

        $rows = $spend->concat($payments)->sortBy('sort')->values()->map(function ($row) {
            unset($row['sort']);

            return $row;
        });
        // Five a page, so a long history stays readable on a phone.
        $page = max(1, $request->integer('page', 1));
        $statement = new LengthAwarePaginator($rows->forPage($page, 5)->values(), $rows->count(), 5, $page, ['path' => $request->url(), 'query' => $request->query()]);

        $person = fn (User $u) => ['id' => $u->id, 'name' => $u->name, 'email' => $u->email, 'phone' => $u->phone, 'company' => $u->company, 'since' => $u->created_at?->format('j M Y')];

        return Inertia::render('clients/Show', [
            'client' => $client->only(['id', 'name', 'status', 'phone', 'email', 'company', 'notes', 'manager_id', 'renew_days']),
            // The client's own people on the portal, and sign-ups still waiting for a client.
            'portal' => [
                'users' => User::where('client_id', $client->id)->orderBy('name')->get()->map($person)->all(),
                'waiting' => User::role(Access::CLIENT)->whereNull('client_id')->latest()->limit(50)->get()->map($person)->all(),
            ],
            'figures' => $figures,
            'statement' => $statement,
            'campaigns' => Campaign::with('account:id,name')->where('client_id', $client->id)
                ->orderByRaw('case when last_spend_day is null then 1 else 0 end')->orderByDesc('last_spend_day')->limit(10)->get()
                ->map(fn (Campaign $c) => [
                    'id' => $c->id, 'name' => $c->name, 'live' => $c->isLive(), 'account' => $c->account?->name,
                    'spend_7d' => $c->spend_7d, 'bill_from' => $c->bill_from?->toDateString(),
                ]),
            'credit' => collect($books->clients())->firstWhere('id', $client->id),
            'budget_days' => $this->budgetDays($client),
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
                'wallets' => Lookups::wallets(),
            ],
        ]);
    }

    /**
     * The last 30 billed days of the client's campaigns: the budget each day
     * had (when known) against what was really spent, so a $2 budget that
     * spent $2.12 or $1.92 shows, and what that adds up to.
     *
     * @return array{days: list<array{day: string, budget: ?int, spend: int, diff: ?int}>, over: int, under: int}
     */
    private function budgetDays(Client $client): array
    {
        $campaigns = Campaign::where('client_id', $client->id)->whereNotNull('bill_from')->get(['id', 'bill_from']);
        $rows = CampaignDay::whereIn('campaign_id', $campaigns->pluck('id'))
            ->whereDate('day', '>=', now()->subDays(29)->toDateString())
            ->orderByDesc('day')->get()
            ->filter(fn (CampaignDay $d) => $d->day->gte($campaigns->firstWhere('id', $d->campaign_id)?->bill_from))
            ->groupBy(fn (CampaignDay $d) => $d->day->toDateString());

        $days = $rows->map(function ($group, $day) {
            // A day's budget is known only when every campaign that spent had one.
            $budget = $group->contains(fn (CampaignDay $d) => $d->budget === null) ? null : (int) $group->sum('budget');
            $spend = (int) $group->sum('spend');

            return ['day' => (string) $day, 'budget' => $budget, 'spend' => $spend, 'diff' => $budget === null ? null : $spend - $budget];
        })->values();

        return [
            'days' => array_values($days->all()),
            'over' => (int) $days->where('diff', '>', 0)->sum('diff'),
            'under' => (int) -$days->where('diff', '<', 0)->sum('diff'),
        ];
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

    /**
     * Gives the client back the advance their campaigns did not spend: the
     * taka it was paid at leaves a money place, and their credit is 0.
     */
    public function refund(Request $request, Client $client, Books $books, CampaignBilling $billing): RedirectResponse
    {
        $data = $request->validate([
            'entry_date' => ['required', 'date', 'before_or_equal:today'],
            'wallet' => ['required', Rule::in(Wallet::keys())],
            'note' => ['nullable', 'string', 'max:300'],
        ]);
        $figure = collect($books->clients())->firstWhere('id', $client->id);
        $left = (int) ($figure['credit_left'] ?? 0);
        $bdt = (int) ($figure['unearned'] ?? 0);

        if ($left <= 0 || $bdt <= 0) {
            throw ValidationException::withMessages(['refund' => __('ফেরত দেওয়ার মতো credit বাকি নেই।')]);
        }

        BdtEntry::create([
            'entry_date' => $data['entry_date'],
            'description' => $client->name.' — unspent ad credit refunded',
            'category' => 'client_refund',
            'client_id' => $client->id,
            'wallet' => $data['wallet'],
            'amount' => -$bdt,
            'usd_credit' => -$left,
            'notes' => $data['note'] ?? null,
        ]);
        $billing->fund($client->id);

        $this->toast(__(':client-কে :amount ফেরত লেখা হয়েছে — credit এখন ০।', ['client' => $client->name, 'amount' => Money::bdt($bdt)]));

        return back();
    }

    public function store(Request $request): RedirectResponse
    {
        Client::create($this->validated($request) + ['type' => 'client']);
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

    /** A portal sign-up is this client's person: they see its campaigns and invoices from now on. */
    public function linkPortalUser(Request $request, Client $client): RedirectResponse
    {
        $data = $request->validate(['user_id' => ['required', 'integer']]);
        $user = User::role(Access::CLIENT)->whereNull('client_id')->whereKey($data['user_id'])->firstOrFail();
        $user->forceFill(['client_id' => $client->id])->save();
        activity('Client')->performedOn($client)->causedBy($request->user())->log("portal user {$user->email} linked");
        rescue(fn () => $user->notify(new AgencyAlert('client_linked', ClientNotifier::LINKED_TITLE, 'এখন থেকে {client}-এর সব campaign, খরচ, invoice আর পেমেন্ট KlokOne-এ দেখতে পাবেন — phone-এ আর email-এও খবর পাবেন।', [ // i18n
            'client' => $client->name,
        ], ['url' => '/portal'])));
        $this->toast(__(':name এখন :client-এর portal দেখবেন।', ['name' => $user->name, 'client' => $client->name]));

        return back();
    }

    public function unlinkPortalUser(Request $request, Client $client, User $user): RedirectResponse
    {
        abort_unless($user->client_id === $client->id, 404);
        $user->forceFill(['client_id' => null])->save();
        activity('Client')->performedOn($client)->causedBy($request->user())->log("portal user {$user->email} unlinked");
        $this->toast(__(':name আর এই client-এর কিছু দেখবেন না।', ['name' => $user->name]));

        return back();
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?Client $client = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120', Rule::unique('clients', 'name')->ignore($client?->id)],
            'status' => ['required', Rule::in(array_keys(Client::STATUSES))],
            'phone' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:255'],
            'company' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'manager_id' => ['nullable', Rule::exists('users', 'id')->whereNull('client_id')],
            'renew_days' => ['nullable', 'integer', 'min:1', 'max:90'],
        ], [], ['name' => __('নাম')]);
    }
}
