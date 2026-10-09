<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreRoleRequest;
use App\Http\Requests\Admin\UpdateRolesMatrixRequest;
use App\Models\Role;
use App\Support\RoleMatrix;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Administración › Roles y permisos (`Admin/Roles/Index`): the role ×
 * permission matrix (visible permissions only, see RoleMatrix) saved in one
 * PUT, and custom roles. Built-in roles come from RolesAndPermissionsSeeder;
 * roles are never renamed or deleted here.
 */
class RoleController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Admin/Roles/Index', [
            'roles' => RoleMatrix::adminRoles(),
            'permission_groups' => RoleMatrix::permissionGroups(),
            'vault' => ['holder_name' => RoleMatrix::vaultHolderName()],
            'reserved_modules' => RoleMatrix::reservedModules(),
        ]);
    }

    /**
     * Each listed role gets exactly the listed visible permissions; its
     * hidden permissions (managed by config) are kept. One activity entry
     * per changed role, with before/after.
     */
    public function update(UpdateRolesMatrixRequest $request): RedirectResponse
    {
        $visible = RoleMatrix::visiblePermissionNames();

        DB::transaction(function () use ($request, $visible) {
            foreach ($request->matrix() as $name => $permissions) {
                /** @var Role $role */
                $role = Role::findByName($name, 'web');
                $current = $role->permissions->pluck('name');
                $before = $current->sort()->values()->all();
                $after = $current->diff($visible)->merge($permissions)->unique()->sort()->values()->all();

                if ($before === $after) {
                    continue;
                }

                $role->syncPermissions($after);

                activity('users')
                    ->causedBy($request->user())
                    ->performedOn($role)
                    ->event('role_permissions_updated')
                    ->withProperties([
                        'old' => ['permissions' => $before],
                        'attributes' => ['permissions' => $after],
                    ])
                    ->log('Permisos del rol actualizados');
            }
        });

        return back()->with('success', 'Permisos guardados.');
    }

    /**
     * Custom role (`is_system = false`) named after its label, starting
     * with the visible permissions of `copy_from` (never
     * `ai.projects.approve`, which belongs to the project approval role).
     */
    public function store(StoreRoleRequest $request): RedirectResponse
    {
        $label = (string) $request->validated('label');
        $copyFrom = $request->validated('copy_from');

        DB::transaction(function () use ($request, $label, $copyFrom) {
            $permissions = $copyFrom === null
                ? []
                : Role::findByName($copyFrom, 'web')->permissions->pluck('name')
                    ->intersect(RoleMatrix::visiblePermissionNames())
                    ->reject(fn (string $name) => $name === 'ai.projects.approve')
                    ->sort()
                    ->values()
                    ->all();

            /** @var Role $role */
            $role = Role::create(['name' => $label, 'guard_name' => 'web']);
            $role->forceFill([
                'label' => $label,
                'scope' => $request->scopeKey(),
                'description' => Role::CUSTOM_DESCRIPTION,
                'requires_area' => false,
                'is_system' => false,
            ])->save();
            $role->syncPermissions($permissions);

            activity('users')
                ->causedBy($request->user())
                ->performedOn($role)
                ->event('role_created')
                ->withProperties([
                    'attributes' => ['label' => $label, 'scope' => $request->validated('scope'), 'permissions' => $permissions],
                    'copy_from' => $copyFrom,
                ])
                ->log('Rol creado');
        });

        return back()->with('success', "Rol {$label} agregado.");
    }
}
