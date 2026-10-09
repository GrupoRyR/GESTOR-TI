import { Link } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { CompanyMark } from '@/Components/ui/CompanyMark';
import { PhaseRail } from '@/Components/ui/PhaseRail';
import { StatusChip } from '@/Components/ui/StatusChip';
import { Tabs, type TabItem } from '@/Components/ui/Tabs';
import { can } from '@/lib/can';
import { route } from '@/lib/route';
import { PHASE_LABELS, requestStatus } from '@/lib/status';
import type { Project } from '@/types/ai';
import type { SharedProps } from '@/types/shared';
import { GestorLayout, type Crumb } from './GestorLayout';

export type ProjectTab = 'summary' | 'board' | 'requirements' | 'costs' | 'docs' | 'report';

/**
 * Project sub-tabs and their routes (param `{ project: key }`). "Tiempo y
 * costos" only renders with `ai.costs.view`.
 */
export const PROJECT_TAB_ROUTES: Record<ProjectTab, { label: string; route: string }> = {
    summary: { label: 'Resumen', route: 'ai.projects.show' },
    board: { label: 'Tablero', route: 'ai.projects.board' },
    requirements: { label: 'Requerimientos', route: 'ai.projects.requirements' },
    costs: { label: 'Tiempo y costos', route: 'ai.projects.costs' },
    docs: { label: 'Documentación', route: 'ai.projects.docs' },
    report: { label: 'Informe', route: 'ai.projects.report' },
};

export type ProjectHeader = Pick<Project, 'key' | 'name' | 'status' | 'company_id' | 'area' | 'request_keys' | 'open_requirements'>;

export interface ProjectLayoutProps {
    shared: SharedProps;
    title: string;
    project: ProjectHeader;
    tab: ProjectTab;
    breadcrumb?: Crumb[];
    counts?: Parameters<typeof GestorLayout>[0]['counts'];
    badges?: Record<string, number>;
    headerActions?: ReactNode;
    children: ReactNode;
}

function ProjectHeaderBar({ shared, project, tab }: { shared: SharedProps; project: ProjectHeader; tab: ProjectTab }) {
    const company = shared.companies.find((item) => item.id === project.company_id);
    const phase = requestStatus(project.status).phase;
    const params = { project: project.key };
    const items: TabItem[] = (Object.keys(PROJECT_TAB_ROUTES) as ProjectTab[])
        .filter((key) => key !== 'costs' || can(shared.auth.user, 'ai.costs.view'))
        .map((key) => ({
            key,
            label: PROJECT_TAB_ROUTES[key].label,
            href: route(PROJECT_TAB_ROUTES[key].route, params),
            count: key === 'requirements' && project.open_requirements > 0 ? project.open_requirements : undefined,
            icon: key === 'costs' ? ('lock' as const) : undefined,
            srHint: key === 'costs' ? '(solo con permiso de costos)' : undefined,
        }));

    return (
        <div className="flex flex-col gap-2.5 border-b border-line bg-surface px-4 pt-3.5 md:px-5 lg:px-8">
            <div className="flex min-w-0 flex-wrap items-center gap-x-3 gap-y-2">
                {company ? <CompanyMark company={company} size={28} /> : null}
                <span className="text-sm font-bold text-fg-muted">{project.key}</span>
                <h1 className="min-w-0 truncate font-display text-xl leading-tight font-bold md:text-2xl">{project.name}</h1>
                <StatusChip status={project.status} size="sm" />
                <span className="hidden items-center gap-2 text-[13px] text-fg-muted sm:inline-flex">
                    <PhaseRail status={project.status} />
                    {PHASE_LABELS[phase]}
                </span>
                <span className="w-full text-[13px] whitespace-nowrap text-fg-soft lg:ml-auto lg:w-auto">
                    {company?.short_name ?? ''} · {project.area.name}
                    {project.request_keys.map((key) => (
                        <span key={key}>
                            {' · '}
                            <Link href={route('ai.requests.show', { request: key })} className="font-bold text-fg underline-offset-2 hover:underline">
                                {key}
                            </Link>
                        </span>
                    ))}
                </span>
            </div>
            <Tabs label="Secciones del proyecto" items={items} current={tab} tone="neutral" size="sm" className="-mx-1" />
        </div>
    );
}

/** GestorLayout on "Proyectos" plus the project header and its sub-tabs. */
export function ProjectLayout({ shared, title, project, tab, breadcrumb, counts, badges, headerActions, children }: ProjectLayoutProps) {
    return (
        <GestorLayout
            shared={shared}
            title={title}
            tab="projects"
            mode="it"
            counts={counts}
            badges={badges}
            breadcrumb={breadcrumb}
            headerActions={headerActions}
            subheader={<ProjectHeaderBar shared={shared} project={project} tab={tab} />}
        >
            {children}
        </GestorLayout>
    );
}

export default ProjectLayout;
