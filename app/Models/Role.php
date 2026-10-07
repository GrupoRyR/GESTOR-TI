<?php

namespace App\Models;

use App\Models\Exceptions\ExclusivePermissionException;
use Spatie\Permission\Models\Role as SpatieRole;

/**
 * Application role model (registered in AppServiceProvider). Roles can never
 * hold an exclusive permission (`ai.vault.access`); syncPermissions() goes
 * through givePermissionTo() only after detaching, so it is checked first.
 */
class Role extends SpatieRole
{
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
