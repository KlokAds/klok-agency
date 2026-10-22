<?php

namespace App\Http\Middleware;

use App\Models\Setting;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Runs on every signed-in request:
 *  - a deactivated account is signed out at once;
 *  - a new or reset password must be changed before anything else;
 *  - when the owner requires it, 2FA must be on before the books open.
 */
class EnsureAccountIsUsable
{
    /** Routes a user can still reach while being asked to fix their account. */
    private const ALLOWED = [
        'security.edit', 'user-password.update', 'logout', 'password.confirm', 'password.confirmation',
        'password.confirm.store', 'two-factor.enable', 'two-factor.confirm', 'two-factor.disable',
        'two-factor.qr-code', 'two-factor.secret-key', 'two-factor.recovery-codes', 'two-factor.regenerate-recovery-codes',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return $next($request);
        }

        if (! $user->is_active) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->with('status', 'এই account বন্ধ করা আছে। Owner-এর সাথে যোগাযোগ করুন।');
        }

        if ($request->routeIs(...self::ALLOWED)) {
            return $next($request);
        }

        if ($user->must_change_password) {
            return redirect()->route('security.edit')->with('notice', 'password');
        }

        if (Setting::get('require_2fa') === '1' && ! $user->two_factor_confirmed_at) {
            return redirect()->route('security.edit')->with('notice', 'two_factor');
        }

        return $next($request);
    }
}
