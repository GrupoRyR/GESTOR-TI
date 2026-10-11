<?php

namespace App\Support;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Role metadata and the role × permission matrix of Administración › Roles
 * y permisos (config/permissions.php `role_metadata`, `scopes`, `matrix`,
 * `project_approval_role`). Also used by › Usuarios (`role_options`,
 * `vault_holder_name`).
 */
class RoleMatrix
{
    /** Shown when nobody holds `ai.vault.access` yet. */
    public const NO_VAULT_HOLDER = 'el dueño de la bóveda';

    /**
     * Permissions the matrix shows and may change, in matrix order.
     *
     * @return list<string>
     */
    public static function visiblePermissionNames(): array
    {
        return collect(config('permissions.matrix', []))
            ->flatMap(fn (array $group) => array_keys($group['permissions']))
            ->values()
            ->all();
    }

    public static function projectApprovalRole(): string
    {
        return (string) config('permissions.project_approval_role', 'Aprobador de proyectos');
    }

    /**
     * @return Collection<int, Role>
     */
    public static function roles(): Collection
    {
        return Role::query()->where('guard_name', 'web')->inMatrixOrder()->with('permissions:id,name')->get();
    }

    /**
     * `AdminRole[]`: visible permissions only (sorted), scope label.
     *
     * @return list<array{name: string, label: string, scope: string, permissions: list<string>, system: bool}>
     */
    public static function adminRoles(): array
    {
        $visible = self::visiblePermissionNames();

        return self::roles()->map(fn (Role $role) => [
            'name' => $role->name,
            'label' => $role->displayLabel(),
            'scope' => $role->visibleScope(),
            'permissions' => $role->permissions->pluck('name')->intersect($visible)->sort()->values()->all(),
            'system' => (bool) $role->is_system,
        ])->values()->all();
    }

    /**
     * `RoleOption[]` of Administración › Usuarios.
     *
     * @return list<array{name: string, label: string, description: string, requires_area: bool}>
     */
    public static function roleOptions(): array
    {
        return Role::query()->where('guard_name', 'web')->inMatrixOrder()->get()->map(fn (Role $role) => [
            'name' => $role->name,
            'label' => $role->displayLabel(),
            'description' => (string) ($role->description ?? ''),
            'requires_area' => (bool) $role->requires_area,
        ])->values()->all();
    }

    /**
     * `PermissionGroup[]`; `ai.projects.approve` carries a hint naming the
     * active users who hold it today.
     *
     * @return list<array{key: string, label: string, permissions: list<array{name: string, label: string, hint?: string}>}>
     */
    public static function permissionGroups(): array
    {
        $approvers = self::joinNames(self::activeHolders('ai.projects.approve'));

        return collect(config('permissions.matrix', []))
            ->map(fn (array $group, string $key) => [
                'key' => $key,
                'label' => $group['label'],
                'permissions' => collect($group['permissions'])
                    ->map(function (string $label, string $name) use ($approvers) {
                        $permission = ['name' => $name, 'label' => $label];

                        if ($name === 'ai.projects.approve' && $approvers !== '') {
                            $permission['hint'] = 'Hoy: '.$approvers;
                        }

                        return $permission;
                    })
                    ->values()
                    ->all(),
            ])
            ->values()
            ->all();
    }

    /**
     * Name(s) of the active users holding `ai.vault.access` (directly; it is
     * never a role permission).
     */
    public static function vaultHolderName(): string
    {
        $names = self::joinNames(self::activeHolders(Permission::VAULT));

        return $names !== '' ? $names : self::NO_VAULT_HOLDER;
    }

    /**
     * Labels of the reserved (inactive) modules of config/modules.php.
     *
     * @return list<string>
     */
    public static function reservedModules(): array
    {
        return collect(config('modules', []))
            ->reject(fn (array $module) => (bool) ($module['active'] ?? false))
            ->pluck('name')
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    private static function activeHolders(string $permission): array
    {
        if (! Permission::query()->where('name', $permission)->where('guard_name', 'web')->exists()) {
            return [];
        }

        return User::permission($permission)
            ->where('active', true)
            ->orderBy('name')
            ->pluck('name')
            ->all();
    }

    /**
     * "Ana", "Ana y Luis", "Ana, Luis y Eva".
     *
     * @param  list<string>  $names
     */
    private static function joinNames(array $names): string
    {
        if (count($names) <= 1) {
            return $names[0] ?? '';
        }

        $last = array_pop($names);

        return implode(', ', $names).' y '.$last;
    }
}
