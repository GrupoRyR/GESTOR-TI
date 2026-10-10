import { Link, router } from '@inertiajs/react';
import { useCallback, useMemo, useState, type ReactNode } from 'react';
import { BudgetBars, CostsLock, PersonLine, ReapprovalBanner } from '@/Components/ai/projects/ProjectParts';
import { reapprovalReasons, reapprovalSentence, requirementState } from '@/Components/ai/projects/projects';
import { columnLabel } from '@/Components/ai/KanbanColumn';
import { BriefPanel } from '@/Components/brief/BriefPanel';
import { BriefTrigger } from '@/Components/brief/BriefTrigger';
import { RepoCreateFooter } from '@/Components/brief/RepoCreateFooter';
import { RepoLinkDialog } from '@/Components/github/RepoLinkDialog';
import { Avatar } from '@/Components/ui/Avatar';
import { Button } from '@/Components/ui/Button';
import { CompanyMark } from '@/Components/ui/CompanyMark';
import { Icon, type IconName } from '@/Components/ui/Icon';
import { cx } from '@/Components/ui/utils';
import { ProjectLayout } from '@/Layouts/ProjectLayout';
import { ALL_BRIEF_SECTIONS, briefFileName, buildClaudeMd, buildProjectBrief, claudeMdInputFromProject, omittedNote, type BriefSectionKey } from '@/lib/brief';
import { can } from '@/lib/can';
import { formatLongDate, formatRelative, formatShortDate } from '@/lib/format';
import { route } from '@/lib/route';
import { PHASE_LABELS, requestStatus } from '@/lib/status';
import type { KanbanColumnKey } from '@/types/ai';
import type { ActivityKind, ProjectsShowProps } from '@/types/pages/ai-projects';
import type { PageProps } from '@/types/shared';

const number = (value: number) => value.toLocaleString('es-CO', { maximumFractionDigits: 1 });

/** Board order of the summary bar: what is finished first. */
const PROGRESS_ORDER: KanbanColumnKey[] = ['done', 'review', 'in_progress', 'todo', 'backlog'];

const PROGRESS_TONE: Record<KanbanColumnKey, string> = {
    done: 'bg-fg',
    review: 'bg-status-maintenance-dot',
    in_progress: 'bg-status-scheduled',
    todo: 'bg-line-strong',
    backlog: 'bg-line',
};

const ACTIVITY_ICON: Record<ActivityKind, IconName> = {
    task: 'move',
    github: 'pull-request',
    requirement: 'message',
    budget: 'bell',
    comment: 'chat',
};

function Card({ title, aside, children, className }: { title: ReactNode; aside?: ReactNode; children: ReactNode; className?: string }) {
    return (
        <section className={cx('flex min-w-0 flex-col gap-3.5 rounded-card border border-line bg-surface p-4 md:p-5', className)}>
            <div className="flex flex-wrap items-center justify-between gap-x-3 gap-y-1">
                <h2 className="text-base font-bold">{title}</h2>
                {aside}
            </div>
            {children}
        </section>
    );
}

function DateRow({ label, value, tone }: { label: string; value: string; tone?: 'late' }) {
    return (
        <div className="flex items-baseline justify-between gap-3 text-[13px]">
            <dt className="text-fg-muted">{label}</dt>
            <dd className={cx('tabular font-semibold', tone === 'late' && 'text-status-failure')}>{value}</dd>
        </div>
    );
}

/**
 * B-ProjectSummary: objective, linked request, team and dates, board progress, budget, criteria, requirements, activity.
 * Stage D: "Brief para Claude" in the project header (D-BriefProject) with the "CLAUDE.md inicial" tab and, for
 * `ai.github.manage` without a repository, "Crear repositorio con este CLAUDE.md".
 */
export default function ProjectsShow(props: PageProps<ProjectsShowProps>) {
    const { project, origin_request, open_requirements, activity, approvers, reapproval_threshold, reapproval_requested_at, next_step, today, auth, companies } = props;
    const now = new Date(props.now);
    const canViewCosts = can(auth.user, 'ai.costs.view');
    const company = companies.find((item) => item.id === project.company_id);
    const [requesting, setRequesting] = useState(false);
    const repoLink = can(auth.user, 'ai.github.manage') ? (props.repo_link ?? null) : null;
    const [linkOpen, setLinkOpen] = useState(repoLink?.open ?? false);
    const brief = props.brief ?? null;
    const briefState = props.brief_state ?? null;
    const [briefOpen, setBriefOpen] = useState(brief !== null && briefState?.open === true);
    const repoCreate = can(auth.user, 'ai.github.manage') && !project.repository ? (props.repo_create ?? null) : null;
    const buildBrief = useCallback((sections: BriefSectionKey[]) => (brief ? buildProjectBrief(brief, sections) : ''), [brief]);
    const claudeMarkdown = useMemo(() => (brief ? buildClaudeMd(claudeMdInputFromProject(brief)) : ''), [brief]);
    const { budget } = project;
    const reasons = reapprovalReasons(budget, reapproval_threshold);
    const needsReapproval = reasons.length > 0 || budget.needs_reapproval;
    const tasksTotal = Object.values(project.tasks_by_column).reduce((sum, count) => sum + count, 0);
    const criteriaDone = project.criteria.filter((criterion) => criterion.validated !== null).length;
    const phase = requestStatus(project.status).phase;
    const board = route('ai.projects.board', { project: project.key });

    const detail = reasons.includes('date')
        ? `La entrega proyectada es el ${formatShortDate(budget.projected_due_date)} y se aprobó para el ${formatShortDate(budget.due_date)}.`
        : reasons.includes('hours')
          ? `Van ${number(budget.hours.actual)} h de ${number(budget.hours.estimate)} h aprobadas.`
          : 'Lo real ya supera lo aprobado.';

    const requestReapproval = () =>
        router.post(route('ai.projects.reapproval.store', { project: project.key }), {}, { preserveScroll: true, onStart: () => setRequesting(true), onFinish: () => setRequesting(false) });

    return (
        <ProjectLayout
            shared={props}
            title={`${project.key} ${project.name} · Gestor IA`}
            project={project}
            tab="summary"
            projectActions={brief ? <BriefTrigger expanded={briefOpen} onOpen={() => setBriefOpen(true)} /> : null}
        >
            <div className="flex flex-col gap-4">
                {needsReapproval ? (
                    <ReapprovalBanner
                        sentence={reapprovalSentence(reasons, reapproval_threshold)}
                        detail={detail}
                        approvers={approvers}
                        requestedAt={reapproval_requested_at}
                        requestedLabel={reapproval_requested_at ? formatRelative(reapproval_requested_at, now) : null}
                        onRequest={requestReapproval}
                        processing={requesting}
                    />
                ) : null}

                <div className="grid grid-cols-1 gap-4 xl:grid-cols-[minmax(0,1.1fr)_minmax(0,1fr)_320px] xl:items-start">
                    {/* Column 1: what and who */}
                    <div className="flex min-w-0 flex-col gap-4">
                        <Card title="Objetivo">
                            <p className="text-[15px] leading-normal">{project.objective}</p>
                            {origin_request ? (
                                <Link
                                    href={route('ai.requests.show', { request: origin_request.key })}
                                    className="flex flex-col gap-1 rounded-[12px] border border-line bg-subtle p-3.5 text-fg no-underline hover:border-line-strong"
                                >
                                    <span className="flex items-center gap-2 text-[13px] text-fg-muted">
                                        <b className="tabular text-fg">{origin_request.key}</b>
                                        Solicitud que originó el proyecto
                                        <Icon name="external" size={13} className="ml-auto" />
                                    </span>
                                    <span className="text-sm font-bold">{origin_request.title}</span>
                                    <span className="text-[13px] text-fg-soft">
                                        Pedida por {origin_request.requester.name}
                                        {origin_request.hours_per_week !== null && origin_request.people !== null
                                            ? ` · ${number(origin_request.hours_per_week)} h/sem × ${origin_request.people} personas`
                                            : ''}
                                        {origin_request.votes > 0 ? ` · +${origin_request.votes}` : ''}
                                    </span>
                                    {origin_request.approved_by && origin_request.approved_at ? (
                                        <span className="text-[13px] text-fg-muted">
                                            Aprobada por {origin_request.approved_by.name} el {formatShortDate(origin_request.approved_at)}
                                            {origin_request.priority_final !== null ? ` · prioridad ${origin_request.priority_final}` : ''}
                                            {origin_request.size ? ` · talla ${origin_request.size}` : ''}
                                        </span>
                                    ) : null}
                                </Link>
                            ) : null}
                        </Card>

                        <Card title="Áreas, equipo y fechas">
                            <dl className="flex flex-col gap-3 text-sm">
                                <div className="flex flex-col gap-1">
                                    <dt className="text-xs font-semibold text-fg-muted">Área principal</dt>
                                    <dd className="flex items-center gap-2 font-semibold">
                                        {company ? <CompanyMark company={company} size={20} /> : null}
                                        {project.area.name}
                                    </dd>
                                </div>
                                {project.benefited_areas.length > 0 ? (
                                    <div className="flex flex-col gap-1">
                                        <dt className="text-xs font-semibold text-fg-muted">También se benefician</dt>
                                        {project.benefited_areas.map((item) => (
                                            <dd key={item.area.id} className="flex flex-wrap items-center gap-2">
                                                {company ? <CompanyMark company={company} size={20} /> : null}
                                                <span className="font-semibold">{item.area.name}</span>
                                                {item.note ? <span className="text-[13px] text-fg-muted">{item.note}</span> : null}
                                            </dd>
                                        ))}
                                    </div>
                                ) : null}
                                <div className="flex flex-col gap-0.5">
                                    <dt className="text-xs font-semibold text-fg-muted">Equipo</dt>
                                    {project.team.map((member) => (
                                        <dd key={member.person.id}>
                                            <PersonLine person={member.person} detail={member.role} />
                                        </dd>
                                    ))}
                                </div>
                            </dl>
                            <dl className="flex flex-col gap-1.5 border-t border-line pt-3">
                                <DateRow label="Aprobado" value={formatShortDate(budget.approved_at)} />
                                <DateRow label="Entrega aprobada" value={formatShortDate(budget.due_date)} />
                                <DateRow
                                    label="Entrega proyectada"
                                    value={formatShortDate(budget.projected_due_date)}
                                    tone={budget.projected_due_date > budget.due_date ? 'late' : undefined}
                                />
                            </dl>
                        </Card>
                    </div>

                    {/* Column 2: progress and money */}
                    <div className="flex min-w-0 flex-col gap-4">
                        <Card
                            title="Avance en el tablero"
                            aside={
                                <Button variant="quiet" href={board} className="h-11 px-0">
                                    Abrir tablero
                                </Button>
                            }
                        >
                            <div className="flex h-3 overflow-hidden rounded-full bg-subtle" aria-hidden="true">
                                {PROGRESS_ORDER.map((column) =>
                                    project.tasks_by_column[column] > 0 ? (
                                        <span key={column} className={PROGRESS_TONE[column]} style={{ width: `${(project.tasks_by_column[column] / Math.max(1, tasksTotal)) * 100}%` }} />
                                    ) : null,
                                )}
                            </div>
                            <ul className="flex flex-wrap gap-x-5 gap-y-2" aria-label={`${tasksTotal} tareas por columna`}>
                                {PROGRESS_ORDER.map((column) => (
                                    <li key={column} className="flex flex-col gap-0.5">
                                        <span className="flex items-center gap-1.5 text-xs whitespace-nowrap text-fg-muted">
                                            <span aria-hidden="true" className={cx('size-2 rounded-[2px]', PROGRESS_TONE[column], column === 'backlog' && 'border border-line-strong')} />
                                            {columnLabel(column)}
                                        </span>
                                        <b className="tabular text-lg">{project.tasks_by_column[column]}</b>
                                    </li>
                                ))}
                            </ul>
                            {project.blocked_tasks > 0 ? (
                                <p className="flex items-center gap-1.5 text-[13px] font-semibold text-status-failure">
                                    <Icon name="blocked" size={14} />
                                    {project.blocked_tasks} {project.blocked_tasks === 1 ? 'tarea bloqueada' : 'tareas bloqueadas'} por un requerimiento
                                    {open_requirements[0] ? ` a ${open_requirements[0].area_name}` : ''}
                                </p>
                            ) : null}
                        </Card>

                        <Card title="Estimado contra real" aside={canViewCosts ? <CostsLock /> : null}>
                            <BudgetBars budget={budget} showCost={canViewCosts} />
                            {canViewCosts ? (
                                <Button variant="secondary" href={route('ai.projects.costs', { project: project.key })} className="self-start">
                                    Ver tiempo y costos
                                </Button>
                            ) : (
                                <p className="flex items-center gap-1.5 text-[13px] text-fg-muted">
                                    <Icon name="lock" size={13} />
                                    El costo solo lo ve quien tiene permiso de costos.
                                </p>
                            )}
                        </Card>

                        <Card
                            title="Criterios de aceptación"
                            aside={
                                <span className="tabular text-[13px] text-fg-muted">
                                    {criteriaDone} de {project.criteria.length} cumplidos
                                </span>
                            }
                        >
                            {project.criteria.length === 0 ? (
                                <p className="text-sm text-fg-muted">Este proyecto aún no tiene criterios escritos.</p>
                            ) : (
                                <ul className="flex flex-col gap-2.5">
                                    {project.criteria.map((criterion) => (
                                        <li key={criterion.id} className="flex items-start gap-2.5 text-sm">
                                            <span
                                                className={cx(
                                                    'mt-0.5 flex size-5 shrink-0 items-center justify-center rounded-full',
                                                    criterion.validated ? 'bg-status-active text-white dark:text-on-fg' : 'border-2 border-line-strong',
                                                )}
                                            >
                                                {criterion.validated ? <Icon name="check" size={12} strokeWidth={3} label="Validado" /> : <span className="sr-only">Pendiente</span>}
                                            </span>
                                            <span className="flex min-w-0 flex-col gap-0.5">
                                                <span className={criterion.validated ? 'font-semibold' : undefined}>{criterion.text}</span>
                                                {criterion.validated ? (
                                                    <span className="text-xs text-fg-muted">
                                                        Validado por {criterion.validated.by} el {formatShortDate(criterion.validated.at)}
                                                    </span>
                                                ) : criterion.note ? (
                                                    <span className="text-xs text-fg-muted">{criterion.note}</span>
                                                ) : null}
                                            </span>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </Card>
                    </div>

                    {/* Column 3: repository, phase, requirements, activity */}
                    <div className="flex min-w-0 flex-col gap-4 md:grid md:grid-cols-2 xl:flex">
                        <Card title="Repositorio">
                            {project.repository ? (
                                <>
                                    <a
                                        href={project.repository.url}
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        className="inline-flex min-h-11 items-center gap-1.5 text-sm font-bold break-all text-fg underline-offset-2 hover:underline"
                                    >
                                        <Icon name="code" size={16} />
                                        {project.repository.url.replace(/^https?:\/\//, '')}
                                        <Icon name="external" size={13} />
                                        <span className="sr-only"> (abre en GitHub)</span>
                                    </a>
                                    <p className="text-[13px] text-fg-muted">
                                        Rama {project.repository.branch} · {project.repository.open_prs}{' '}
                                        {project.repository.open_prs === 1 ? 'PR abierto' : 'PR abiertos'} · último cambio {formatRelative(project.repository.last_change_at, now)}
                                    </p>
                                    {project.product_slug ? (
                                        <Link
                                            href={`${route('ai.products.show', { product: project.product_slug })}#github`}
                                            className="inline-flex min-h-11 items-center gap-1.5 self-start text-[13px] font-bold text-fg underline-offset-[3px] hover:underline"
                                        >
                                            Ver actividad en GitHub
                                        </Link>
                                    ) : null}
                                </>
                            ) : (
                                <div className="flex flex-col gap-2">
                                    <p className="text-sm text-fg-muted">
                                        {repoLink
                                            ? 'Sin repositorio vinculado. Vincúlalo para crear issues desde las tareas y recibir PR, CI y releases.'
                                            : 'Sin repositorio vinculado. Pídele a quien administra GitHub que lo vincule.'}
                                    </p>
                                    {repoLink ? (
                                        <Button variant="dark" icon="link" aria-haspopup="dialog" onClick={() => setLinkOpen(true)} className="self-start">
                                            Vincular repositorio
                                        </Button>
                                    ) : null}
                                </div>
                            )}
                        </Card>

                        <Card title={`Fase ${PHASE_LABELS[phase]} · ${phase} de 5`}>
                            <p className="text-[13px] leading-snug text-fg-soft">
                                {project.started_at ? `Desde el ${formatShortDate(project.started_at)}.` : 'Aún no empieza.'} {next_step ?? ''}
                            </p>
                        </Card>

                        <Card
                            title="Requerimientos abiertos"
                            aside={
                                <Button variant="quiet" href={route('ai.projects.requirements', { project: project.key })} className="h-11 px-0">
                                    Ver todos
                                </Button>
                            }
                        >
                            {open_requirements.length === 0 ? (
                                <p className="text-sm text-fg-muted">No esperamos nada de nadie.</p>
                            ) : (
                                <ul className="flex flex-col gap-3">
                                    {open_requirements.map((requirement) => {
                                        const state = requirementState(requirement, today);
                                        return (
                                            <li key={requirement.id} className="flex flex-col gap-1 text-sm">
                                                <b>{requirement.title}</b>
                                                <span className="text-[13px] text-fg-muted">
                                                    Para {requirement.recipient?.name ?? requirement.recipient_label} · {requirement.area_name}
                                                </span>
                                                <span
                                                    className={cx(
                                                        'flex flex-wrap items-center gap-1.5 text-[13px] font-semibold',
                                                        state.level === 'overdue' ? 'text-status-failure' : 'text-status-maintenance',
                                                    )}
                                                >
                                                    <Icon name={state.level === 'overdue' ? 'alert-triangle' : 'hourglass'} size={13} />
                                                    {state.level === 'overdue' ? 'Vencido' : 'Pendiente'} · vence {formatLongDate(requirement.due_date).slice(0, 3)}{' '}
                                                    {formatShortDate(requirement.due_date)}
                                                    {requirement.blocks_task ? ` · bloquea ${requirement.blocks_task.key}` : ''}
                                                </span>
                                            </li>
                                        );
                                    })}
                                </ul>
                            )}
                        </Card>

                        <Card title="Actividad reciente">
                            <ol className="flex flex-col gap-3">
                                {activity.map((item) => (
                                    <li key={item.id} className="flex items-start gap-2.5 text-[13px]">
                                        {item.actor ? (
                                            <Avatar name={item.actor.name} size="xs" />
                                        ) : (
                                            <span aria-hidden="true" className="flex size-6 shrink-0 items-center justify-center rounded-full bg-status-maintenance-bg text-status-maintenance">
                                                <Icon name={ACTIVITY_ICON[item.kind]} size={13} />
                                            </span>
                                        )}
                                        <span className="flex min-w-0 flex-col gap-0.5">
                                            <span className="leading-snug">
                                                {item.actor ? <b>{item.actor.name.split(' ')[0]} </b> : null}
                                                {item.text}
                                            </span>
                                            <span className="text-xs text-fg-muted">{formatRelative(item.at, now)}</span>
                                        </span>
                                    </li>
                                ))}
                            </ol>
                        </Card>
                    </div>
                </div>

            </div>
            {repoLink && !project.repository ? (
                <RepoLinkDialog
                    setup={repoLink}
                    open={linkOpen}
                    onClose={() => setLinkOpen(false)}
                    action={route('ai.projects.repository.store', { project: project.key })}
                    now={props.now}
                />
            ) : null}
            {brief ? (
                <BriefPanel
                    open={briefOpen}
                    onClose={() => setBriefOpen(false)}
                    subject={`${brief.key} · ${brief.name}${project.repository ? '' : ' · sin repo'}`}
                    build={buildBrief}
                    defaultSections={ALL_BRIEF_SECTIONS}
                    fileName={briefFileName(brief.key)}
                    showWorkflow
                    omittedNote={omittedNote(props.brief_omitted)}
                    initialView={briefState?.view}
                    initialTab={briefState?.tab}
                    initiallyCopied={briefState?.copied}
                    claudeMd={{
                        markdown: claudeMarkdown,
                        fileName: 'CLAUDE.md',
                        renderFooter: repoCreate
                            ? (copy) => (
                                  <RepoCreateFooter
                                      organization={repoCreate.organization}
                                      defaultName={repoCreate.default_name}
                                      claudeMd={claudeMarkdown}
                                      action={route('ai.projects.repository.create', { project: project.key })}
                                      copy={copy}
                                  />
                              )
                            : undefined,
                    }}
                />
            ) : null}
        </ProjectLayout>
    );
}
