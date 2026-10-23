<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Who is signed in where. Each person has one place at a time (signing in
 * anywhere ends their other session), so this shows one row per person,
 * and the Owner can end one by hand.
 */
class SessionController extends Controller
{
    /** Seen within this many minutes: "using it now". */
    private const NOW_MINUTES = 5;

    public function index(Request $request): Response
    {
        $users = User::query()->unless($request->user()->is_hidden, fn ($q) => $q->visible())->get(['id', 'name', 'email'])->keyBy('id');

        $sessions = DB::table((string) config('session.table', 'sessions'))
            ->whereNotNull('user_id')
            ->where('last_activity', '>=', now()->subDays(7)->getTimestamp())
            ->orderByDesc('last_activity')
            ->get(['id', 'user_id', 'ip_address', 'user_agent', 'last_activity'])
            ->filter(fn ($s) => $users->has($s->user_id))
            ->map(fn ($s) => [
                'id' => $s->id,
                'user' => $users[$s->user_id]->name,
                'email' => $users[$s->user_id]->email,
                'device' => self::device((string) $s->user_agent),
                'app' => str_contains((string) $s->user_agent, 'KlokFinanceApp'),
                'ip' => $s->ip_address,
                'last' => now()->setTimestamp((int) $s->last_activity)->toIso8601String(),
                'active' => (int) $s->last_activity >= now()->subMinutes(self::NOW_MINUTES)->getTimestamp(),
                'mine' => $s->id === $request->session()->getId(),
            ])->values();

        return Inertia::render('admin/Sessions', ['sessions' => $sessions]);
    }

    /** Ends that session; the device is at the sign-in page on its next click. */
    public function destroy(Request $request, string $session): RedirectResponse
    {
        abort_if($session === $request->session()->getId(), 422, __('নিজের এই session শেষ করতে Sign out করুন।'));

        $row = DB::table((string) config('session.table', 'sessions'))->where('id', $session)->first(['user_id']);
        abort_if($row === null, 404);

        $user = User::query()->whereKey((int) $row->user_id)->first();
        abort_if($user === null || ($user->is_hidden && ! $request->user()->is_hidden), 404);

        DB::table((string) config('session.table', 'sessions'))->where('id', $session)->delete();
        // Its "remember this device" cookie stops working too.
        $user->forceFill(['remember_token' => Str::random(60)])->saveQuietly();
        activity('Auth')->causedBy($request->user())->performedOn($user)->log('session ended by hand');
        $this->toast(__(':name-এর session শেষ করা হয়েছে।', ['name' => $user->name]));

        return back();
    }

    /** "Android app", "Chrome · Windows", "Safari · iPhone"… from the browser's own name. */
    public static function device(string $agent): string
    {
        if (str_contains($agent, 'KlokFinanceApp')) {
            preg_match('/Android [\d.]+;\s*([^;)]+)/', $agent, $m);

            return 'Android app'.(isset($m[1]) ? ' · '.trim(preg_replace('/ Build.*/', '', $m[1]) ?? '') : '');
        }

        $browser = match (true) {
            str_contains($agent, 'Edg/') => 'Edge',
            str_contains($agent, 'OPR/') => 'Opera',
            str_contains($agent, 'SamsungBrowser') => 'Samsung Internet',
            str_contains($agent, 'Firefox/') => 'Firefox',
            str_contains($agent, 'Chrome/') => 'Chrome',
            str_contains($agent, 'Safari/') => 'Safari',
            default => __('Browser'),
        };
        $system = match (true) {
            str_contains($agent, 'Android') => 'Android',
            str_contains($agent, 'iPhone') || str_contains($agent, 'iPad') => 'iPhone',
            str_contains($agent, 'Windows') => 'Windows',
            str_contains($agent, 'Mac OS') => 'Mac',
            str_contains($agent, 'Linux') => 'Linux',
            default => '',
        };

        return $browser.($system !== '' ? ' · '.$system : '');
    }
}
