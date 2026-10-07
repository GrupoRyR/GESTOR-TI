/**
 * Administración (`Pages/Admin/**`). Every route below requires Súper
 * Admin-level access on the server (`ai.manage` + Súper Admin role); the UI
 * only hides what the server would refuse anyway.
 *
 * Ziggy routes used:
 *
 * Empresas — `Admin/Companies/Index`, `Admin/Companies/Create`
 * - `admin.companies.index`        GET    —                        tab; Index with `selected = null`
 * - `admin.companies.edit`         GET    `{ company: id }`        card link; Index with `selected` set
 * - `admin.companies.update`       PUT    `{ company: id }`        sent as POST + `_method=PUT`, multipart:
 *                                           name, short_name, slug, domains[], sender_email, colors{primary,accent,dark},
 *                                           logo?, logo_dark?, active, tenant_id (only when `can_change_tenant`)
 * - `admin.companies.create`       GET    —                        "Agregar empresa" (wizard)
 * - `admin.companies.store`        POST   —                        wizard, multipart: same fields as update + `active`
 *
 * Áreas — `Admin/Areas/Index`
 * - `admin.areas.index`            GET    query `{ company_id }`   tab and company switch
 * - `admin.areas.store`            POST   —                        `{ company_id, name, departments[], approver_id|null, active }`
 * - `admin.areas.update`           PUT    `{ area: id }`           same body as store
 * - `admin.areas.mappings.update`  PUT    —                        `{ company_id, mappings: { department, area_id|null }[] }`
 * - `admin.areas.approval.update`  PUT    —                        `{ company_id, enabled }`
 *
 * Usuarios — `Admin/Users/Index`
 * - `admin.users.index`            GET    query `{ q?, role?, page? }`
 * - `admin.users.show`             GET    `{ user: id }`           row link; same page with `selected`
 * - `admin.users.roles.update`     PUT    `{ user: id }`           `{ roles: string[], area_id: number|null }`
 * - `admin.users.deactivate`       PATCH  `{ user: id }`           after a confirm dialog
 *
 * Roles y permisos — `Admin/Roles/Index`
 * - `admin.roles.index`            GET    —
 * - `admin.roles.update`           PUT    —                        `{ roles: { name, permissions[] }[] }`
 * - `admin.roles.store`            POST   —                        `{ label, scope, copy_from: string|null }`
 *
 * Integraciones — `Admin/Integrations/Index`
 * - `admin.integrations.index`           GET   —
 * - `admin.integrations.microsoft.test`  POST  —                   "Probar conexión"
 * - `admin.integrations.mail.test`       POST  —                   "Enviarme un correo de prueba"
 * - `admin.integrations.telegram.test`   POST  —                   "Enviarme un mensaje de prueba"
 * - `admin.integrations.github.update`   PUT   —                   `{ organization }`
 * - `admin.integrations.ai.update`       PUT   —                   `{ provider, model, monthly_cap_usd|null }`
 *   (GitHub install goes to the external `github.install_url`, not a Laravel route.)
 *
 * Auditoría — `Admin/Audit/Index`
 * - `admin.audit.index`            GET    query `{ causer_id?, module?, sensitive_only?, range, page? }`
 * - `admin.audit.export`           GET    same query; plain link (CSV download, not an Inertia visit)
 *
 * Permissions:
 * - All pages: Súper Admin (server-enforced). Tab links come from AdminLayout.
 * - `can_change_tenant` (Súper Admin only, audited) shows "Cambiar" on the Microsoft tenant field.
 * - `ai.vault.access` is never a role permission: shown as a locked row/note naming `vault.holder_name`.
 * - `ai.projects.approve` belongs only to "Aprobador de proyectos" by default.
 */
import type { Company, CompanyColors, FormErrors, Paginated, Permission } from '@/types/shared';

// ---- Empresas ---------------------------------------------------------------

export interface AdminCompany extends Company {
    /** Microsoft Entra tenant (GUID). */
    tenant_id: string;
    /** "segurex.com" — without "@". */
    domains: string[];
    /** Mailbox every email of this company's records is sent from. */
    sender_email: string;
    active: boolean;
    employees_count: number;
}

export interface AdminCompaniesIndexProps {
    /**
     * Every company, active or not. Named `admin_companies` (not `companies`)
     * so it does not overwrite the shared `companies` prop that feeds the
     * company selector.
     */
    admin_companies: AdminCompany[];
    /** Company open in the edit panel (`admin.companies.edit`); `null` on the index. */
    selected: AdminCompany | null;
    /** Súper Admin only; every change is audited. */
    can_change_tenant: boolean;
}

export type WizardStepNumber = 1 | 2 | 3 | 4 | 5;

export interface AdminCompaniesCreateProps {
    /**
     * Microsoft admin-consent URL with a `{tenant}` slot, e.g.
     * `https://login.microsoftonline.com/{tenant}/adminconsent?client_id=…&redirect_uri=…`.
     */
    admin_consent_url_template: string;
    /** Validation errors from `admin.companies.store`; the wizard opens on the first step that has one. */
    errors?: FormErrors;
    initial_step?: WizardStepNumber;
    /**
     * Optional prefill, e.g. resuming a company left half done. Logos are
     * never prefilled. Not needed after a failed POST: Inertia keeps the
     * wizard's client state.
     */
    draft?: Partial<CompanyDraft>;
}

export interface CompanyDraft {
    name: string;
    short_name: string;
    slug: string;
    domains: string[];
    tenant_id: string;
    colors: CompanyColors;
    sender_email: string;
}

// ---- Áreas ------------------------------------------------------------------

export interface AdminArea {
    id: number;
    name: string;
    employees_count: number;
    /** Microsoft department names mapped to this area. */
    departments: string[];
    /** Aprobador de área (visto bueno); `null` = no requiere. */
    approver: { id: number; name: string } | null;
    active: boolean;
}

export interface UnmappedDepartment {
    name: string;
    employees_count: number;
    /** Suggested or previously chosen area, if any. */
    area_id: number | null;
}

export interface AdminAreasIndexProps {
    company_id: number;
    areas: AdminArea[];
    unmapped_departments: UnmappedDepartment[];
    /** Area heads approve requests before they reach TI. */
    area_approval_enabled: boolean;
    approver_options: { id: number; name: string }[];
}

// ---- Usuarios ---------------------------------------------------------------

export interface AdminUserRow {
    id: number;
    name: string;
    /** Microsoft email, or the local username for the emergency account. */
    email: string;
    /** `null` = local emergency account (shown as "LOC"). */
    company_id: number | null;
    /** Role names (labels come from `role_options`). */
    roles: string[];
    last_login_at: string | null;
    telegram_linked: boolean;
    /** Signs in with a local password instead of Microsoft. */
    is_local: boolean;
    active: boolean;
}

export interface AdminUserDetail extends AdminUserRow {
    job_title: string | null;
    area_name: string | null;
    /** Area this person approves when they hold "Aprobador de área". */
    approver_area_id: number | null;
}

export interface RoleOption {
    name: string;
    label: string;
    description: string;
    /** "Aprobador de área": an area must be chosen. */
    requires_area: boolean;
}

export interface AdminUsersIndexProps {
    users: Paginated<AdminUserRow>;
    filters: { q: string | null; role: string | null };
    selected: AdminUserDetail | null;
    role_options: RoleOption[];
    area_options: { id: number; name: string; company_id: number }[];
    /** Only person with `ai.vault.access` (today Jhon Castro). */
    vault_holder_name: string;
}

// ---- Roles y permisos -------------------------------------------------------

export interface AdminRole {
    name: string;
    label: string;
    /** "Global", "Empresa", "Empresa y área". */
    scope: string;
    permissions: string[];
    /** Built-in role (cannot be renamed or deleted). */
    system: boolean;
}

export interface PermissionGroup {
    key: string;
    label: string;
    permissions: { name: Permission; label: string; hint?: string }[];
}

export interface AdminRolesIndexProps {
    roles: AdminRole[];
    permission_groups: PermissionGroup[];
    vault: { holder_name: string };
    /** Modules whose permissions are reserved until they are activated. */
    reserved_modules: string[];
}

// ---- Integraciones ----------------------------------------------------------

export interface MicrosoftCompanySync {
    company_id: number;
    status: 'ok' | 'error' | 'never';
    last_sync_at: string | null;
    synced_count: number | null;
    error: string | null;
}

export interface IntegrationsProps {
    microsoft: { companies: MicrosoftCompanySync[] };
    mail: {
        status: 'ok' | 'pending' | 'error';
        transport: 'graph' | 'smtp' | null;
        senders: { company_id: number; email: string }[];
        note: string | null;
    };
    telegram: {
        status: 'ok' | 'off' | 'error';
        bot_username: string | null;
        linked_users: number;
        last_message_at: string | null;
        webhook_ok: boolean;
    };
    github: {
        status: 'connected' | 'disconnected';
        organization: string | null;
        install_url: string | null;
        repositories_count: number;
    };
    ai: {
        status: 'ok' | 'off' | 'capped';
        provider: string;
        model: string;
        provider_options: { value: string; label: string }[];
        model_options: { value: string; label: string }[];
        monthly_cap_usd: number | null;
        month_spend_usd: number;
        /** Warning threshold as a ratio of the cap (0.8 = 80 %). */
        warn_ratio: number;
    };
}

export type AdminIntegrationsIndexProps = IntegrationsProps;

// ---- Auditoría --------------------------------------------------------------

export type AuditOrigin = 'web' | 'telegram' | 'system' | 'api';

export type AuditRange = '24h' | '7d' | '30d' | 'all';

export interface AuditChange {
    field: string;
    old: string | null;
    new: string | null;
}

export interface AuditEntry {
    id: number;
    at: string;
    /** `null` = unidentified (e.g. failed sign-ins). */
    causer: { id: number; name: string } | null;
    /** Spanish sentence, already rendered by the server. */
    description: string;
    origin: AuditOrigin;
    ip: string | null;
    /** Credential reveals, role and tenant changes, vault access. */
    sensitive: boolean;
    /** Needs attention (lockouts, repeated failures). */
    alert: boolean;
    changes: AuditChange[] | null;
}

export interface AuditFilters {
    causer_id: number | null;
    module: string | null;
    sensitive_only: boolean;
    range: AuditRange;
}

export interface AdminAuditIndexProps {
    entries: Paginated<AuditEntry>;
    filters: AuditFilters;
    causer_options: { id: number; name: string }[];
    module_options: { value: string; label: string }[];
}
