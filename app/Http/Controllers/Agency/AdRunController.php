<?php

namespace App\Http\Controllers\Agency;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\AdRun;
use App\Models\User;
use App\Services\Agency;
use App\Support\ClientMessage;
use App\Support\Lookups;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Ads the agency runs for clients: how long each runs, when to ask for a
 * renewal, and how it did.
 */
class AdRunController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'view' => ['nullable', Rule::in(['running', 'ending', 'ended', 'all'])],
            'client_id' => ['nullable', 'integer'],
            'focus' => ['nullable', 'integer'],
        ]);
        $view = $filters['view'] ?? 'running';

        $runs = AdRun::with(['client:id,name,phone', 'account:id,name', 'assignee:id,name'])
            ->withCount(['tasks as open_tasks' => fn ($q) => $q->whereNull('done_at')])
            ->when($filters['client_id'] ?? null, fn ($q, $id) => $q->where('client_id', $id))
            ->when($view === 'running', fn ($q) => $q->where('status', 'running'))
            ->when($view === 'ending', fn ($q) => $q->where('status', 'running')->where('end_on', '<=', now()->addDays(2)->toDateString()))
            ->when($view === 'ended', fn ($q) => $q->whereIn('status', ['ended', 'renewed', 'stopped']))
            ->orderByRaw("case when status = 'running' then 0 else 1 end")
            ->orderBy('end_on')
            ->limit(300)
            ->get()
            ->map(fn (AdRun $run) => $this->present($run));

        return Inertia::render('agency/Runs', [
            'runs' => $runs,
            'filters' => ['view' => $view, 'client_id' => $filters['client_id'] ?? null, 'focus' => $filters['focus'] ?? null],
            'counts' => [
                'running' => AdRun::where('status', 'running')->count(),
                'ending' => AdRun::where('status', 'running')->where('end_on', '<=', now()->addDays(2)->toDateString())->count(),
            ],
            'options' => [
                'clients' => Lookups::clients(),
                'platforms' => Account::where('kind', 'platform')->where('is_active', true)->orderBy('sort')->get()
                    ->map(fn ($a) => ['value' => $a->id, 'label' => $a->name])->all(),
                'users' => User::visible()->where('is_active', true)->orderBy('name')->get(['id', 'name'])
                    ->map(fn ($u) => ['value' => $u->id, 'label' => $u->name])->all(),
            ],
        ]);
    }

    /** @return array<string, mixed> */
    private function present(AdRun $run): array
    {
        $total = max(1, $run->days);
        $done = min($total, max(0, (int) $run->start_on->startOfDay()->diffInDays(now()->startOfDay(), false) + 1));
        $links = ClientMessage::links($run->client?->phone, ClientMessage::renewal($run));

        return [
            'id' => $run->id,
            'title' => $run->title,
            'client' => $run->client?->name,
            'client_id' => $run->client_id,
            'platform' => $run->account?->name,
            'account_id' => $run->account_id,
            'budget_usd' => $run->budget_usd,
            'daily_usd' => $run->daily_usd,
            'days' => $run->days,
            'start_on' => $run->start_on->toDateString(),
            'end_on' => $run->end_on->toDateString(),
            'days_left' => $run->daysLeft(),
            'progress' => $run->status === 'running' ? round($done / $total * 100) : 100,
            'remind_days_before' => $run->remind_days_before,
            'status' => $run->status,
            'result' => $run->result,
            'results' => $run->results,
            'cost_per_result' => $run->cost_per_result,
            'result_note' => $run->result_note,
            'assigned_to' => $run->assigned_to,
            'assignee' => $run->assignee?->name,
            'notes' => $run->notes,
            'open_tasks' => $run->open_tasks ?? 0,
            'whatsapp' => $links['whatsapp'],
            'sms' => $links['sms'],
            'message' => $links['text'],
        ];
    }

    public function store(Request $request, Agency $agency): RedirectResponse
    {
        $run = AdRun::create($this->validated($request) + ['status' => 'running']);
        $agency->plan($run);
        $this->toast(__('Ad run যোগ হয়েছে — শেষ হওয়ার আগে renew-এর reminder আসবে।'));

        return back();
    }

    public function update(Request $request, AdRun $run, Agency $agency): RedirectResponse
    {
        $run->update($this->validated($request));
        $agency->plan($run);
        $this->toast(__('Ad run আপডেট হয়েছে।'));

        return back();
    }

    public function result(Request $request, AdRun $run, Agency $agency): RedirectResponse
    {
        $data = $request->validate([
            'result' => ['required', Rule::in(array_keys(AdRun::RESULTS))],
            'results' => ['nullable', 'integer', 'min:0', 'max:100000000'],
            'cost_per_result' => ['nullable', Money::RULE],
            'result_note' => ['nullable', 'string', 'max:2000'],
        ]);

        $data['cost_per_result'] = isset($data['cost_per_result']) ? Money::toMinor($data['cost_per_result']) : null;
        $agency->recordResult($run, $data);

        $this->toast(match ($run->result) {
            'good' => __('ফলাফল লেখা হলো — কাল scale করার reminder আসবে।'),
            'bad' => __('ফলাফল লেখা হলো — কাল ঠিক করার reminder আসবে।'),
            default => __('ফলাফল লেখা হলো।'),
        });

        return back();
    }

    public function renew(Request $request, AdRun $run, Agency $agency): RedirectResponse
    {
        $data = $this->schedule($request, $run->end_on->addDay()->toDateString());
        $agency->renew($run, $data + ['title' => $run->title, 'notes' => $request->input('notes')]);
        $this->toast(__('Renew হলো — নতুন ad run শুরু।'));

        return back();
    }

    public function stop(AdRun $run, Agency $agency): RedirectResponse
    {
        $run->update(['status' => 'stopped']);
        $agency->plan($run);
        $this->toast(__('Ad run বন্ধ করা হলো।'));

        return back();
    }

    public function destroy(AdRun $run): RedirectResponse
    {
        $run->tasks()->whereNull('done_at')->delete();
        $run->delete();
        $this->toast(__('Ad run মুছে ফেলা হয়েছে।'));

        return back();
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'client_id' => ['required', 'exists:clients,id'],
            'account_id' => ['required', Rule::exists('accounts', 'id')->where('kind', 'platform')],
            'title' => ['required', 'string', 'max:160'],
            'assigned_to' => ['nullable', 'exists:users,id'],
            'remind_days_before' => ['required', 'integer', 'min:0', 'max:7'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ], [], ['client_id' => 'client', 'account_id' => 'platform', 'title' => __('নাম')]);

        return $data + $this->schedule($request, now()->toDateString());
    }

    /**
     * Budget and how long it runs: days given, or worked out from the daily budget.
     *
     * @return array<string, mixed>
     */
    private function schedule(Request $request, string $defaultStart): array
    {
        $data = $request->validate([
            'budget_usd' => ['required', Money::RULE],
            'daily_usd' => ['nullable', Money::RULE],
            'days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'start_on' => ['nullable', 'date'],
        ], [], ['budget_usd' => 'Budget', 'daily_usd' => __('দৈনিক budget'), 'days' => __('কত দিন'), 'start_on' => __('শুরুর তারিখ')]);

        $budget = Money::toMinor($data['budget_usd']);
        $daily = isset($data['daily_usd']) && $data['daily_usd'] !== '' ? Money::toMinor($data['daily_usd']) : null;
        $days = $data['days'] ?? ($daily ? (int) ceil($budget / $daily) : null);

        if ($budget <= 0 || ! $days) {
            throw ValidationException::withMessages(['days' => __('কত দিন চলবে দিন, অথবা দৈনিক budget দিন।')]);
        }

        $start = CarbonImmutable::parse($data['start_on'] ?? $defaultStart);

        return [
            'budget_usd' => $budget,
            'daily_usd' => $daily ?? (int) round($budget / $days),
            'days' => $days,
            'start_on' => $start->toDateString(),
            'end_on' => $start->addDays($days - 1)->toDateString(),
        ];
    }
}
