<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\Access;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Role;

class RoleController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('admin/Roles', [
            'roles' => Role::with('permissions:id,name')->withCount('users')->orderBy('id')->get()->map(fn ($r) => [
                'id' => $r->id,
                'name' => $r->name,
                'is_owner' => $r->name === Access::OWNER,
                'users' => $r->users_count,
                'permissions' => $r->permissions->pluck('name'),
            ]),
            'modules' => collect(Access::MODULES)->map(fn ($m, $key) => ['key' => $key] + $m)->values(),
            'actions' => Access::ACTIONS,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        Role::create(['name' => $data['name'], 'guard_name' => 'web'])->syncPermissions($data['permissions']);
        $this->toast("\"{$data['name']}\" role তৈরি হয়েছে।");

        return back();
    }

    public function update(Request $request, Role $role): RedirectResponse
    {
        $this->guardOwner($role);
        $data = $this->validated($request, $role);

        $role->update(['name' => $data['name']]);
        $role->syncPermissions($data['permissions']);
        activity('Role')->performedOn($role)->causedBy($request->user())
            ->withProperties(['permissions' => $data['permissions']])->log('permissions updated');

        $this->toast('Role আপডেট হয়েছে।');

        return back();
    }

    public function destroy(Role $role): RedirectResponse
    {
        $this->guardOwner($role);

        if ($role->users()->exists()) {
            throw ValidationException::withMessages(['role' => 'এই role-এ user আছে — আগে তাদের অন্য role দিন।']);
        }

        $role->delete();
        $this->toast('Role মুছে ফেলা হয়েছে।');

        return back();
    }

    private function guardOwner(Role $role): void
    {
        if ($role->name === Access::OWNER) {
            throw ValidationException::withMessages(['role' => 'Owner role বদলানো বা মোছা যায় না।']);
        }
    }

    private function validated(Request $request, ?Role $role = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:60', Rule::notIn([Access::OWNER]), Rule::unique('roles', 'name')->ignore($role?->id)],
            'permissions' => ['array'],
            'permissions.*' => [Rule::in(Access::all())],
        ], [], ['name' => 'Role-এর নাম']);
    }
}
