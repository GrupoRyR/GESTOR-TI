<?php

namespace App\Actions\Users;

use App\Models\Permission;
use App\Models\User;

/**
 * Assigns the initial roles of a user from config/gestor.php
 * (`initial_users`), matching the email case-insensitively:
 *
 * - super admins: `Súper Admin` and `is_global = true`;
 * - project approvers: `Aprobador de proyectos`;
 * - vault owner: direct `ai.vault.access` (never through a role);
 * - anyone else without roles: `Usuario final`.
 *
 * Additive and idempotent: it never removes roles, so it is safe on every
 * login (A5) and from InitialUsersSeeder.
 */
class AssignInitialRoles
{
    public const SUPER_ADMIN = 'Súper Admin';

    public const PROJECT_APPROVER = 'Aprobador de proyectos';

    public const END_USER = 'Usuario final';

    public function handle(User $user): User
    {
        $email = strtolower(trim((string) $user->email));
        $config = config('gestor.initial_users', []);

        $roles = [];

        if (in_array($email, $config['super_admins'] ?? [], true)) {
            $roles[] = self::SUPER_ADMIN;

            if (! $user->is_global) {
                $user->forceFill(['is_global' => true])->save();
            }
        }

        if (in_array($email, $config['project_approvers'] ?? [], true)) {
            $roles[] = self::PROJECT_APPROVER;
        }

        if ($roles === [] && $user->roles()->doesntExist()) {
            $roles[] = self::END_USER;
        }

        if ($roles !== []) {
            $user->assignRole($roles);
        }

        $vaultOwner = $config['vault_owner'] ?? null;

        if ($vaultOwner !== null && $email === $vaultOwner && ! $user->hasDirectPermission(Permission::VAULT)) {
            Permission::withExclusiveGrant(fn () => $user->givePermissionTo(Permission::VAULT));

            activity()
                ->performedOn($user)
                ->event('vault_access_granted')
                ->withProperties(['source' => 'config:gestor.initial_users.vault_owner'])
                ->log('Acceso a la bóveda concedido al dueño configurado');
        }

        return $user;
    }
}
