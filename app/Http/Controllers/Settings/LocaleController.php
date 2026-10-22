<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Middleware\SetLocale;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class LocaleController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $data = $request->validate(['locale' => ['required', Rule::in(SetLocale::LOCALES)]]);

        $request->user()?->update(['locale' => $data['locale']]);

        return back()->withCookie(cookie()->forever('locale', $data['locale']));
    }
}
