<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateRolePermissionsRequest;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Administración › Roles: role × permission matrix. Roles are created by
 * RolesAndPermissionsSeeder (creating or deleting roles is out of scope).
 */
class RoleController extends Controller
{
    public function index(): Response
    {
        $roles = Role::query()
            ->with('permissions:id,name')
            ->withCount('users')
            ->orderBy('id')
            ->get();

        return Inertia::render('Admin/Roles/Index', [
            'roles' => $roles->map(fn (Role $role) => [
                'id' => $role->id,
                'name' => $role->name,
                'users_count' => $role->users_count,
                'permissions' => $role->permissions->pluck('name')->sort()->values()->all(),
            ])->values()->all(),
            'permissions' => collect(Permission::catalog())
                ->map(fn (array $definition, string $name) => [
                    'name' => $name,
                    'label' => $definition['label'],
                    'module' => $definition['module'],
                    'exclusive' => $definition['exclusive'] || Permission::isExclusive($name),
                ])->values()->all(),
            'modules' => collect(config('modules', []))
                ->map(fn (array $module, string $key) => [
                    'key' => $key,
                    'name' => $module['name'],
                ])->values()->all(),
        ]);
    }

    public function update(UpdateRolePermissionsRequest $request, Role $role): RedirectResponse
    {
        $before = $role->permissions->pluck('name')->sort()->values()->all();
        $after = collect($request->permissionNames())->sort()->values()->all();

        DB::transaction(function () use ($request, $role, $before, $after) {
            $role->syncPermissions($after);

            activity()
                ->causedBy($request->user())
                ->performedOn($role)
                ->event('role_permissions_updated')
                ->withProperties([
                    'old' => ['permissions' => $before],
                    'attributes' => ['permissions' => $after],
                ])
                ->log('Permisos del rol actualizados');
        });

        return back()->with('success', 'Permisos del rol actualizados.');
    }
}
