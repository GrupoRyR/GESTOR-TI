<?php

namespace App\Actions\Users;

use App\Models\Permission;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * The only application path to grant `ai.vault.access` to another user
 * (PROMPT §6.4.10): the acting user must already hold it (the vault owner
 * confirms the grant) and every grant is written to the audit log.
 * The permission is always direct; it can never come from a role.
 */
class GrantVaultAccess
{
    /**
     * @throws AuthorizationException
     */
    public function handle(User $actor, User $target): User
    {
        if (! $actor->checkPermissionTo(Permission::VAULT)) {
            throw new AuthorizationException('Solo el dueño de la bóveda puede conceder acceso a ella.');
        }

        if ($target->hasDirectPermission(Permission::VAULT)) {
            return $target;
        }

        Permission::withExclusiveGrant(fn () => $target->givePermissionTo(Permission::VAULT));

        activity()
            ->causedBy($actor)
            ->performedOn($target)
            ->event('vault_access_granted')
            ->log('Acceso a la bóveda concedido');

        return $target;
    }
}
