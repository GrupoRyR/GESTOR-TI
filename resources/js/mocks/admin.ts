/**
 * Mocks for Administración: Empresas (list + edit, wizard per step and with
 * server errors), Áreas, Usuarios, Roles y permisos, Integraciones and
 * Auditoría. Unknown real facts stay as bracket placeholders.
 */
import type {
    AdminAreasIndexProps,
    AdminAuditIndexProps,
    AdminCompaniesCreateProps,
    AdminCompaniesIndexProps,
    AdminCompany,
    AdminIntegrationsIndexProps,
    AdminRole,
    AdminRolesIndexProps,
    AdminUserDetail,
    AdminUserRow,
    AdminUsersIndexProps,
    AuditEntry,
    CompanyDraft,
    PermissionGroup,
    RoleOption,
} from '@/types/pages/admin';
import type { PageProps, Paginated } from '@/types/shared';
import { ANDINA, DERIVADOS, SEGUREX, sharedFor, type MockVariant } from './shared';

type CompaniesPage = PageProps<AdminCompaniesIndexProps>;
type WizardPage = PageProps<AdminCompaniesCreateProps>;
type AreasPage = PageProps<AdminAreasIndexProps>;
type UsersPage = PageProps<AdminUsersIndexProps>;
type RolesPage = PageProps<AdminRolesIndexProps>;
type IntegrationsPage = PageProps<AdminIntegrationsIndexProps>;
type AuditPage = PageProps<AdminAuditIndexProps>;
type AdminPage = CompaniesPage | WizardPage | AreasPage | UsersPage | RolesPage | IntegrationsPage | AuditPage;

const VAULT_HOLDER = 'Jhon Castro';

/** Laravel-style paginator around a slice. */
function paginate<T>(rows: T[], total: number, routeName: string, currentPage = 1, perPage = 20): Paginated<T> {
    const lastPage = Math.max(1, Math.ceil(total / perPage));
    const url = (page: number) => `#${routeName}?page=${page}`;
    return {
        data: rows,
        links: [
            { url: currentPage > 1 ? url(currentPage - 1) : null, label: '&laquo; Anterior', active: false },
            ...Array.from({ length: Math.min(lastPage, 3) }, (_, index) => ({
                url: url(index + 1),
                label: String(index + 1),
                active: index + 1 === currentPage,
            })),
            { url: currentPage < lastPage ? url(currentPage + 1) : null, label: 'Siguiente &raquo;', active: false },
        ],
        meta: { current_page: currentPage, last_page: lastPage, per_page: perPage, total },
    };
}

// ---- Empresas ---------------------------------------------------------------

export const ADMIN_COMPANIES: AdminCompany[] = [
    {
        id: SEGUREX.id,
        name: SEGUREX.name,
        short_name: SEGUREX.short_name,
        slug: SEGUREX.slug,
        tenant_id: '97cd024c-cd7a-419b-b385-f47b5f5816c8',
        domains: ['segurex.com'],
        sender_email: 'segurex.info@segurex.com',
        colors: SEGUREX.colors,
        logo_url: SEGUREX.logo_url,
        logo_dark_url: SEGUREX.logo_dark_url,
        active: true,
        employees_count: 142,
    },
    {
        id: DERIVADOS.id,
        name: DERIVADOS.name,
        short_name: DERIVADOS.short_name,
        slug: DERIVADOS.slug,
        tenant_id: '[ID DE INQUILINO DE DERIVADOS]',
        domains: ['derivadoscol.com'],
        sender_email: 'derivados.info@derivadoscol.com',
        colors: DERIVADOS.colors,
        logo_url: DERIVADOS.logo_url,
        logo_dark_url: DERIVADOS.logo_dark_url,
        active: true,
        employees_count: 72,
    },
    {
        id: ANDINA.id,
        name: ANDINA.name,
        short_name: ANDINA.short_name,
        slug: ANDINA.slug,
        tenant_id: '3f1c9a52-7d0e-4b6a-9c21-5e8d4f0a7b13',
        domains: ['andinapruebas.com'],
        sender_email: 'andina.info@andinapruebas.com',
        colors: ANDINA.colors,
        logo_url: null,
        logo_dark_url: null,
        active: false,
        employees_count: 0,
    },
];

const companiesBase: AdminCompaniesIndexProps = {
    admin_companies: ADMIN_COMPANIES,
    selected: ADMIN_COMPANIES[0] ?? null,
    can_change_tenant: true,
};

const companiesProps = { ...sharedFor('jhon'), ...companiesBase } satisfies CompaniesPage;

// ---- Wizard -----------------------------------------------------------------

const CONSENT_TEMPLATE =
    'https://login.microsoftonline.com/{tenant}/adminconsent?client_id=[ID DE LA APP DEL GESTOR]&redirect_uri=https%3A%2F%2F[DOMINIO DEL GESTOR]%2Fadmin%2Fempresas%2Fconsentimiento';

const ANDINA_DRAFT: CompanyDraft = {
    name: 'Andina Pruebas S.A.S.',
    short_name: 'Andina',
    slug: 'andina',
    domains: ['andinapruebas.com'],
    tenant_id: '3f1c9a52-7d0e-4b6a-9c21-5e8d4f0a7b13',
    colors: ANDINA.colors,
    sender_email: 'andina.info@andinapruebas.com',
};

const wizardProps = { ...sharedFor('jhon'), admin_consent_url_template: CONSENT_TEMPLATE } satisfies WizardPage;

// ---- Áreas ------------------------------------------------------------------

const PEOPLE = {
    carlos: { id: 31, name: 'Carlos Mejía' },
    paola: { id: 101, name: 'Paola Ríos' },
    leonardo: { id: 2, name: 'Leonardo Herrera' },
    edward: { id: 104, name: 'Edward Ramanan' },
    andres: { id: 7, name: 'Andrés Méndez' },
};

const SEGUREX_AREAS: AdminAreasIndexProps['areas'] = [
    { id: 2, name: 'Ventas', employees_count: 38, departments: ['Ventas', 'Comercial'], approver: PEOPLE.carlos, active: true },
    { id: 4, name: 'Cartera', employees_count: 9, departments: ['Cartera'], approver: null, active: true },
    { id: 6, name: 'Contabilidad', employees_count: 11, departments: ['Contabilidad', 'Impuestos'], approver: null, active: true },
    { id: 7, name: 'Tesorería', employees_count: 4, departments: ['Tesorería'], approver: null, active: true },
    { id: 8, name: 'Compras', employees_count: 7, departments: ['Compras', 'Importaciones'], approver: null, active: true },
    { id: 9, name: 'Producción', employees_count: 41, departments: ['Planta', 'Calidad'], approver: null, active: true },
    { id: 3, name: 'Gerencia', employees_count: 6, departments: ['Gerencia General'], approver: null, active: true },
    { id: 1, name: 'TI', employees_count: 2, departments: ['Sistemas'], approver: null, active: true },
];

const DERIVADOS_AREAS: AdminAreasIndexProps['areas'] = [
    { id: 11, name: 'Compras', employees_count: 14, departments: ['Compras'], approver: PEOPLE.andres, active: true },
    { id: 12, name: 'Tesorería', employees_count: 5, departments: ['Tesorería', 'Pagos'], approver: null, active: true },
    { id: 13, name: 'Diseño', employees_count: 9, departments: ['Diseño', 'Mercadeo'], approver: null, active: true },
    { id: 14, name: 'Logística', employees_count: 0, departments: [], approver: null, active: false },
];

const areasProps = {
    ...sharedFor('jhon'),
    company_id: SEGUREX.id,
    areas: SEGUREX_AREAS,
    unmapped_departments: [
        { name: 'Cobranzas', employees_count: 3, area_id: 4 },
        { name: 'Logística', employees_count: 1, area_id: null },
    ],
    area_approval_enabled: false,
    approver_options: Object.values(PEOPLE).filter((person) => person.id !== PEOPLE.andres.id),
} satisfies AreasPage;

// ---- Usuarios ---------------------------------------------------------------

export const ROLE_OPTIONS: RoleOption[] = [
    { name: 'Súper Admin', label: 'Súper Admin', description: 'Administra empresas, usuarios e integraciones', requires_area: false },
    { name: 'Líder IA', label: 'Líder IA', description: 'Evalúa, planea y entrega los desarrollos', requires_area: false },
    { name: 'Aprobador de área', label: 'Aprobador de área', description: 'Pide escoger el área', requires_area: true },
    {
        name: 'Aprobador de proyectos',
        label: 'Aprobador de proyectos',
        description: 'Ve costos y aprueba qué se vuelve proyecto',
        requires_area: false,
    },
    { name: 'Usuario final', label: 'Usuario final', description: 'Pide desarrollos y ve los suyos', requires_area: false },
];

const USER_ROWS: AdminUserRow[] = [
    {
        id: 1,
        name: 'Jhon Castro',
        email: 'jhon.castro@segurex.com',
        company_id: SEGUREX.id,
        roles: ['Súper Admin', 'Líder IA', 'Aprobador de proyectos'],
        last_login_at: '2026-10-07T10:10:00-05:00',
        telegram_linked: true,
        is_local: false,
        active: true,
    },
    {
        id: 2,
        name: 'Leonardo Herrera',
        email: 'leonardo.herrera@segurex.com',
        company_id: SEGUREX.id,
        roles: ['Súper Admin'],
        last_login_at: '2026-10-07T08:12:00-05:00',
        telegram_linked: true,
        is_local: false,
        active: true,
    },
    {
        id: 104,
        name: 'Edward Ramanan',
        email: 'edward.ramanan@segurex.com',
        company_id: SEGUREX.id,
        roles: ['Aprobador de proyectos', 'Usuario final'],
        last_login_at: '2026-10-06T17:30:00-05:00',
        telegram_linked: true,
        is_local: false,
        active: true,
    },
    {
        id: 101,
        name: 'Paola Ríos',
        email: 'paola.rios@segurex.com',
        company_id: SEGUREX.id,
        roles: ['Usuario final'],
        last_login_at: '2026-10-03T11:00:00-05:00',
        telegram_linked: false,
        is_local: false,
        active: true,
    },
    {
        id: 7,
        name: 'Andrés Méndez',
        email: 'andres.mendez@derivadoscol.com',
        company_id: DERIVADOS.id,
        roles: ['Usuario final'],
        last_login_at: '2026-10-06T09:20:00-05:00',
        telegram_linked: true,
        is_local: false,
        active: true,
    },
    {
        id: 108,
        name: 'Camila Torres',
        email: 'camila.torres@derivadoscol.com',
        company_id: DERIVADOS.id,
        roles: ['Usuario final'],
        last_login_at: '2026-09-29T15:45:00-05:00',
        telegram_linked: false,
        is_local: false,
        active: true,
    },
    {
        id: 900,
        name: 'Cuenta de emergencia',
        email: 'admin.emergencia',
        company_id: null,
        roles: ['Súper Admin'],
        last_login_at: null,
        telegram_linked: false,
        is_local: true,
        active: true,
    },
];

const userRow = (id: number): AdminUserRow => {
    const row = USER_ROWS.find((item) => item.id === id);
    if (!row) throw new Error(`Unknown mock user ${id}`);
    return row;
};

const EDWARD: AdminUserDetail = { ...userRow(104), job_title: '[CARGO]', area_name: 'Gerencia', approver_area_id: null };
const EMERGENCY: AdminUserDetail = { ...userRow(900), job_title: null, area_name: null, approver_area_id: null };

const AREA_OPTIONS = [...SEGUREX_AREAS.map((area) => ({ id: area.id, name: area.name, company_id: SEGUREX.id }))].concat(
    DERIVADOS_AREAS.filter((area) => area.active).map((area) => ({ id: area.id, name: area.name, company_id: DERIVADOS.id })),
);

const usersProps = {
    ...sharedFor('jhon'),
    users: paginate(USER_ROWS, 7, 'admin.users.index'),
    filters: { q: null, role: null },
    selected: EDWARD,
    role_options: ROLE_OPTIONS,
    area_options: AREA_OPTIONS,
    vault_holder_name: VAULT_HOLDER,
} satisfies UsersPage;

// ---- Roles y permisos -------------------------------------------------------

/** Role order of the matrix columns and of the permission bit strings below. */
const ROLE_COLUMNS: Omit<AdminRole, 'permissions'>[] = [
    { name: 'Súper Admin', label: 'Súper Admin', scope: 'Global', system: true },
    { name: 'Líder IA', label: 'Líder IA', scope: 'Global', system: true },
    { name: 'Aprobador de área', label: 'Aprobador de área', scope: 'Empresa y área', system: true },
    { name: 'Aprobador de proyectos', label: 'Aprobador de proyectos', scope: 'Global', system: true },
    { name: 'Usuario final', label: 'Usuario final', scope: 'Empresa', system: true },
];

export const PERMISSION_GROUPS: PermissionGroup[] = [
    {
        key: 'ai',
        label: 'Gestor IA',
        permissions: [
            { name: 'ai.manage', label: 'Gestionar todo el Gestor IA' },
            { name: 'ai.requests.create', label: 'Crear solicitudes' },
            { name: 'ai.requests.approve_area', label: 'Dar visto bueno del área' },
            { name: 'ai.projects.approve', label: 'Aprobar proyectos', hint: 'Hoy: Jhon Castro y Edward Ramanan' },
            { name: 'ai.costs.view', label: 'Ver costos' },
            { name: 'ai.costs.manage', label: 'Gestionar costos y tarifas' },
            { name: 'ai.area.view', label: 'Ver lo de su área' },
            { name: 'ai.docs.edit', label: 'Editar documentación' },
            { name: 'ai.github.manage', label: 'Conectar GitHub' },
            { name: 'ai.mcp.use', label: 'Usar el conector de Claude' },
        ],
    },
    {
        key: 'assistant',
        label: 'Asistente TI',
        permissions: [
            { name: 'assistant.use', label: 'Usar el Asistente' },
            { name: 'assistant.knowledge.manage', label: 'Administrar la base de conocimiento' },
            { name: 'assistant.credentials', label: 'Pedir contraseñas al Asistente' },
        ],
    },
    {
        key: 'base',
        label: 'Base',
        permissions: [
            { name: 'companies.view_all', label: 'Ver todas las empresas' },
            { name: 'credentials.view', label: 'Ver contraseñas de colaboradores' },
        ],
    },
];

/** Defaults per permission, one bit per role column: SA, LIA, AA, AP, UF. */
const DEFAULT_BITS: Record<string, string> = {
    'ai.manage': '11000',
    'ai.requests.create': '11111',
    'ai.requests.approve_area': '11100',
    'ai.projects.approve': '00010',
    'ai.costs.view': '11010',
    'ai.costs.manage': '11000',
    'ai.area.view': '11100',
    'ai.docs.edit': '11000',
    'ai.github.manage': '11000',
    'ai.mcp.use': '11000',
    'assistant.use': '11000',
    'assistant.knowledge.manage': '11000',
    'assistant.credentials': '11000',
    'companies.view_all': '11010',
    'credentials.view': '11000',
};

export const DEFAULT_ROLES: AdminRole[] = ROLE_COLUMNS.map((role, index) => ({
    ...role,
    permissions: Object.entries(DEFAULT_BITS)
        .filter(([, bits]) => bits[index] === '1')
        .map(([permission]) => permission),
}));

const rolesProps = {
    ...sharedFor('jhon'),
    roles: DEFAULT_ROLES,
    permission_groups: PERMISSION_GROUPS,
    vault: { holder_name: VAULT_HOLDER },
    reserved_modules: ['Monitoreo', 'Tickets', 'Inventario', 'Operación'],
} satisfies RolesPage;

// ---- Integraciones ----------------------------------------------------------

const integrationsProps = {
    ...sharedFor('jhon'),
    microsoft: {
        companies: [
            { company_id: SEGUREX.id, status: 'ok', last_sync_at: '2026-10-07T06:00:00-05:00', synced_count: 142, error: null },
            {
                company_id: DERIVADOS.id,
                status: 'error',
                last_sync_at: '2026-10-07T06:00:00-05:00',
                synced_count: null,
                error: 'Falta el permiso User.Read.All: un administrador de Microsoft de Derivados debe aprobarlo.',
            },
        ],
    },
    mail: {
        status: 'pending',
        transport: null,
        senders: [
            { company_id: SEGUREX.id, email: 'segurex.info@segurex.com' },
            { company_id: DERIVADOS.id, email: 'derivados.info@derivadoscol.com' },
        ],
        note: 'Falta el permiso de envío (Graph Mail.Send o SMTP). Mientras tanto los avisos van por Telegram y la campana.',
    },
    telegram: {
        status: 'ok',
        bot_username: null,
        linked_users: 4,
        last_message_at: '2026-10-07T09:41:00-05:00',
        webhook_ok: true,
    },
    github: { status: 'disconnected', organization: null, install_url: null, repositories_count: 0 },
    ai: {
        status: 'ok',
        provider: 'deepseek',
        model: 'deepseek-chat',
        provider_options: [
            { value: 'deepseek', label: 'DeepSeek' },
            { value: 'anthropic', label: 'Anthropic' },
            { value: 'openai', label: 'OpenAI' },
        ],
        model_options: [
            { value: 'deepseek-chat', label: 'deepseek-chat' },
            { value: 'deepseek-reasoner', label: 'deepseek-reasoner' },
        ],
        // Real cap still pending ([TOPE]); 10 keeps the bar meaningful in the preview.
        monthly_cap_usd: 10,
        month_spend_usd: 3.4,
        warn_ratio: 0.8,
    },
} satisfies IntegrationsPage;

// ---- Auditoría --------------------------------------------------------------

const AUDIT_ENTRIES: AuditEntry[] = [
    {
        id: 5012,
        at: '2026-10-07T10:02:00-05:00',
        causer: { id: 2, name: 'Leonardo Herrera' },
        description: 'Cambió el correo emisor de Derivados',
        origin: 'web',
        ip: '190.24.118.6',
        sensitive: false,
        alert: false,
        changes: [{ field: 'Correo emisor', old: 'info@derivadoscol.com', new: 'derivados.info@derivadoscol.com' }],
    },
    {
        id: 5011,
        at: '2026-10-07T09:15:00-05:00',
        causer: { id: 1, name: 'Jhon Castro' },
        description: 'Reveló la contraseña de SAP B1 de Juan Pérez',
        origin: 'web',
        ip: '190.24.118.4',
        sensitive: true,
        alert: false,
        changes: null,
    },
    {
        id: 5010,
        at: '2026-10-07T07:48:00-05:00',
        causer: { id: 1, name: 'Jhon Castro' },
        description: 'Recibió la contraseña de Office de Mónica Salazar por el Asistente',
        origin: 'telegram',
        ip: null,
        sensitive: true,
        alert: false,
        changes: null,
    },
    {
        id: 5009,
        at: '2026-10-06T23:20:00-05:00',
        causer: null,
        description: '5 intentos fallidos en la cuenta de emergencia; quedó bloqueada',
        origin: 'web',
        ip: '181.52.x.x',
        sensitive: true,
        alert: true,
        changes: null,
    },
    {
        id: 5008,
        at: '2026-10-06T16:05:00-05:00',
        causer: { id: 1, name: 'Jhon Castro' },
        description: 'Dio el rol Aprobador de proyectos a Edward Ramanan',
        origin: 'web',
        ip: '190.24.118.4',
        sensitive: true,
        alert: false,
        changes: [{ field: 'Roles', old: 'Usuario final', new: 'Aprobador de proyectos, Usuario final' }],
    },
    {
        id: 5007,
        at: '2026-10-05T14:30:00-05:00',
        causer: { id: 2, name: 'Leonardo Herrera' },
        description: 'Desactivó a Ricardo Gómez (ya no está en Microsoft)',
        origin: 'system',
        ip: null,
        sensitive: false,
        alert: false,
        changes: [{ field: 'Estado', old: 'Activo', new: 'Inactivo' }],
    },
    {
        id: 5006,
        at: '2026-10-03T09:00:00-05:00',
        causer: { id: 1, name: 'Jhon Castro' },
        description: 'Cambió el inquilino de Microsoft de Derivados',
        origin: 'web',
        ip: '190.24.118.4',
        sensitive: true,
        alert: false,
        changes: [{ field: 'ID de inquilino', old: null, new: '[ID DE INQUILINO DE DERIVADOS]' }],
    },
];

const auditProps = {
    ...sharedFor('jhon'),
    entries: paginate(AUDIT_ENTRIES, 312, 'admin.audit.index'),
    filters: { causer_id: null, module: null, sensitive_only: false, range: '7d' },
    causer_options: [
        { id: 1, name: 'Jhon Castro' },
        { id: 2, name: 'Leonardo Herrera' },
        { id: 104, name: 'Edward Ramanan' },
    ],
    module_options: [
        { value: 'companies', label: 'Empresas' },
        { value: 'users', label: 'Usuarios y roles' },
        { value: 'credentials', label: 'Contraseñas' },
        { value: 'auth', label: 'Inicio de sesión' },
        { value: 'ai', label: 'Gestor IA' },
        { value: 'integrations', label: 'Integraciones' },
    ],
} satisfies AuditPage;

// ---- variants ---------------------------------------------------------------

export const variants: Record<string, MockVariant<AdminPage>> = {
    // Empresas
    'companies-edit': { page: 'Admin/Companies/Index', label: 'Empresas · editando Segurex', props: companiesProps },
    'companies-index': {
        page: 'Admin/Companies/Index',
        label: 'Empresas · sin empresa escogida',
        props: { ...companiesProps, selected: null } satisfies CompaniesPage,
    },
    'companies-derivados': {
        page: 'Admin/Companies/Index',
        label: 'Empresas · Derivados, sin permiso de inquilino',
        props: {
            ...companiesProps,
            ...sharedFor('leonardo'),
            selected: ADMIN_COMPANIES[1] ?? null,
            can_change_tenant: false,
        } satisfies CompaniesPage,
    },
    'companies-andina': {
        page: 'Admin/Companies/Index',
        label: 'Empresas · Andina inactiva, sin logo',
        props: {
            ...companiesProps,
            ...sharedFor('jhon', { flash: { success: 'Andina quedó creada. Actívala cuando su administrador apruebe el permiso.' } }),
            selected: ADMIN_COMPANIES[2] ?? null,
        } satisfies CompaniesPage,
    },

    // Wizard
    'wizard-1': { page: 'Admin/Companies/Create', label: 'Agregar empresa · paso 1', props: wizardProps },
    'wizard-2': {
        page: 'Admin/Companies/Create',
        label: 'Agregar empresa · paso 2 (Microsoft)',
        props: { ...wizardProps, initial_step: 2, draft: { ...ANDINA_DRAFT, tenant_id: '' } } satisfies WizardPage,
    },
    'wizard-3': {
        page: 'Admin/Companies/Create',
        label: 'Agregar empresa · paso 3 (marca)',
        props: { ...wizardProps, initial_step: 3, draft: ANDINA_DRAFT } satisfies WizardPage,
    },
    'wizard-4': {
        page: 'Admin/Companies/Create',
        label: 'Agregar empresa · paso 4 (correo)',
        props: { ...wizardProps, initial_step: 4, draft: ANDINA_DRAFT } satisfies WizardPage,
    },
    'wizard-5': {
        page: 'Admin/Companies/Create',
        label: 'Agregar empresa · paso 5 (revisar)',
        props: { ...wizardProps, initial_step: 5, draft: ANDINA_DRAFT } satisfies WizardPage,
    },
    'wizard-errors': {
        page: 'Admin/Companies/Create',
        label: 'Agregar empresa · errores del servidor',
        props: {
            ...wizardProps,
            draft: { ...ANDINA_DRAFT, slug: 'segurex' },
            errors: {
                slug: 'Ya existe una empresa con el identificador «segurex».',
                sender_email: 'Microsoft no deja enviar desde ese buzón todavía.',
            },
        } satisfies WizardPage,
    },

    // Áreas
    'areas-segurex': { page: 'Admin/Areas/Index', label: 'Áreas · Segurex con departamentos sin área', props: areasProps },
    'areas-derivados': {
        page: 'Admin/Areas/Index',
        label: 'Áreas · Derivados con visto bueno activo',
        props: {
            ...areasProps,
            company_id: DERIVADOS.id,
            areas: DERIVADOS_AREAS,
            unmapped_departments: [],
            area_approval_enabled: true,
            approver_options: [PEOPLE.andres, { id: 108, name: 'Camila Torres' }],
        } satisfies AreasPage,
    },
    'areas-empty': {
        page: 'Admin/Areas/Index',
        label: 'Áreas · empresa sin áreas',
        props: {
            ...areasProps,
            ...sharedFor('jhon', { companies: [SEGUREX, DERIVADOS, ANDINA] }),
            company_id: ANDINA.id,
            areas: [],
            unmapped_departments: [],
            approver_options: [],
        } satisfies AreasPage,
    },

    // Usuarios
    'users-selected': { page: 'Admin/Users/Index', label: 'Usuarios · Edward escogido', props: usersProps },
    'users-list': {
        page: 'Admin/Users/Index',
        label: 'Usuarios · sin escoger',
        props: { ...usersProps, selected: null } satisfies UsersPage,
    },
    'users-local': {
        page: 'Admin/Users/Index',
        label: 'Usuarios · cuenta de emergencia',
        props: { ...usersProps, selected: EMERGENCY } satisfies UsersPage,
    },
    'users-no-results': {
        page: 'Admin/Users/Index',
        label: 'Usuarios · búsqueda sin resultados',
        props: {
            ...usersProps,
            users: paginate([], 0, 'admin.users.index'),
            filters: { q: 'Gutiérrez', role: 'Líder IA' },
            selected: null,
        } satisfies UsersPage,
    },

    // Roles
    roles: { page: 'Admin/Roles/Index', label: 'Roles y permisos', props: rolesProps },
    'roles-custom': {
        page: 'Admin/Roles/Index',
        label: 'Roles · con un rol agregado',
        props: {
            ...rolesProps,
            roles: [
                ...DEFAULT_ROLES,
                {
                    name: 'Auditor',
                    label: 'Auditor',
                    scope: 'Global',
                    system: false,
                    permissions: ['ai.costs.view', 'companies.view_all'],
                },
            ],
        } satisfies RolesPage,
    },

    // Integraciones
    integrations: { page: 'Admin/Integrations/Index', label: 'Integraciones · como hoy', props: integrationsProps },
    'integrations-all-ok': {
        page: 'Admin/Integrations/Index',
        label: 'Integraciones · todo conectado',
        props: {
            ...integrationsProps,
            microsoft: {
                companies: [
                    { company_id: SEGUREX.id, status: 'ok', last_sync_at: '2026-10-07T06:00:00-05:00', synced_count: 142, error: null },
                    { company_id: DERIVADOS.id, status: 'ok', last_sync_at: '2026-10-07T06:00:00-05:00', synced_count: 72, error: null },
                ],
            },
            mail: { ...integrationsProps.mail, status: 'ok', transport: 'graph', note: null },
            github: {
                status: 'connected',
                organization: '[ORGANIZACIÓN DE GITHUB]',
                install_url: 'https://github.com/apps/[APP DEL GESTOR]/installations/new',
                repositories_count: 6,
            },
            ai: { ...integrationsProps.ai, month_spend_usd: 8.6 },
        } satisfies IntegrationsPage,
    },
    'integrations-capped': {
        page: 'Admin/Integrations/Index',
        label: 'Integraciones · tope de IA alcanzado, Telegram caído',
        props: {
            ...integrationsProps,
            telegram: { ...integrationsProps.telegram, status: 'error', webhook_ok: false },
            ai: { ...integrationsProps.ai, status: 'capped', month_spend_usd: 10.2 },
        } satisfies IntegrationsPage,
    },
    'integrations-no-cap': {
        page: 'Admin/Integrations/Index',
        label: 'Integraciones · IA sin tope ni nada probado',
        props: {
            ...integrationsProps,
            microsoft: {
                companies: [
                    { company_id: SEGUREX.id, status: 'never', last_sync_at: null, synced_count: null, error: null },
                    { company_id: DERIVADOS.id, status: 'never', last_sync_at: null, synced_count: null, error: null },
                ],
            },
            telegram: { status: 'off', bot_username: null, linked_users: 0, last_message_at: null, webhook_ok: false },
            ai: { ...integrationsProps.ai, status: 'off', monthly_cap_usd: null, month_spend_usd: 0 },
        } satisfies IntegrationsPage,
    },

    // Auditoría
    audit: { page: 'Admin/Audit/Index', label: 'Auditoría · últimos 7 días', props: auditProps },
    'audit-sensitive': {
        page: 'Admin/Audit/Index',
        label: 'Auditoría · solo acciones sensibles',
        props: {
            ...auditProps,
            entries: paginate(
                AUDIT_ENTRIES.filter((entry) => entry.sensitive),
                41,
                'admin.audit.index',
            ),
            filters: { ...auditProps.filters, sensitive_only: true },
        } satisfies AuditPage,
    },
    'audit-empty': {
        page: 'Admin/Audit/Index',
        label: 'Auditoría · sin resultados',
        props: {
            ...auditProps,
            entries: paginate([], 0, 'admin.audit.index'),
            filters: { causer_id: 104, module: 'credentials', sensitive_only: true, range: '24h' },
        } satisfies AuditPage,
    },
};
