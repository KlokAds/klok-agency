<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * Locks the browser down: scripts only from this site (plus a per-request
 * nonce), no framing, no sniffing, no referrer leaks, no camera/mic/etc.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $nonce = Vite::useCspNonce();

        $response = $next($request);

        $dev = app()->isLocal() && is_file(public_path('hot'));
        $hot = $dev ? file_get_contents(public_path('hot')) : false;
        $devHost = $hot !== false ? trim($hot) : '';
        $devWs = $dev ? preg_replace('#^http#', 'ws', $devHost) : '';

        $csp = implode('; ', array_filter([
            "default-src 'self'",
            "script-src 'self' 'nonce-{$nonce}' {$devHost}",
            "style-src 'self' 'unsafe-inline' {$devHost}",
            "img-src 'self' data: blob:",
            "font-src 'self' data: {$devHost}",
            "connect-src 'self' {$devHost} {$devWs}",
            "frame-ancestors 'none'",
            "form-action 'self'",
            "base-uri 'self'",
            "object-src 'none'",
            app()->isProduction() ? 'upgrade-insecure-requests' : null,
        ]));

        $response->headers->set('Content-Security-Policy', $csp);
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'same-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=(), usb=()');
        $response->headers->remove('X-Powered-By');
        // PHP adds its own X-Powered-By (expose_php) outside the response; take that one out too.
        // The sure fix is expose_php = Off in the server's PHP settings.
        if (! headers_sent()) {
            header_remove('X-Powered-By');
        }

        if ($request->isSecure()) {
            $response->headers->set('Cross-Origin-Opener-Policy', 'same-origin');
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        // Financial pages must not sit in shared caches or the back/forward cache.
        // The offline worker (public/sw.js) keeps a page only for this user, and drops the
        // kept pages when another user signs in or the answer comes back signed out.
        if ($request->user()) {
            $response->headers->set('Cache-Control', 'no-store, private');
            $response->headers->set('X-Kept-For', (string) $request->user()->getAuthIdentifier());
        }

        return $response;
    }
}
