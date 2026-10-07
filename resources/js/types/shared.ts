/**
 * Shared Inertia props contract. Laravel's HandleInertiaRequests must send
 * exactly this shape on every request; pages receive it merged with their
 * own props through `PageProps<T>`.
 */

export type Permission =
    | 'ai.manage'
    | 'ai.requests.create'
    | 'ai.requests.approve_area'
    | 'ai.projects.approve'
    | 'ai.costs.view'
    | 'ai.costs.manage'
    | 'ai.area.view'
    | 'ai.docs.edit'
    | 'ai.github.manage'
    | 'ai.mcp.use'
    | 'assistant.use'
    | 'assistant.knowledge.manage'
    | 'assistant.credentials'
    | 'credentials.view'
    | 'companies.view_all'
    | 'ai.vault.access';

export interface AuthUser {
    id: number;
    name: string;
    email: string;
    avatar_url: string | null;
    company_id: number;
    roles: string[];
    permissions: string[];
}

export interface CompanyColors {
    primary: string;
    accent: string;
    dark: string;
}

export interface Company {
    id: number;
    slug: string;
    name: string;
    short_name: string;
    logo_url: string | null;
    logo_dark_url: string | null;
    colors: CompanyColors;
}

export interface ModuleItem {
    key: string;
    name: string;
    icon: string;
    route: string;
    active: boolean;
}

export interface Flash {
    success?: string;
    error?: string;
}

export interface SharedProps {
    auth: { user: AuthUser };
    companies: Company[];
    current_company_id: number | null;
    flash: Flash;
    notifications_unread: number;
    modules: ModuleItem[];
}

// eslint-disable-next-line @typescript-eslint/no-empty-object-type
export type PageProps<T = {}> = SharedProps & T;

export interface Paginated<T> {
    data: T[];
    links: { url: string | null; label: string; active: boolean }[];
    meta: {
        current_page: number;
        last_page: number;
        per_page: number;
        total: number;
    };
}

export type FormErrors = Record<string, string>;
