import { Link, router } from '@inertiajs/react';
import { budgetLines, wipText } from '@/Components/ai/projects/projects';
import { FilterSelect } from '@/Components/ai/triage/InboxParts';
import { WipChip } from '@/Components/ai/KanbanColumn';
import { Avatar } from '@/Components/ui/Avatar';
import { BudgetBar } from '@/Components/ui/BudgetBar';
import { Button } from '@/Components/ui/Button';
import { CompanyMark } from '@/Components/ui/CompanyMark';
import { EmptyState } from '@/Components/ui/EmptyState';
import { Icon } from '@/Components/ui/Icon';
import { PhaseRail } from '@/Components/ui/PhaseRail';
import { StatusChip } from '@/Components/ui/StatusChip';
import { GestorLayout } from '@/Layouts/GestorLayout';
import { can } from '@/lib/can';
import { formatShortDate } from '@/lib/format';
import { hasRoute, route } from '@/lib/route';
import { PHASE_LABELS, requestStatus } from '@/lib/status';
import type { ProjectStatus } from '@/types/ai';
import type { ProjectFilters, ProjectListItem, ProjectsIndexProps } from '@/types/pages/ai-projects';
import type { Company, PageProps } from '@/types/shared';

const ALL = 'all';

const STATUS_OPTIONS: { value: ProjectStatus | typeof ALL; label: string }[] = [
    { value: ALL, label: 'todos' },
    { value: 'planned', label: 'Planificada' },
    { value: 'in_development', label: 'En desarrollo' },
    { value: 'in_testing', label: 'En pruebas' },
    { value: 'in_production', label: 'En producción' },
    { value: 'closed', label: 'Cerrada' },
];

function toQuery(filters: ProjectFilters): Record<string, string | number> {
    const query: Record<string, string | number> = {};
    if (filters.status !== null) query.status = filters.status;
    if (filters.company_id !== null) query.company_id = filters.company_id;
    return query;
}

function ProjectRow({ project, company, showCompany, canViewCosts }: { project: ProjectListItem; company: Company | undefined; showCompany: boolean; canViewCosts: boolean }) {
    const href = route('ai.projects.show', { project: project.key });
    const total = Object.values(project.tasks_by_column).reduce((sum, count) => sum + count, 0);
    const lines = budgetLines(project.budget);
    const phase = requestStatus(project.status).phase;
    const lead = project.team[0];

    return (
        <article className="relative grid gap-x-5 gap-y-3 rounded-card border border-line bg-surface p-4 md:p-5 lg:grid-cols-[minmax(0,1fr)_220px_260px] lg:items-center">
            <div className="flex min-w-0 flex-col gap-1.5">
                <div className="flex flex-wrap items-center gap-2">
                    {showCompany && company ? <CompanyMark company={company} size={22} /> : null}
                    <span className="tabular text-[13px] font-bold text-fg-muted">{project.key}</span>
                    <StatusChip status={project.status} size="sm" />
                </div>
                <Link href={href} className="font-display text-lg leading-tight font-bold text-fg no-underline after:absolute after:inset-0 after:rounded-card hover:underline">
                    {project.name}
                </Link>
                <p className="text-[13px] text-fg-muted">
                    {project.area.name}
                    {project.request_keys.length > 0 ? ` · ${project.request_keys.join(', ')}` : ''}
                    {lead ? ` · lidera ${lead.person.name}` : ''}
                </p>
            </div>

            <div className="flex flex-col gap-2 text-[13px]">
                <span className="flex items-center gap-2 text-fg-soft">
                    <PhaseRail status={project.status} />
                    {PHASE_LABELS[phase]}
                </span>
                <span className="flex flex-wrap items-center gap-x-3 gap-y-1 text-fg-muted">
                    <span className="tabular">
                        {project.tasks_by_column.done} de {total} tareas hechas
                    </span>
                    {project.blocked_tasks > 0 ? (
                        <span className="inline-flex items-center gap-1 font-semibold text-status-failure">
                            <Icon name="blocked" size={13} />
                            {project.blocked_tasks} {project.blocked_tasks === 1 ? 'bloqueada' : 'bloqueadas'}
                        </span>
                    ) : null}
                    {project.open_requirements > 0 ? (
                        <span className="tabular">
                            {project.open_requirements} {project.open_requirements === 1 ? 'requerimiento abierto' : 'requerimientos abiertos'}
                        </span>
                    ) : null}
                </span>
            </div>

            <div className="flex flex-col gap-1.5">
                {canViewCosts && project.budget.cost ? (
                    <BudgetBar label="Horas" value={project.budget.hours.actual} max={project.budget.hours.estimate} valueText={lines.hours.value} />
                ) : (
                    <span className="tabular text-[13px] text-fg-soft">
                        <b className="text-fg">{lines.hours.value}</b> registradas
                    </span>
                )}
                <span className={lines.date.late > 0 ? 'text-[13px] font-semibold text-status-failure' : 'text-[13px] text-fg-muted'}>
                    Entrega {formatShortDate(project.budget.projected_due_date)}
                    {lines.date.late > 0 ? ` · ${lines.date.message}` : ' · a tiempo'}
                </span>
                <span className="relative z-10 mt-1 flex items-center gap-1 lg:hidden">
                    {project.team.map((member) => (
                        <Avatar key={member.person.id} name={member.person.name} size="xs" decorative={false} />
                    ))}
                </span>
            </div>
        </article>
    );
}

/** Proyectos: compact list of every project with its phase, progress and (with costs) the hours bar. */
export default function ProjectsIndex(props: PageProps<ProjectsIndexProps>) {
    const { projects, filters, wip, companies, current_company_id, auth } = props;
    const allCompanies = current_company_id === null;
    const canViewCosts = can(auth.user, 'ai.costs.view');
    const filtered = filters.status !== null || filters.company_id !== null;

    const apply = (change: Partial<ProjectFilters>) => {
        router.get(route('ai.projects.index'), toQuery({ ...filters, ...change }), { preserveState: true, preserveScroll: true });
    };

    return (
        <GestorLayout shared={props} title="Proyectos · Gestor IA" tab="projects" mode="it">
            <div className="flex flex-col gap-4">
                <div className="flex flex-wrap items-end justify-between gap-3">
                    <div className="flex flex-col gap-1">
                        <h1 className="font-display text-[26px] leading-tight font-bold tracking-[-0.02em] md:text-[30px]">Proyectos</h1>
                        <p className="flex flex-wrap items-center gap-2 text-sm text-fg-soft">
                            <WipChip current={wip.in_development} limit={wip.limit} />
                            <span>{wipText(wip)}: para empezar otro, uno debe pasar a pruebas.</span>
                        </p>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        <Button variant="secondary" icon="list" href={route('ai.backlog.index')}>
                            Ver backlog
                        </Button>
                        {can(auth.user, 'ai.manage') && hasRoute('ai.proposals.create') ? (
                            <Button variant="dark" icon="plus" href={route('ai.proposals.create')}>
                                Proponer proyecto
                            </Button>
                        ) : null}
                    </div>
                </div>

                <div className="flex flex-wrap gap-2">
                    <FilterSelect
                        label="Estado"
                        value={filters.status ?? ALL}
                        options={STATUS_OPTIONS}
                        onChange={(value) => apply({ status: value === ALL ? null : (value as ProjectStatus) })}
                    />
                    {allCompanies ? (
                        <FilterSelect
                            label="Empresa"
                            value={filters.company_id !== null ? String(filters.company_id) : ALL}
                            options={[{ value: ALL, label: 'todas' }, ...companies.map((company) => ({ value: String(company.id), label: company.short_name }))]}
                            onChange={(value) => apply({ company_id: value === ALL ? null : Number(value) })}
                        />
                    ) : null}
                </div>

                {projects.length === 0 ? (
                    <EmptyState
                        icon="list"
                        title={filtered ? 'Ningún proyecto con estos filtros' : 'Todavía no hay proyectos'}
                        description={
                            filtered
                                ? 'Cambia el estado o la empresa para ver otros proyectos.'
                                : 'Un proyecto nace cuando una solicitud aprobada se convierte en proyecto desde la bandeja o el backlog.'
                        }
                        actions={
                            filtered ? (
                                <Button variant="secondary" onClick={() => apply({ status: null, company_id: null })}>
                                    Quitar filtros
                                </Button>
                            ) : (
                                <Button variant="secondary" href={route('ai.backlog.index')}>
                                    Ir al backlog
                                </Button>
                            )
                        }
                    />
                ) : (
                    <ul className="flex flex-col gap-3">
                        {projects.map((project) => (
                            <li key={project.key}>
                                <ProjectRow
                                    project={project}
                                    company={companies.find((company) => company.id === project.company_id)}
                                    showCompany={allCompanies}
                                    canViewCosts={canViewCosts}
                                />
                            </li>
                        ))}
                    </ul>
                )}

                {projects.length > 0 ? (
                    <p className="text-[13px] text-fg-muted">
                        {projects.length} {projects.length === 1 ? 'proyecto' : 'proyectos'}
                        {canViewCosts ? ' · la barra marca el 80 % y el 100 % de las horas aprobadas' : ''}
                    </p>
                ) : null}
            </div>
        </GestorLayout>
    );
}
