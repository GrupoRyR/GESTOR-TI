/**
 * Shared-props mocks for the backend-free preview and future tests: the two
 * real companies plus a logo-less test brand, the module list, three users
 * and helpers that assemble `SharedProps` / `GuestSharedProps`.
 */
import logoDerivadosDark from '../../brand/logo-derivados-dark.svg?url';
import logoDerivados from '../../brand/logo-derivados.svg?url';
import logoSegurexDark from '../../brand/logo-segurex-dark.svg?url';
import logoSegurex from '../../brand/logo-segurex.svg?url';
import type { AuthUser, Company, Flash, GuestSharedProps, ModuleItem, Permission, SharedProps } from '@/types/shared';

// ---- variants -------------------------------------------------------------

/**
 * One gallery entry: which page renders (Inertia component name), a Spanish
 * label and the full props it receives. Every `mocks/{module}.ts` exports
 * `variants: Record<string, MockVariant<…>>`.
 */
export interface MockVariant<P> {
    page: string;
    label: string;
    props: P;
}

// ---- companies ------------------------------------------------------------

export const SEGUREX: Company = {
    id: 1,
    slug: 'segurex',
    name: 'Segurex Latam',
    short_name: 'Segurex',
    logo_url: logoSegurex,
    logo_dark_url: logoSegurexDark,
    colors: { primary: '#FF671F', accent: '#666666', dark: '#8F360B' },
};

export const DERIVADOS: Company = {
    id: 2,
    slug: 'derivados',
    name: 'Soluciones y Derivados de Colombia S.A.S.',
    short_name: 'Derivados',
    logo_url: logoDerivados,
    logo_dark_url: logoDerivadosDark,
    colors: { primary: '#183F94', accent: '#6D94E8', dark: '#03256C' },
};

/** Test brand without a logo, to check the letter-mark fallback and brand derivation. */
export const ANDINA: Company = {
    id: 3,
    slug: 'andina',
    name: 'Andina Pruebas S.A.S.',
    short_name: 'Andina',
    logo_url: null,
    logo_dark_url: null,
    colors: { primary: '#0F8A7E', accent: '#3FBFAE', dark: '#0A4F48' },
};

export const COMPANIES: Company[] = [SEGUREX, DERIVADOS];

// ---- modules --------------------------------------------------------------

export const MODULES: ModuleItem[] = [
    { key: 'home', name: 'Inicio', icon: 'home', route: 'home', active: true },
    { key: 'ai', name: 'Gestor IA', icon: 'ai', route: 'ai.index', active: true },
    { key: 'assistant', name: 'Asistente', icon: 'assistant', route: 'assistant.index', active: true },
    { key: 'employees', name: 'Colaboradores', icon: 'employees', route: 'employees.index', active: true },
    { key: 'admin', name: 'Administración', icon: 'admin', route: 'admin.companies.index', active: true },
    { key: 'monitoring', name: 'Monitoreo', icon: 'monitoring', route: 'monitoring.index', active: false },
    { key: 'tickets', name: 'Tickets', icon: 'tickets', route: 'tickets.index', active: false },
    { key: 'inventory', name: 'Inventario', icon: 'inventory', route: 'inventory.index', active: false },
    { key: 'operations', name: 'Operación', icon: 'operations', route: 'operations.index', active: false },
];

/** A final user only gets the modules their permissions open. */
const REQUESTER_MODULES = MODULES.filter((module) => module.key === 'home' || module.key === 'ai' || !module.active);

// ---- users ----------------------------------------------------------------

const ALL_PERMISSIONS: Permission[] = [
    'ai.manage',
    'ai.requests.create',
    'ai.requests.approve_area',
    'ai.projects.approve',
    'ai.costs.view',
    'ai.costs.manage',
    'ai.area.view',
    'ai.docs.edit',
    'ai.github.manage',
    'ai.mcp.use',
    'assistant.use',
    'assistant.knowledge.manage',
    'assistant.credentials',
    'credentials.view',
    'companies.view_all',
    'ai.vault.access',
];

const without = (...excluded: Permission[]) => ALL_PERMISSIONS.filter((permission) => !excluded.includes(permission));

export type MockUserKey = 'jhon' | 'leonardo' | 'edward' | 'andres' | 'paola';

export const USERS: Record<MockUserKey, AuthUser> = {
    /** Líder IA + Súper Admin + Aprobador de proyectos: the only one with the vault. */
    jhon: {
        id: 1,
        name: 'Jhon Castro',
        email: 'jhon.castro@segurex.com',
        avatar_url: null,
        company_id: SEGUREX.id,
        roles: ['Súper Admin', 'Líder IA', 'Aprobador de proyectos'],
        permissions: [...ALL_PERMISSIONS],
    },
    /** Súper Admin: everything except the vault and project approval. */
    leonardo: {
        id: 2,
        name: 'Leonardo Herrera',
        email: 'leonardo.herrera@segurex.com',
        avatar_url: null,
        company_id: SEGUREX.id,
        roles: ['Súper Admin'],
        permissions: without('ai.vault.access', 'ai.projects.approve'),
    },
    /** Aprobador de proyectos (Gerencia): approves from Telegram, sees costs, no IT tabs. */
    edward: {
        id: 3,
        name: 'Edward Ramanan',
        email: 'edward.ramanan@segurex.com',
        avatar_url: null,
        company_id: SEGUREX.id,
        roles: ['Aprobador de proyectos'],
        permissions: ['ai.requests.create', 'ai.projects.approve', 'ai.costs.view', 'companies.view_all'],
    },
    /** Usuario final of Derivados: can only create requests. */
    andres: {
        id: 7,
        name: 'Andrés Méndez',
        email: 'andres.mendez@derivadoscol.com',
        avatar_url: null,
        company_id: DERIVADOS.id,
        roles: ['Usuario final'],
        permissions: ['ai.requests.create'],
    },
    /** Usuario final of Segurex (Ventas): asks, votes, validates and rates. */
    paola: {
        id: 4,
        name: 'Paola Ríos',
        email: 'paola.rios@segurex.com',
        avatar_url: null,
        company_id: SEGUREX.id,
        roles: ['Usuario final'],
        permissions: ['ai.requests.create'],
    },
};

// ---- builders -------------------------------------------------------------

export interface SharedOptions {
    /** `null` = "Todas". Defaults to "Todas" for users who see every company, else their own. */
    currentCompanyId?: number | null;
    flash?: Flash;
    notificationsUnread?: number;
    /** Override the company list (e.g. `[ANDINA, …]` to test a brand). */
    companies?: Company[];
}

export function sharedFor(userKey: MockUserKey, options: SharedOptions = {}): SharedProps {
    const user = USERS[userKey];
    const viewsAll = user.permissions.includes('companies.view_all');
    /** Without `ai.manage` (Andrés, Paola, Edward) only the requester modules show. */
    const requester = !user.permissions.includes('ai.manage');
    const companies =
        options.companies ?? (viewsAll ? COMPANIES : COMPANIES.filter((company) => company.id === user.company_id));

    return {
        auth: { user },
        companies,
        current_company_id: options.currentCompanyId !== undefined ? options.currentCompanyId : viewsAll ? null : user.company_id,
        flash: options.flash ?? {},
        notifications_unread: options.notificationsUnread ?? (requester ? 1 : 4),
        modules: requester ? REQUESTER_MODULES : MODULES,
    };
}

export function guestShared(options: { flash?: Flash; companies?: Company[] } = {}): GuestSharedProps {
    return {
        auth: { user: null },
        companies: options.companies ?? COMPANIES,
        flash: options.flash ?? {},
    };
}
