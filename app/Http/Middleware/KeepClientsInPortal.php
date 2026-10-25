<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A client's own person signs in where everyone does, but only ever sees the portal: any other
 * page of the books sends them to /portal (a form or API call there is refused). Their own
 * account pages, the invoice and renewal links, the phone app's calls and signing out stay open.
 */
class KeepClientsInPortal
{
    /** Paths a client may use outside the portal. */
    private const OPEN = [
        'portal', 'portal/*', 'feedback', 'settings', 'settings/*', 'user/*', 'two-factor*', 'confirm-password', 'email/*',
        'logout', 'locale', 'i/*', 'r/*', 'app/device', 'app/latest', 'app/get', 'app/download', 'hooks/*', 'up',
        '.well-known/*', 'passkeys*', 'webauthn*',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || ! $user->isClient() || $request->is(...self::OPEN)) {
            return $next($request);
        }

        abort_unless($request->isMethod('GET') && ! $request->expectsJson(), 403);

        return redirect('/portal');
    }
}
