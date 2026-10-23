<?php

namespace App\Http\Controllers\Agency;

use App\Http\Controllers\Controller;
use App\Notifications\AgencyAlert;
use App\Support\Paging;
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
    /** Every notification, newest first: unread or read only, urgent only, and a search. */
    public function index(Request $request): Response
    {
        $show = in_array($request->query('show'), ['unread', 'urgent', 'read'], true) ? (string) $request->query('show') : 'all';
        $q = trim(mb_substr((string) $request->query('q', ''), 0, 80));

        $query = $request->user()->notifications()->latest()
            ->when($show === 'unread', fn ($w) => $w->whereNull('read_at'))
            ->when($show === 'read', fn ($w) => $w->whereNotNull('read_at'))
            ->when($show === 'urgent', fn ($w) => $w->where(function ($or) {
                foreach (AgencyAlert::URGENT as $kind) {
                    $or->orWhereRaw("data like ? escape '!'", ['%'.self::like('"kind":"'.$kind.'"').'%']);
                }
            }))
            // The text is kept as JSON, where Bangla is written as \u… codes: look for both forms.
            ->when($q !== '', fn ($w) => $w->where(fn ($or) => $or
                ->whereRaw("data like ? escape '!'", ['%'.self::like($q).'%'])
                ->orWhereRaw("data like ? escape '!'", ['%'.self::like(trim((string) json_encode($q), '"')).'%'])));

        return Inertia::render('agency/Notifications', [
            'notifications' => $query->paginate(Paging::size($request))->withQueryString()->through(fn ($n) => $this->present($n)),
            'filters' => ['show' => $show, 'q' => $q],
            'counts' => [
                'all' => $request->user()->notifications()->count(),
                'unread' => $request->user()->unreadNotifications()->count(),
            ],
        ]);
    }

    /** A search term for LIKE … ESCAPE '!': its own %, _ and ! taken literally. */
    private static function like(string $text): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $text);
    }

    /** Removes one of your own notifications. */
    public function destroy(Request $request, string $id): RedirectResponse|JsonResponse
    {
        $request->user()->notifications()->findOrFail($id)->delete();

        return $request->expectsJson() ? response()->json(['ok' => true]) : back();
    }

    /** Clears the ones already read; unread ones stay until they are seen. */
    public function clear(Request $request): RedirectResponse
    {
        $count = $request->user()->readNotifications()->delete();
        $this->toast(__(':n টা পড়া notification মুছে ফেলা হয়েছে।', ['n' => $count]));

        return back();
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
