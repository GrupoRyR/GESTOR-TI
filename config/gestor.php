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

        // Aprobador de proyectos: the only role with `ai.projects.approve`
        // (e.g. jhon.castro@segurex.com,edward.ramanan@segurex.com).
        'project_approvers' => $emails(env('GESTOR_PROJECT_APPROVER_EMAILS')),

        // Receives `ai.vault.access` directly (never through a role).
        'vault_owner' => $emails(env('GESTOR_VAULT_OWNER_EMAIL'))[0] ?? null,
    ],

    // IT contact shown on `Auth/CompanyNotEnabled` (null hides the link).
    'support_email' => env('GESTOR_SUPPORT_EMAIL') ?: null,

    /*
    | Defaults of `App\Support\Settings` (table `settings` stores only the
    | overrides made later from Administración). Keep the PHP type of each
    | value: it is the type callers receive.
    */
    'settings_defaults' => [
        // AlertService: minutes before a grouped alert is notified again.
        'alerts.cooldown_minutes' => 60,

        // Gestor IA: business days for the first response to a request.
        'ai.first_response_business_days' => 3,

        // AI spend: monthly cap in USD (0 = not configured yet; pending
        // from Jhon) and warning threshold in percent of the cap.
        'ai.monthly_cost_cap_usd' => 0.0,
        'ai.cost_warning_percent' => 80,
    ],

    'microsoft' => [
        // "Agregar empresa" wizard: admin-consent link for the new tenant.
        // `{client_id}` is filled from services.microsoft.client_id on the
        // server; `{tenant}` stays for the page to fill with the typed GUID.
        'admin_consent_url_template' => env(
            'MICROSOFT_ADMIN_CONSENT_URL_TEMPLATE',
            'https://login.microsoftonline.com/{tenant}/adminconsent?client_id={client_id}',
        ),
    ],

    'brand' => [
        // Shared `companies[].colors` never carry null (design contract):
        // each missing key of a company falls back to these neutral colors.
        'default_colors' => [
            'primary' => '#334155',
            'accent' => '#64748B',
            'dark' => '#0F172A',
        ],
    ],

    // Render the `Errors/Error` Inertia page for 403/404/419/500/503
    // (bootstrap/app.php). Null = whenever APP_DEBUG is off.
    'error_pages' => env('GESTOR_ERROR_PAGES') === null ? null : (bool) env('GESTOR_ERROR_PAGES'),

    'alerts' => [
        // Default recipients of AlertService: users holding this permission
        // (through a role or directly) in the alert's company, plus global
        // users. Group-wide alerts (no company) go to every holder.
        'recipient_permission' => env('GESTOR_ALERTS_RECIPIENT_PERMISSION', 'ai.manage'),
    ],

    'telegram' => [
        // How webhook updates are processed: `after_response` (default; runs
        // in the same PHP process right after answering 200 to Telegram) or
        // `queue` (database queue, handled by the cron-driven worker, ~1 min).
        'processing' => env('TELEGRAM_PROCESSING', 'after_response'),

        // callback_data prefix => handler class (App\Telegram\Contracts\CallbackHandler).
        // callback_data is `{prefix}:{payload}` (max 64 bytes, Telegram limit).
        // Stage B registers the approval handlers here.
        'callbacks' => [],
    ],

];
