<?php

namespace App\Http\Controllers\Agency;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The bell: each person sees only their own notifications.
 */
class NotificationController extends Controller
{
    public function index(Request $request): Response
    {
        $page = $request->user()->notifications()->latest()->paginate(30)->through(fn ($n) => $this->present($n));

        return Inertia::render('agency/Notifications', ['notifications' => $page]);
    }

    /** Latest few for the bell, plus the unread count (polled every minute). */
    public function feed(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'unread' => $user->unreadNotifications()->count(),
            'items' => $user->notifications()->latest()->limit(12)->get()->map(fn ($n) => $this->present($n)),
        ]);
    }

    public function read(Request $request, string $id): RedirectResponse|JsonResponse
    {
        $notification = $request->user()->notifications()->findOrFail($id);
        $notification->markAsRead();

        return $request->expectsJson() ? response()->json(['ok' => true]) : back();
    }

    public function readAll(Request $request): RedirectResponse
    {
        $request->user()->unreadNotifications->markAsRead();

        return back();
    }

    /** @return array<string, mixed> */
    private function present(DatabaseNotification $n): array
    {
        return [
            'id' => $n->id,
            'kind' => $n->data['kind'] ?? 'info',
            'title' => $n->data['title'] ?? '',
            'body' => $n->data['body'] ?? null,
            'params' => $n->data['params'] ?? [],
            'url' => $n->data['url'] ?? null,
            'whatsapp' => $n->data['whatsapp'] ?? null,
            'sms' => $n->data['sms'] ?? null,
            'summary' => $n->data['summary'] ?? null,
            'read' => $n->read_at !== null,
            'at' => $n->created_at->toIso8601String(),
        ];
    }
}
