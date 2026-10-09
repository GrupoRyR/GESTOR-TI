import { Link } from '@inertiajs/react';
import { ApprovalHistory, ApprovalSummaryCard, DecisionForm, estimateLine, otherApprovers } from '@/Components/ai/triage/ApprovalParts';
import { sinceLabel } from '@/Components/ai/triage/triage';
import { CompanyMark } from '@/Components/ui/CompanyMark';
import { EmptyState } from '@/Components/ui/EmptyState';
import { NoPermission } from '@/Components/ui/NoPermission';
import { cx } from '@/Components/ui/utils';
import { GestorLayout } from '@/Layouts/GestorLayout';
import { can } from '@/lib/can';
import { route } from '@/lib/route';
import type { ApprovalsIndexProps } from '@/types/pages/ai-triage';
import type { PageProps } from '@/types/shared';

/** "Esperan tu aprobación" (B-ApprovalDesktop): pending list, the selected one in detail, its history and the decision. */
export default function ApprovalsIndex(props: PageProps<ApprovalsIndexProps>) {
    const { pending, selected, wip, auth, companies } = props;
    const now = new Date(props.now);
    const isIt = can(auth.user, 'ai.manage');
    const canApprove = can(auth.user, 'ai.projects.approve');
    const canViewCosts = can(auth.user, 'ai.costs.view');
    const companyOf = (id: number) => companies.find((company) => company.id === id);
    const breadcrumb = isIt ? [{ label: 'Bandeja', href: route('ai.inbox.index') }, { label: 'Aprobaciones' }] : undefined;

    if (!canApprove) {
        return (
            <GestorLayout shared={props} title="Aprobaciones · Gestor IA" tab={isIt ? 'inbox' : undefined} breadcrumb={breadcrumb}>
                <NoPermission
                    title="Solo los aprobadores de proyectos deciden aquí"
                    description="Las aprobaciones las dan Jhon Castro o Edward Ramanan. Si debes aprobar proyectos, pídele el rol a un administrador del Gestor TI."
                />
            </GestorLayout>
        );
    }

    const others = selected ? otherApprovers(selected.approval.approvers, auth.user.id) : '';

    return (
        <GestorLayout shared={props} title="Aprobaciones · Gestor IA" tab={isIt ? 'inbox' : undefined} breadcrumb={breadcrumb}>
            <div className="flex flex-col gap-4">
                <div className="flex flex-col gap-1">
                    <h1 className="font-display text-[26px] leading-tight font-bold tracking-[-0.02em] md:text-[30px]">Esperan tu aprobación</h1>
                    <p className="text-sm text-fg-soft">
                        {pending.length === 0
                            ? 'Nada pendiente por ahora.'
                            : `${pending.length} ${pending.length === 1 ? 'proyecto' : 'proyectos'} · basta una aprobación${others ? `: la tuya o la de ${others}` : ''}`}
                    </p>
                </div>

                {pending.length === 0 || !selected ? (
                    <EmptyState
                        icon="check-circle"
                        title="No hay aprobaciones pendientes"
                        description="Cuando TI mande un proyecto a aprobación te llega por Telegram y aparece aquí."
                    />
                ) : (
                    <div className="flex flex-col gap-4 lg:grid lg:grid-cols-[320px_minmax(0,1fr)] lg:items-start lg:gap-6">
                        <div className="flex flex-col gap-3">
                            <ul aria-label="Pendientes" className="flex flex-col gap-2">
                                {pending.map((item) => {
                                    const current = item.key === selected.request.key;
                                    const company = companyOf(item.company_id);
                                    return (
                                        <li key={item.key}>
                                            <Link
                                                href={route('ai.approvals.index', { request: item.key })}
                                                only={['selected']}
                                                preserveScroll
                                                preserveState
                                                aria-current={current ? 'true' : undefined}
                                                className={cx(
                                                    'flex flex-col gap-1 rounded-card border bg-surface p-3.5 no-underline transition-colors duration-150',
                                                    current ? 'border-fg shadow-[inset_3px_0_0_var(--color-fg)]' : 'border-line hover:bg-subtle',
                                                )}
                                            >
                                                <span className="flex items-center gap-2 text-sm">
                                                    {company ? <CompanyMark company={company} size={20} /> : null}
                                                    <b className="tabular">{item.key}</b>
                                                    <span className="ml-auto text-xs text-fg-muted">{sinceLabel(item.requested_at, now)}</span>
                                                </span>
                                                <span className="text-[15px] leading-snug font-bold text-fg">{item.title}</span>
                                                <span className="tabular text-[13px] text-fg-soft">
                                                    {estimateLine(item.hours_total, canViewCosts ? item.total_cost : null, item.due_date)}
                                                </span>
                                            </Link>
                                        </li>
                                    );
                                })}
                            </ul>
                            <p className="text-[13px] leading-snug text-fg-muted">
                                Aprobar no la pone en desarrollo de inmediato
                                {wip.in_development >= wip.limit ? `: hoy hay ${wip.in_development} de ${wip.limit} proyectos en desarrollo.` : '.'}
                            </p>
                        </div>

                        <div className="flex min-w-0 flex-col gap-4 xl:grid xl:grid-cols-[minmax(0,1fr)_340px] xl:items-start">
                            <ApprovalSummaryCard detail={selected} company={companyOf(selected.request.company_id)} canViewCosts={canViewCosts} headingLevel={2} />
                            <div className="flex flex-col gap-4">
                                <ApprovalHistory detail={selected} />
                                <DecisionForm key={selected.request.key} requestKey={selected.request.key} approvers={selected.approval.approvers} viewerId={auth.user.id} />
                            </div>
                        </div>
                    </div>
                )}
            </div>
        </GestorLayout>
    );
}
