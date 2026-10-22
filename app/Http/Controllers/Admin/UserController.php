<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Access;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Role;

/**
 * There is no public sign-up: people get an account only from here.
 */
class UserController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('admin/Users', [
            'users' => User::with('roles:id,name')->unless($request->user()->is_hidden, fn ($q) => $q->visible())->orderBy('name')->get()->map(fn ($u) => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'role' => $u->roles->first()?->name,
                'is_active' => $u->is_active,
                'two_factor' => $u->two_factor_confirmed_at !== null,
                'must_change_password' => $u->must_change_password,
                'last_login_at' => $u->last_login_at?->toIso8601String(),
                'last_login_ip' => $u->last_login_ip,
            ]),
            'roles' => Role::orderBy('name')->pluck('name')->map(fn ($r) => ['value' => $r, 'label' => $r]),
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'role' => ['required', 'exists:roles,name'],
            'password' => ['required', Password::defaults()],
        ]);

        $this->guardOwnerRole($request, $data['role']);

        DB::transaction(function () use ($data) {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $data['password'],
                'email_verified_at' => now(),
                'must_change_password' => true,
            ]);

            $user->syncRoles([$data['role']]);
        });

        $this->toast(__(':name-এর account তৈরি হয়েছে। প্রথম login-এ password বদলাতে হবে।', ['name' => $data['name']]));

        return back();
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'role' => ['required', 'exists:roles,name'],
            'is_active' => ['required', 'boolean'],
            'password' => ['nullable', Password::defaults()],
        ]);

        $this->guardHidden($request, $user);
        $this->guardOwnerRole($request, $data['role'], $user);

        if ($user->is($request->user()) && (! $data['is_active'] || $data['role'] !== $user->roles->first()?->name)) {
            throw ValidationException::withMessages(['role' => __('নিজের role বা access নিজে বদলানো যায় না।')]);
        }

        $this->keepOneOwner($user, $data['role'], $data['is_active']);

        DB::transaction(function () use ($user, $data) {
            $user->update([
                'name' => $data['name'],
                'email' => $data['email'],
                'is_active' => $data['is_active'],
            ]);

            if (! empty($data['password'])) {
                $user->update(['password' => $data['password'], 'must_change_password' => true]);
            }

            $user->syncRoles([$data['role']]);

            if (! $data['is_active'] || ! empty($data['password'])) {
                DB::table('sessions')->where('user_id', $user->id)->delete();
            }
        });

        $this->toast(__('User আপডেট হয়েছে।'));

        return back();
    }

    public function resetTwoFactor(Request $request, User $user): RedirectResponse
    {
        $this->guardHidden($request, $user);

        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();

        activity('User')->performedOn($user)->causedBy($request->user())->log('2FA reset');
        $this->toast(__(':name-এর 2FA বন্ধ করা হলো — আবার চালু করতে হবে।', ['name' => $user->name]));

        return back();
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        $this->guardHidden($request, $user);

        if ($user->is($request->user())) {
            throw ValidationException::withMessages(['user' => __('নিজের account নিজে মোছা যায় না।')]);
        }

        $this->keepOneOwner($user, null, false);

        DB::table('sessions')->where('user_id', $user->id)->delete();
        $user->delete();
        $this->toast(__('User মুছে ফেলা হয়েছে।'));

        return back();
    }

    /** A hidden account is not there for anyone who is not hidden themselves. */
    private function guardHidden(Request $request, User $user): void
    {
        abort_if($user->is_hidden && ! $request->user()->is_hidden, 404);
    }

    /** Only an Owner can hand out, or take away, the Owner role. */
    private function guardOwnerRole(Request $request, string $role, ?User $user = null): void
    {
        $touchesOwner = $role === Access::OWNER || $user?->hasRole(Access::OWNER);

        if ($touchesOwner && ! $request->user()->hasRole(Access::OWNER)) {
            throw ValidationException::withMessages(['role' => __('Owner role শুধু Owner দিতে বা বদলাতে পারে।')]);
        }
    }

    private function keepOneOwner(User $user, ?string $newRole, bool $active): void
    {
        if (! $user->hasRole(Access::OWNER) || ($newRole === Access::OWNER && $active)) {
            return;
        }

        // A hidden super admin does not count: the agency must keep an Owner it can see.
        $others = User::role(Access::OWNER)->visible()->where('is_active', true)->whereKeyNot($user->id)->count();

        if ($others === 0) {
            throw ValidationException::withMessages(['role' => __('অন্তত একজন সক্রিয় Owner থাকতে হবে।')]);
        }
    }
}
