import type { ReactNode } from 'react';
import { Tabs, type TabItem } from '@/Components/ui/Tabs';
import { route } from '@/lib/route';
import type { SharedProps } from '@/types/shared';
import { AppLayout } from './AppLayout';

export type AdminTab = 'companies' | 'areas' | 'users' | 'roles' | 'integrations' | 'audit';

/** Route names behind each Administración tab. */
export const ADMIN_TAB_ROUTES: Record<AdminTab, { label: string; route: string }> = {
    companies: { label: 'Empresas', route: 'admin.companies.index' },
    areas: { label: 'Áreas', route: 'admin.areas.index' },
    users: { label: 'Usuarios', route: 'admin.users.index' },
    roles: { label: 'Roles y permisos', route: 'admin.roles.index' },
    integrations: { label: 'Integraciones', route: 'admin.integrations.index' },
    audit: { label: 'Auditoría', route: 'admin.audit.index' },
};

export interface AdminLayoutProps {
    shared: SharedProps;
    title: string;
    /** Current tab. */
    tab: AdminTab;
    badges?: Record<string, number>;
    headerActions?: ReactNode;
    children: ReactNode;
}

/** AppLayout on the "admin" module with the Administración section tabs. */
export function AdminLayout({ shared, title, tab, badges, headerActions, children }: AdminLayoutProps) {
    const items: TabItem[] = (Object.keys(ADMIN_TAB_ROUTES) as AdminTab[]).map((key) => ({
        key,
        label: ADMIN_TAB_ROUTES[key].label,
        href: route(ADMIN_TAB_ROUTES[key].route),
    }));

    return (
        <AppLayout
            shared={shared}
            title={title}
            active="admin"
            badges={badges}
            headerActions={headerActions}
            subnav={
                <Tabs
                    label="Secciones de Administración"
                    items={items}
                    current={tab}
                    className="border-b border-line bg-surface px-2 md:px-3 lg:px-6"
                />
            }
        >
            {children}
        </AppLayout>
    );
}

export default AdminLayout;
