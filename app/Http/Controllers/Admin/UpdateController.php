<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\SelfUpdater;
use App\Support\Access;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Settings → App update. Only an Owner, after re-entering the password,
 * can pull new code onto the live server.
 */
class UpdateController extends Controller
{
    public function status(Request $request, SelfUpdater $updater): JsonResponse
    {
        abort_unless($request->user()->hasRole(Access::OWNER), 403);

        return response()->json($updater->status());
    }

    public function update(Request $request, SelfUpdater $updater): RedirectResponse
    {
        abort_unless($request->user()->hasRole(Access::OWNER), 403);
        $request->validate(['password' => ['required', 'current_password']], [], ['password' => 'Password']);

        try {
            $result = $updater->update();
        } catch (RuntimeException $e) {
            $this->toast($e->getMessage(), 'error');

            return back();
        }

        $result['ok']
            ? $this->toast($result['from'] === $result['to'] ? __('App আগে থেকেই সর্বশেষ version-এ আছে।') : __('App update হয়েছে: :sha', ['sha' => substr((string) $result['to'], 0, 7)]))
            : $this->toast(__('Update হয়নি — নিচের log দেখুন।'), 'error');

        return back();
    }
}
