import { Link } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { Icon } from '@/Components/ui/Icon';
import { Tabs, type TabItem } from '@/Components/ui/Tabs';
import { can } from '@/lib/can';
import { route } from '@/lib/route';
import type { Permission, SharedProps } from '@/types/shared';
import { AppLayout, type BottomNavOverride } from './AppLayout';

export type GestorTab = 'inbox' | 'backlog' | 'areas' | 'projects' | 'products' | 'docs' | 'week' | 'value' | 'vault';

export interface GestorTabRoute {
    label: string;
    route: string;
    /** Extra permission on top of `ai.manage`. */
    permission?: Permission;
}

/**
 * Gestor IA section tabs and their routes. Every tab needs `ai.manage`;
 * Bóveda also needs `ai.vault.access`. Costs are never a tab: they live
 * inside each project ("Tiempo y costos") and in Administración.
 */
export const GESTOR_TAB_ROUTES: Record<GestorTab, GestorTabRoute> = {
    inbox: { label: 'Bandeja', route: 'ai.inbox.index' },
    backlog: { label: 'Backlog', route: 'ai.backlog.index' },
    areas: { label: 'Por área', route: 'ai.areas.index' },
    projects: { label: 'Proyectos', route: 'ai.projects.index' },
    products: { label: 'Productos', route: 'ai.products.index' },
    docs: { label: 'Documentación', route: 'ai.docs.index' },
    week: { label: 'Mi semana', route: 'ai.week.index' },
    value: { label: 'Valor', route: 'ai.value.index' },
    vault: { label: 'Bóveda', route: 'ai.vault.index', permission: 'ai.vault.access' },
};

export interface Crumb {
    label: string;
    /** Omit on the current page (last crumb). */
    href?: string;
}

export interface GestorLayoutProps {
    shared: SharedProps;
    title: string;
    /** Current tab (IT mode). Omit on pages outside the tabs (request detail…). */
    tab?: GestorTab;
    /**
     * `it` shows the section tabs; `requester` hides them (users without
     * `ai.manage`). Defaults from the user's permissions.
     */
    mode?: 'it' | 'requester';
    /** Counts after tab labels, e.g. `{ inbox: 5 }` (0 hides it). */
    counts?: Partial<Record<GestorTab, number>>;
    /** Breadcrumb above the content: Bandeja / GIA-148. */
    breadcrumb?: Crumb[];
    /** Full-width block under the tabs (ProjectLayout's project header). */
    subheader?: ReactNode;
    badges?: Record<string, number>;
    headerActions?: ReactNode;
    /** Requester mode: current item of the phone bar (Inicio · Solicitudes · Hoja de ruta · Perfil). */
    requesterNav?: RequesterNavKey;
    children: ReactNode;
}

export type RequesterNavKey = 'home' | 'mine' | 'roadmap' | 'profile';

/** Phone bottom bar of requesters (boards PhoneHome, B-MyRequests). */
export function requesterBottomNav(active?: RequesterNavKey): BottomNavOverride {
    return {
        label: 'Gestor IA',
        active,
        items: [
            { key: 'home', label: 'Inicio', icon: 'home', href: route('home') },
            { key: 'mine', label: 'Solicitudes', icon: 'list', href: route('ai.requests.mine') },
            { key: 'roadmap', label: 'Hoja de ruta', icon: 'calendar', href: route('ai.roadmap.index') },
            { key: 'profile', label: 'Perfil', icon: 'user', href: route('profile.edit') },
        ],
    };
}

export function gestorTabsFor(shared: SharedProps, counts: GestorLayoutProps['counts'] = {}): TabItem[] {
    const user = shared.auth.user;
    return (Object.keys(GESTOR_TAB_ROUTES) as GestorTab[])
        .filter((key) => {
            const permission = GESTOR_TAB_ROUTES[key].permission;
            return permission === undefined || can(user, permission);
        })
        .map((key) => {
            const count = counts[key];
            return {
                key,
                label: GESTOR_TAB_ROUTES[key].label,
                href: route(GESTOR_TAB_ROUTES[key].route),
                count: count !== undefined && count > 0 ? count : undefined,
                icon: key === 'vault' ? ('lock' as const) : undefined,
                srHint: key === 'vault' ? '(zona protegida)' : undefined,
            };
        });
}

export function Breadcrumb({ items }: { items: Crumb[] }) {
    const parent = [...items].reverse().find((item) => item.href !== undefined);
    return (
        <nav aria-label="Ruta" className="mb-4 flex min-w-0 items-center gap-1 text-[13px]">
            {parent?.href ? (
                <Link
                    href={parent.href}
                    aria-label={`Volver a ${parent.label}`}
                    className="-ml-2 flex size-11 shrink-0 items-center justify-center rounded-control text-fg-soft hover:bg-subtle md:hidden"
                >
                    <Icon name="chevron-left" size={18} />
                </Link>
            ) : null}
            <ol className="flex min-w-0 flex-wrap items-center gap-1">
                {items.map((item, index) => {
                    const last = index === items.length - 1;
                    return (
                        <li key={`${item.label}-${index}`} className="flex min-w-0 items-center gap-1">
                            {index > 0 ? (
                                <span aria-hidden="true" className="text-fg-muted">
                                    /
                                </span>
                            ) : null}
                            {item.href && !last ? (
                                <Link href={item.href} className="font-semibold text-fg-soft underline-offset-2 hover:text-fg hover:underline">
                                    {item.label}
                                </Link>
                            ) : (
                                <span aria-current={last ? 'page' : undefined} className="truncate font-bold text-fg">
                                    {item.label}
                                </span>
                            )}
                        </li>
                    );
                })}
            </ol>
        </nav>
    );
}

/**
 * AppLayout on the "ai" module. IT users get the Gestor IA tab strip
 * (filtered by permission); requesters get no tabs, only a breadcrumb, and
 * their own phone bar (Inicio · Solicitudes · Hoja de ruta · Perfil).
 */
export function GestorLayout({
    shared,
    title,
    tab,
    mode,
    counts,
    breadcrumb,
    subheader,
    badges,
    headerActions,
    requesterNav,
    children,
}: GestorLayoutProps) {
    const resolved = mode ?? (can(shared.auth.user, 'ai.manage') ? 'it' : 'requester');
    const tabs =
        resolved === 'it' ? (
            <div className="flex items-stretch border-b border-line bg-surface px-2 md:px-3 lg:pr-5 lg:pl-6">
                <Tabs label="Secciones del Gestor IA" items={gestorTabsFor(shared, counts)} current={tab ?? ''} className="min-w-0 flex-1" />
                {/* IT can still file a request the normal way (board B-ProposeEntry). */}
                {can(shared.auth.user, 'ai.manage') ? (
                    <Link
                        href={route('ai.requests.create')}
                        className="ml-2 hidden h-11 shrink-0 items-center self-center rounded-control px-3 text-sm font-semibold whitespace-nowrap text-fg-soft underline underline-offset-[3px] hover:bg-subtle hover:text-fg lg:inline-flex"
                    >
                        Pedir un desarrollo
                    </Link>
                ) : null}
            </div>
        ) : null;

    return (
        <AppLayout
            shared={shared}
            title={title}
            active="ai"
            badges={badges}
            headerActions={headerActions}
            bottomNav={resolved === 'requester' ? requesterBottomNav(requesterNav) : undefined}
            subnav={
                tabs || subheader ? (
                    <>
                        {tabs}
                        {subheader}
                    </>
                ) : undefined
            }
        >
            {breadcrumb && breadcrumb.length > 0 ? <Breadcrumb items={breadcrumb} /> : null}
            {children}
        </AppLayout>
    );
}

export default GestorLayout;
