<?php

use App\Domain\Assistant\Tools\DeliverCredentialTool;
use App\Domain\Assistant\Tools\SearchKnowledgeTool;
use App\Domain\Assistant\Tools\SearchProductsTool;
use App\Domain\Assistant\Tools\SearchRequestsTool;
use App\Domain\Assistant\Tools\ShowCardTool;
use App\Domain\Assistant\Tools\ShowProductTool;
use App\Telegram\Handlers\ApprovalCallbackHandler;

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

        // Holidays skipped by the first-response window (`YYYY-MM-DD`
        // strings; weekends are always skipped). No calendar is bundled.
        'ai.holidays' => [],

        // Project approval: when the approver is the person who evaluated
        // the request, require the other approver (off by default, 6.4).
        'ai.approval.require_other_if_evaluator' => false,

        // Work in progress limit: projects "En desarrollo" at the same time
        // (global, PROMPT 6.4.3). Shown on backlog, approvals and roadmap.
        'ai.wip_limit' => 3,

        // Projects (B5): tasks allowed in "En progreso" per project (the
        // board shows it; it does not block), days between reminders of a
        // pending requirement, and the cost/hours increase over the approved
        // estimate that asks for a new approval (0.2 = 20 %).
        'ai.board.in_progress_limit' => 3,
        'ai.requirements.reminder_every_days' => 2,
        'ai.reapproval_threshold' => 0.2,

        // Time and costs (B6): COP per USD used to show the AI spend of
        // `ai_interactions` (USD) among the project costs (COP). Pending
        // from Jhon: the rate to use (no live exchange rate is fetched).
        'ai.usd_cop_rate' => 4000,

        // AI spend: monthly cap in USD (0 = not configured yet; pending
        // from Jhon) and warning threshold in percent of the cap.
        'ai.monthly_cost_cap_usd' => 0.0,
        'ai.cost_warning_percent' => 80,

        // AI provider and model (keys of `ai.providers` below), changed from
        // Administración › Integraciones.
        'ai.provider' => 'deepseek',
        'ai.model' => 'deepseek-chat',

        // GitHub organization of the Gestor (Stage D uses it).
        'github.organization' => null,
    ],

    /*
    | AI providers offered in Administración › Integraciones (PROMPT §6.8.3),
    | in display order. `credential` is the config key that must be filled
    | for the provider to be "on" (never sent to the frontend); `models` is a
    | small curated list for the selector — the saved model may be any id.
    */
    'ai' => [
        // Request files (App\Domain\Ai\RequestAttachments): private disk,
        // the design's wizard limits (lib/wizard.ts: 10 files of 10 MB;
        // images, PDF, Excel, CSV). Types are detected from the content;
        // SVG is left out on purpose (scripts), CSV is often `text/plain`.
        'attachments' => [
            'disk' => 'local',
            'max_files' => 10,
            'max_kb' => 10 * 1024,
            'mimetypes' => [
                'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/heic', 'image/heif',
                'application/pdf',
                'application/vnd.ms-excel',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'text/csv', 'text/plain',
            ],
        ],

        // Prices in USD per million tokens, per model id (App\Services\Ai\AiPricing):
        // `input` = input without cache (cache miss), `cached_input` = cache
        // hit, `output` = output. Cost of a call = uncached × input + cached ×
        // cached_input + output × output. A model missing here costs 0 and is
        // logged. DeepSeek list prices (api-docs.deepseek.com/quick_start/pricing,
        // V3.2, checked Oct 2026; confirm before go-live).
        'prices' => [
            'deepseek-chat' => ['input' => 0.28, 'cached_input' => 0.028, 'output' => 0.42],
            'deepseek-reasoner' => ['input' => 0.28, 'cached_input' => 0.028, 'output' => 0.42],
        ],

        'providers' => [
            'deepseek' => [
                'label' => 'DeepSeek',
                'credential' => 'services.deepseek.api_key',
                'models' => ['deepseek-chat', 'deepseek-reasoner'],
            ],
            'openai' => [
                'label' => 'OpenAI',
                'credential' => 'services.openai.api_key',
                'models' => ['gpt-4.1', 'gpt-4.1-mini', 'gpt-4o-mini'],
            ],
            'anthropic' => [
                'label' => 'Anthropic',
                'credential' => 'services.anthropic.api_key',
                'models' => ['claude-sonnet-4-5', 'claude-haiku-4-5'],
            ],
            'gemini' => [
                'label' => 'Google Gemini',
                'credential' => 'services.gemini.api_key',
                'models' => ['gemini-2.5-pro', 'gemini-2.5-flash'],
            ],
            'mistral' => [
                'label' => 'Mistral',
                'credential' => 'services.mistral.api_key',
                'models' => ['mistral-large-latest', 'mistral-small-latest'],
            ],
            'openrouter' => [
                'label' => 'OpenRouter',
                'credential' => 'services.openrouter.api_key',
                'models' => ['deepseek/deepseek-chat', 'anthropic/claude-sonnet-4.5', 'openai/gpt-4.1-mini'],
            ],
            'ollama' => [
                'label' => 'Ollama (local)',
                'credential' => 'services.ollama.base_url',
                'models' => ['llama3.1', 'qwen2.5'],
            ],
        ],
    ],

    /*
    | Asistente TI · Excel import (App\Actions\Assistant\KnowledgeImports,
    | PROMPT 6.8.1). Only .xlsx and .csv: the reader (openspout/openspout,
    | pure PHP with zip + xml) cannot read the old .xls format. Files stay
    | on the private disk. `max_rows`: data rows of the chosen sheet;
    | `preview_new_rows`: new rows shown on the preview after the errors
    | and updates (all of them are imported).
    */
    'assistant' => [
        // Read-only tools of the assistant (PROMPT 6.8.2, 6.8.4), in the order
        // the provider receives them (keep it stable: it is part of the cached
        // prefix). Each class implements App\Domain\Assistant\Tools\AssistantTool;
        // later stages add `estado_apps`, `tickets`, `inventario`,
        // `consultar_sap` here without touching the engine.
        'tools' => [
            SearchKnowledgeTool::class,
            ShowCardTool::class,
            SearchProductsTool::class,
            ShowProductTool::class,
            SearchRequestsTool::class,
            DeliverCredentialTool::class,
        ],

        // Context kept per conversation (PROMPT 6.8.2: 30 minutes).
        'context_minutes' => 30,

        // Greeting suggestions of an empty chat (`first_open_suggestions`).
        'suggestions' => [
            '¿Quién recibe el informe de ventas diario?',
            '¿Cómo me conecto a la VPN?',
            '¿Qué es un pedido bloqueado por cartera?',
            '¿Cómo saco la antigüedad de cartera?',
        ],

        // Password delivery: a credential notice can be shown ("Mostrar") by
        // its owner during `reveal_minutes` after the delivery; each time
        // the value stays visible `reveal_seconds` (design: 30, max 30).
        'credentials' => [
            'reveal_minutes' => 10,
            'reveal_seconds' => 30,
        ],

        'import' => [
            'disk' => 'local',
            'max_kb' => (int) env('ASSISTANT_IMPORT_MAX_KB', 10 * 1024),
            'max_rows' => 2000,
            'preview_new_rows' => 20,
        ],
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

    /*
    | Administración › Auditoría (`App\Support\Audit\AuditEntries`).
    |   - modules: filter value => label and the activity log names it covers
    |     (`default` holds role/vault events logged before log names existed).
    |   - sensitive_events / sensitive_log_names: credential reveals, vault,
    |     role and permission changes, emergency login events. Tenant changes
    |     (a `companies` entry touching `tenant_id`) are sensitive too.
    |   - alert_events: failures and lockouts that need attention.
    |   - export_limit: max rows of the CSV export (newest first).
    */
    'audit' => [
        'modules' => [
            'companies' => ['label' => 'Empresas', 'log_names' => ['companies']],
            'areas' => ['label' => 'Áreas', 'log_names' => ['areas']],
            'employees' => ['label' => 'Colaboradores', 'log_names' => ['employees']],
            'users' => ['label' => 'Usuarios y roles', 'log_names' => ['users', 'default']],
            'credentials' => ['label' => 'Contraseñas', 'log_names' => ['credentials', 'employee_accesses', 'access_systems']],
            'auth' => ['label' => 'Inicio de sesión', 'log_names' => ['auth']],
            'ai' => ['label' => 'Gestor IA', 'log_names' => ['ai']],
            'integrations' => ['label' => 'Integraciones', 'log_names' => ['integrations', 'settings']],
        ],
        'sensitive_events' => [
            'credential_revealed', 'vault_access_granted', 'role_permissions_updated', 'role_created',
            'user_roles_updated', 'ai_settings_updated',
            // Vault (B8): reads, copies, changes and blocked attempts.
            'vault_view', 'vault_copy', 'vault_create', 'vault_update', 'vault_delete', 'vault_denied',
        ],
        'sensitive_log_names' => ['auth', 'credentials'],
        'alert_events' => [
            'emergency_login_failed', 'emergency_login_locked', 'emergency_login_throttled', 'emergency_login_inactive',
        ],
        'export_limit' => 10000,
    ],

    'telegram' => [
        // How webhook updates are processed: `after_response` (default; runs
        // in the same PHP process right after answering 200 to Telegram) or
        // `queue` (database queue, handled by the cron-driven worker, ~1 min).
        'processing' => env('TELEGRAM_PROCESSING', 'after_response'),

        // callback_data prefix => handler class (App\Telegram\Contracts\CallbackHandler).
        // callback_data is `{prefix}:{payload}` (max 64 bytes, Telegram limit).
        // `ai-approval`: ✅ Aprobar / ❌ Rechazar of project approvals (B4).
        'callbacks' => [
            'ai-approval' => ApprovalCallbackHandler::class,
        ],
    ],

];
