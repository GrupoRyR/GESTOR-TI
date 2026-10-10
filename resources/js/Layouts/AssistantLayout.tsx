import type { ReactNode } from 'react';
import { Tabs, type TabItem } from '@/Components/ui/Tabs';
import { can } from '@/lib/can';
import { route } from '@/lib/route';
import type { Permission, SharedProps } from '@/types/shared';
import { AppLayout } from './AppLayout';
import { Breadcrumb, type Crumb } from './GestorLayout';

export type AssistantTab = 'chat' | 'knowledge' | 'import' | 'drafts' | 'unanswered';

export interface AssistantTabRoute {
    label: string;
    route: string;
    permission: Permission;
}

/**
 * Asistente TI tabs (board C-AssistantTabs). The chat needs `assistant.use`;
 * cards, import, drafts and unanswered questions need
 * `assistant.knowledge.manage`.
 */
export const ASSISTANT_TAB_ROUTES: Record<AssistantTab, AssistantTabRoute> = {
    chat: { label: 'Chat', route: 'assistant.index', permission: 'assistant.use' },
    knowledge: { label: 'Base de conocimiento', route: 'assistant.knowledge.index', permission: 'assistant.knowledge.manage' },
    import: { label: 'Importar desde Excel', route: 'assistant.import.create', permission: 'assistant.knowledge.manage' },
    drafts: { label: 'Borradores de /aprender', route: 'assistant.drafts.index', permission: 'assistant.knowledge.manage' },
    unanswered: { label: 'Preguntas sin respuesta', route: 'assistant.unanswered.index', permission: 'assistant.knowledge.manage' },
};

export function assistantTabsFor(shared: SharedProps, counts: AssistantLayoutProps['counts'] = {}): TabItem[] {
    const user = shared.auth.user;
    return (Object.keys(ASSISTANT_TAB_ROUTES) as AssistantTab[])
        .filter((key) => can(user, ASSISTANT_TAB_ROUTES[key].permission))
        .map((key) => {
            const count = counts[key];
            return {
                key,
                label: ASSISTANT_TAB_ROUTES[key].label,
                href: route(ASSISTANT_TAB_ROUTES[key].route),
                count: count !== undefined && count > 0 ? count : undefined,
            };
        });
}

export interface AssistantLayoutProps {
    shared: SharedProps;
    title: string;
    /** Current tab; omit on pages outside the tabs. */
    tab?: AssistantTab;
    /** Counts after tab labels: `{ drafts: 3, unanswered: 7 }` (0 hides it). */
    counts?: Partial<Record<AssistantTab, number>>;
    breadcrumb?: Crumb[];
    headerActions?: ReactNode;
    badges?: Record<string, number>;
    /**
     * Phone (< 768 px) header that replaces the app top bar and the tabs, as
     * on C-ChatPhone and C-KnowledgePhone (see `AssistantPhoneHeader`).
     */
    phoneHeader?: ReactNode;
    /** Viewport-tall page that scrolls inside itself (the chat). */
    fullHeight?: boolean;
    children: ReactNode;
}

/**
 * AppLayout on the "assistant" module with the Asistente TI tab strip,
 * filtered by permission. A user with only `assistant.use` sees no strip (a
 * single tab is noise). On phone the tabs hide when a `phoneHeader` is given.
 */
export function AssistantLayout({ shared, title, tab, counts, breadcrumb, headerActions, badges, phoneHeader, fullHeight = false, children }: AssistantLayoutProps) {
    const items = assistantTabsFor(shared, counts);
    const tabs =
        items.length > 1 ? (
            <Tabs
                label="Secciones del Asistente TI"
                items={items}
                current={tab ?? ''}
                className={phoneHeader ? 'border-b border-line bg-surface px-2 max-md:hidden md:px-3 lg:px-6' : 'border-b border-line bg-surface px-2 md:px-3 lg:px-6'}
            />
        ) : undefined;

    return (
        <AppLayout
            shared={shared}
            title={title}
            active="assistant"
            badges={badges}
            headerActions={headerActions}
            subnav={tabs}
            phoneHeader={phoneHeader}
            fullHeight={fullHeight}
        >
            {breadcrumb && breadcrumb.length > 0 ? <Breadcrumb items={breadcrumb} /> : null}
            {children}
        </AppLayout>
    );
}

export default AssistantLayout;
