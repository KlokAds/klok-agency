<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

/**
 * বাংলা is the source language; English is a translation of it.
 * A signed-in user's choice wins, then the cookie (for the sign-in page).
 */
class SetLocale
{
    public const LOCALES = ['bn', 'en'];

    public function handle(Request $request, Closure $next): Response
    {
        $locale = $request->user()?->locale ?? $request->cookie('locale');

        App::setLocale(in_array($locale, self::LOCALES, true) ? $locale : 'bn');

        return $next($request);
    }
}
