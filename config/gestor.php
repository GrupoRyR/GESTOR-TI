<?php

/*
|--------------------------------------------------------------------------
| Gestor TI RYR
|--------------------------------------------------------------------------
|
| initial_users: who receives which role on first login
| (`App\Actions\Users\AssignInitialRoles`, `InitialUsersSeeder`). Emails come
| only from the environment (comma separated, case insensitive); never from
| code. Unlisted users get `Usuario final`.
|
*/

$emails = static fn (?string $value): array => array_values(array_filter(array_map(
    static fn (string $email): string => strtolower(trim($email)),
    explode(',', (string) $value),
)));

return [

    'initial_users' => [
        // Súper Admin + `is_global`.
        'super_admins' => $emails(env('GESTOR_SUPER_ADMIN_EMAILS')),

        // Aprobador de proyectos.
        'project_approvers' => $emails(env('GESTOR_PROJECT_APPROVER_EMAILS')),

        // Receives `ai.vault.access` directly (never through a role).
        'vault_owner' => $emails(env('GESTOR_VAULT_OWNER_EMAIL'))[0] ?? null,
    ],

];
