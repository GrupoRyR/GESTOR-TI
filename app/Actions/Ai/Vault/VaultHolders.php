<?php

namespace App\Actions\Ai\Vault;

use App\Models\Permission;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

/**
 * Active holders of `ai.vault.access` (granted directly, never by a role):
 * today only Jhon. They receive every vault notice and alert.
 */
final class VaultHolders
{
    /**
     * @return Collection<int, User>
     */
    public function all(): Collection
    {
        try {
            return User::permission(Permission::VAULT)->where('active', true)->orderBy('id')->get();
        } catch (PermissionDoesNotExist) {
            return new Collection;
        }
    }

    public static function has(?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        try {
            return $user->hasDirectPermission(Permission::VAULT);
        } catch (PermissionDoesNotExist) {
            return false;
        }
    }

    /**
     * Design `holder_name`: the first holder, else a generic label.
     */
    public function name(): string
    {
        return $this->all()->first()?->name ?? 'el dueño de la bóveda';
    }
}
