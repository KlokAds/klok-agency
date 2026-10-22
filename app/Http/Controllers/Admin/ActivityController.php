<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Activitylog\Models\Activity;

class ActivityController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->only(['user_id', 'log']);

        $activities = Activity::with('causer')
            ->when($filters['user_id'] ?? null, fn ($q, $id) => $q->where('causer_id', $id)->where('causer_type', User::class))
            ->when($filters['log'] ?? null, fn ($q, $log) => $q->where('log_name', $log))
            ->latest('id')
            ->paginate(40)
            ->withQueryString()
            ->through(fn ($a) => [
                'id' => $a->id,
                'log' => $a->log_name,
                'event' => $a->event ?? $a->description,
                'subject_id' => $a->subject_id,
                'by' => $a->causer?->name ?? 'System',
                'changes' => $a->attribute_changes,
                'properties' => $a->properties,
                'at' => $a->created_at->toIso8601String(),
            ]);

        return Inertia::render('admin/Activity', [
            'activities' => $activities,
            'filters' => $filters,
            'users' => User::orderBy('name')->get(['id', 'name'])->map(fn ($u) => ['value' => $u->id, 'label' => $u->name]),
            'logs' => Activity::distinct()->orderBy('log_name')->pluck('log_name')->filter()->map(fn ($l) => ['value' => $l, 'label' => $l])->values(),
        ]);
    }
}
