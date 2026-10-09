<?php

namespace App\Models;

use App\Models\Exceptions\ExclusivePermissionException;
use Illuminate\Database\Eloquent\Builder;
use Spatie\Permission\Models\Role as SpatieRole;

/**
 * Application role model (registered in AppServiceProvider). Roles can never
 * hold an exclusive permission (`ai.vault.access`); syncPermissions() goes
 * through givePermissionTo() only after detaching, so it is checked first.
 *
 * Metadata columns (migration 2026_10_13_000002): `label`, `scope`
 * (global | company | company_area), `description`, `requires_area` and
 * `is_system` (built-in roles of config/permissions.php).
 *
 * @property string $name
 * @property ?string $label
 * @property string $scope
 * @property ?string $description
 * @property bool $requires_area
 * @property bool $is_system
 */
class Role extends SpatieRole
{
    public const SCOPE_GLOBAL = 'global';

    public const SCOPE_COMPANY = 'company';

    public const SCOPE_COMPANY_AREA = 'company_area';

    /** Description shown for roles created from Roles y permisos. */
    public const CUSTOM_DESCRIPTION = 'Rol agregado en Roles y permisos';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'requires_area' => 'boolean',
            'is_system' => 'boolean',
        ];
    }

    /**
     * Built-in roles first (seeding order), then custom roles by creation.
     *
     * @param  Builder<Role>  $query
     * @return Builder<Role>
     */
    public function scopeInMatrixOrder(Builder $query): Builder
    {
        return $query->orderByDesc('is_system')->orderBy('id');
    }

    public function displayLabel(): string
    {
        return $this->label ?: $this->name;
    }

    public function visibleScope(): string
    {
        return (string) (config('permissions.scopes.'.$this->scope) ?? $this->scope);
    }

    public function givePermissionTo(...$permissions): static
    {
        $this->guardExclusive($permissions);

        return parent::givePermissionTo(...$permissions);
    }

    public function syncPermissions(...$permissions): static
    {
        $this->guardExclusive($permissions);

        return parent::syncPermissions(...$permissions);
    }

    private function guardExclusive(array $permissions): void
    {
        $exclusive = Permission::exclusiveAmong($permissions);

        if ($exclusive !== []) {
            throw ExclusivePermissionException::forRole($exclusive[0]);
        }
    }
}
