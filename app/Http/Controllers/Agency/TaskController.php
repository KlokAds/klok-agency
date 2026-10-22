<?php

namespace App\Http\Controllers\Agency;

use App\Http\Controllers\Controller;
use App\Models\Task;
use App\Models\User;
use App\Support\Lookups;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The agency's to-dos, client meetings and follow-ups, together with the
 * reminders the system makes from ad runs.
 */
class TaskController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'view' => ['nullable', Rule::in(['open', 'today', 'done'])],
            'who' => ['nullable', Rule::in(['mine', 'all'])],
            'client_id' => ['nullable', 'integer'],
            'focus' => ['nullable', 'integer'],
        ]);
        $view = $filters['view'] ?? 'open';
        $who = $filters['who'] ?? 'all';
        $me = $request->user()->id;

        $tasks = Task::with(['client:id,name', 'assignee:id,name', 'adRun:id,title'])
            ->when($who === 'mine', fn ($q) => $q->where('assigned_to', $me))
            ->when($filters['client_id'] ?? null, fn ($q, $id) => $q->where('client_id', $id))
            ->when($view === 'open', fn ($q) => $q->whereNull('done_at'))
            ->when($view === 'today', fn ($q) => $q->whereNull('done_at')->where('due_at', '<=', now()->endOfDay()))
            ->when($view === 'done', fn ($q) => $q->whereNotNull('done_at')->latest('done_at'))
            ->orderByRaw('case when due_at is null then 1 else 0 end')
            ->orderBy('due_at')
            ->limit(300)
            ->get()
            ->map(fn (Task $t) => [
                'id' => $t->id,
                'title' => $t->title,
                'notes' => $t->notes,
                'kind' => $t->kind,
                'priority' => $t->priority,
                'client' => $t->client?->name,
                'client_id' => $t->client_id,
                'ad_run' => $t->adRun?->title,
                'ad_run_id' => $t->ad_run_id,
                'assigned_to' => $t->assigned_to,
                'assignee' => $t->assignee?->name,
                'due_at' => $t->due_at?->format('Y-m-d\TH:i'),
                'remind_at' => $t->remind_at?->format('Y-m-d\TH:i'),
                'repeat' => $t->repeat,
                'done_at' => $t->done_at?->toIso8601String(),
                'overdue' => ! $t->done_at && $t->due_at && $t->due_at->isPast(),
                'auto' => in_array($t->kind, ['renewal', 'result', 'scale'], true),
            ]);

        return Inertia::render('agency/Tasks', [
            'tasks' => $tasks,
            'filters' => ['view' => $view, 'who' => $who, 'client_id' => $filters['client_id'] ?? null, 'focus' => $filters['focus'] ?? null],
            'counts' => [
                'open' => Task::open()->count(),
                'today' => Task::open()->where('due_at', '<=', now()->endOfDay())->count(),
                'mine' => Task::open()->where('assigned_to', $me)->count(),
            ],
            'options' => [
                'clients' => Lookups::clients(),
                'users' => User::where('is_active', true)->orderBy('name')->get(['id', 'name'])->map(fn ($u) => ['value' => $u->id, 'label' => $u->name])->all(),
                'kinds' => Lookups::options(array_intersect_key(Task::KINDS, array_flip(['task', 'meeting', 'follow_up']))),
                'repeats' => Lookups::options(Task::REPEATS),
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $task = Task::create($this->validated($request));
        $this->toast($task->remind_at ? __('কাজ যোগ হয়েছে — সময়মতো reminder আসবে।') : __('কাজ যোগ হয়েছে।'));

        return back();
    }

    public function update(Request $request, Task $task): RedirectResponse
    {
        $task->update($this->validated($request) + ['notified_at' => null]);
        $this->toast(__('কাজ আপডেট হয়েছে।'));

        return back();
    }

    /** Ticking a task off; a repeating one comes back for its next time. */
    public function toggle(Request $request, Task $task): RedirectResponse
    {
        DB::transaction(function () use ($request, $task) {
            if ($task->done_at) {
                $task->update(['done_at' => null, 'done_by' => null]);

                return;
            }

            $task->update(['done_at' => now(), 'done_by' => $request->user()->id]);

            if ($task->repeat !== 'none') {
                $shift = fn (?CarbonImmutable $d) => $d ? match ($task->repeat) {
                    'daily' => $d->addDay(),
                    'weekly' => $d->addWeek(),
                    'monthly' => $d->addMonthNoOverflow(),
                } : null;

                Task::create($task->only(['title', 'notes', 'kind', 'priority', 'client_id', 'assigned_to', 'repeat']) + [
                    'due_at' => $shift($task->due_at),
                    'remind_at' => $shift($task->remind_at),
                ]);
            }
        });

        $this->toast($task->done_at ? __('কাজ শেষ ✓') : __('কাজ আবার খোলা হলো।'));

        return back();
    }

    public function destroy(Task $task): RedirectResponse
    {
        $task->delete();
        $this->toast(__('কাজ মুছে ফেলা হয়েছে।'));

        return back();
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'kind' => ['required', Rule::in(array_keys(Task::KINDS))],
            'priority' => ['required', Rule::in(['normal', 'high'])],
            'client_id' => ['nullable', 'exists:clients,id'],
            'assigned_to' => ['nullable', 'exists:users,id'],
            'due_at' => ['nullable', 'date'],
            'remind_at' => ['nullable', 'date'],
            'repeat' => ['required', Rule::in(array_keys(Task::REPEATS))],
        ], [], ['title' => __('কাজ'), 'due_at' => __('কবে'), 'remind_at' => 'Reminder']);

        // Without its own reminder time, a dated task reminds at its due time.
        $data['remind_at'] ??= $data['due_at'] ?? null;

        return $data;
    }
}
