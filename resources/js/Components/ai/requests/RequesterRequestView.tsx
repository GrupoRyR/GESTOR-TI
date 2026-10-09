import { Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { RequestConversation } from '@/Components/ai/requests/RequestConversation';
import { teamHoursLine, toTimelineItem } from '@/Components/ai/requests/requestView';
import { CompanyMark } from '@/Components/ui/CompanyMark';
import { Icon } from '@/Components/ui/Icon';
import { PhaseRail } from '@/Components/ui/PhaseRail';
import { STATUS_ICONS, StatusChip } from '@/Components/ui/StatusChip';
import { Timeline } from '@/Components/ui/Timeline';
import { cx } from '@/Components/ui/utils';
import { GestorLayout } from '@/Layouts/GestorLayout';
import { formatShortDate } from '@/lib/format';
import { route } from '@/lib/route';
import { requestStatus } from '@/lib/status';
import type { RequesterShowProps } from '@/types/pages/ai-requester';
import type { PageProps } from '@/types/shared';

type Props = PageProps<RequesterShowProps>;

function FollowButton({ requestKey, following }: { requestKey: string; following: boolean }) {
    const [on, setOn] = useState(following);
    const toggle = () => {
        const url = route('ai.requests.follow', { request: requestKey });
        const options = { preserveScroll: true, preserveState: true, onError: () => setOn(!on) };
        setOn(!on);
        if (on) router.delete(url, options);
        else router.post(url, {}, options);
    };
    return (
        <button
            type="button"
            aria-pressed={on}
            onClick={toggle}
            title={on ? 'Dejar de seguir: ya no te llegan avisos' : 'Seguir: te avisamos de cada avance'}
            className={cx(
                'inline-flex h-11 shrink-0 items-center gap-1.5 self-start rounded-control border px-3.5 text-sm font-bold transition-colors duration-150',
                on ? 'border-fg bg-fg text-on-fg' : 'border-line-strong bg-surface text-fg hover:bg-subtle',
            )}
        >
            <Icon name={on ? 'check' : 'bell'} size={16} strokeWidth={2.4} />
            {on ? 'Siguiendo' : 'Seguir'}
        </button>
    );
}

function StatusPanel({ props }: { props: Props }) {
    const { request, where, roadmap_position, companies } = props;
    const company = companies.find((item) => item.id === request.company_id);
    const awaitingDecision = requestStatus(request.status).phase <= 2 && request.status !== 'approved';
    const hoursLine = teamHoursLine(request.hours_per_week, request.people);

    return (
        <div className="flex flex-col gap-3">
            <section aria-label="Dónde está" className="flex flex-col gap-3 rounded-card border border-line bg-surface p-4">
                <PhaseRail status={request.status} variant="full" />
                <div className="flex flex-col gap-1 rounded-control bg-ground p-3">
                    <span className="text-xs font-semibold text-fg-muted">Dónde está</span>
                    <b className="text-[15px] leading-snug">{where.label}</b>
                    <p className="text-[13px] leading-snug text-fg-soft">{where.explanation}</p>
                </div>
                {roadmap_position ? (
                    <Link
                        href={route('ai.roadmap.index', { area_id: request.area.id })}
                        className="flex items-center gap-3 rounded-control border border-line p-3 no-underline hover:bg-subtle"
                    >
                        <span className="tabular flex size-11 shrink-0 items-center justify-center rounded-icon bg-fg font-display text-xl font-bold text-on-fg">
                            {roadmap_position.rank}
                        </span>
                        <span className="flex min-w-0 flex-1 flex-col text-sm">
                            <b className="leading-snug">{roadmap_position.label}</b>
                            {roadmap_position.detail ? <span className="text-[13px] text-fg-muted">{roadmap_position.detail}</span> : null}
                        </span>
                        <Icon name="chevron-right" size={18} />
                    </Link>
                ) : null}
            </section>
            <section aria-label="Datos" className="rounded-card border border-line bg-surface p-4">
                <h2 className="mb-2 text-base font-bold">Datos</h2>
                <dl className="grid grid-cols-[auto_minmax(0,1fr)] gap-x-4 gap-y-2 text-sm">
                    {request.product ? (
                        <>
                            <dt className="text-fg-muted">App</dt>
                            <dd>
                                {request.product.name} · {request.product.version}
                            </dd>
                        </>
                    ) : null}
                    <dt className="text-fg-muted">Área</dt>
                    <dd>
                        {request.area.name}
                        {company ? ` · ${company.short_name}` : ''}
                    </dd>
                    <dt className="text-fg-muted">La pediste</dt>
                    <dd>{formatShortDate(request.created_at)}</dd>
                    {hoursLine ? (
                        <>
                            <dt className="text-fg-muted">Tiempo hoy</dt>
                            <dd className="tabular">{hoursLine}</dd>
                        </>
                    ) : null}
                    {request.estimate ? (
                        <>
                            <dt className="text-fg-muted">Entrega propuesta</dt>
                            <dd>
                                {formatShortDate(request.estimate.due_date)}
                                {awaitingDecision ? ', si se aprueba' : ''}
                            </dd>
                        </>
                    ) : null}
                </dl>
            </section>
        </div>
    );
}

function Criteria({ props, id }: { props: Props; id?: string }) {
    const { criteria } = props.request;
    const titleId = `${id ?? 'criterios-d'}-t`;
    return (
        <section id={id} aria-labelledby={titleId} className="flex scroll-mt-24 flex-col gap-2.5 rounded-card border border-line bg-surface p-4">
            <h2 id={titleId} className="text-base font-bold">
                Criterios de aceptación
            </h2>
            {criteria.length === 0 ? (
                <p className="text-sm text-fg-muted">TI los escribe al evaluarla. Son las condiciones que vas a validar cuando esté en pruebas.</p>
            ) : (
                <>
                    <p className="text-[13px] text-fg-muted">Son los que vas a validar cuando esté en pruebas. Si falta alguno, escríbelo en la conversación.</p>
                    <ol className="flex flex-col gap-2">
                        {criteria.map((criterion, index) => (
                            <li key={criterion.id} className="grid grid-cols-[24px_minmax(0,1fr)] gap-2.5 text-sm leading-snug">
                                <span
                                    aria-hidden="true"
                                    className={cx(
                                        'tabular flex size-6 items-center justify-center rounded-full text-xs font-bold',
                                        criterion.validated ? 'bg-status-active-bg text-status-active' : 'bg-subtle',
                                    )}
                                >
                                    {criterion.validated ? <Icon name="check" size={12} strokeWidth={3} /> : index + 1}
                                </span>
                                <span>
                                    {criterion.text}
                                    {criterion.validated ? <span className="block text-xs text-status-active">Validado por {criterion.validated.by}</span> : null}
                                </span>
                            </li>
                        ))}
                    </ol>
                </>
            )}
        </section>
    );
}

/**
 * Request detail as the requester sees it: where it is, what happens next,
 * the history, the public conversation and the acceptance criteria. No
 * costs, no internal notes (the server never sends them).
 */
export function RequesterRequestView(props: Props) {
    const { request, following, can_comment, companies, auth } = props;
    const company = companies.find((item) => item.id === request.company_id);
    const timeline = request.timeline.map((event) => toTimelineItem(event, STATUS_ICONS));
    const sections = [
        { href: '#linea', label: 'Línea de tiempo' },
        { href: '#conversacion', label: 'Conversación', count: request.comments.length },
        { href: '#criterios', label: 'Criterios' },
    ];

    return (
        <GestorLayout
            shared={props}
            title={`${request.key} · Mis solicitudes`}
            mode="requester"
            breadcrumb={[{ label: 'Mis solicitudes', href: route('ai.requests.mine') }, { label: request.key }]}
        >
            <div className="mx-auto flex w-full max-w-[1180px] flex-col gap-4">
                <header className="flex flex-col gap-2.5 md:flex-row md:items-start md:gap-6">
                    <div className="flex min-w-0 flex-1 flex-col gap-2">
                        <div className="flex flex-wrap items-center gap-2">
                            <b className="tabular text-sm text-fg-soft">{request.key}</b>
                            {company ? <CompanyMark company={company} size={22} /> : null}
                            <StatusChip status={request.status} size="sm" />
                        </div>
                        <h1 className="font-display text-[24px] leading-[1.15] font-bold tracking-[-0.02em] md:text-[30px]">{request.title}</h1>
                    </div>
                    <FollowButton requestKey={request.key} following={following} />
                </header>

                <div className="flex flex-col gap-4 lg:grid lg:grid-cols-[minmax(0,1fr)_380px] lg:items-start lg:gap-6">
                    <div className="flex min-w-0 flex-col gap-4">
                        <div className="lg:hidden">
                            <StatusPanel props={props} />
                        </div>

                        <nav
                            aria-label="Secciones"
                            className="sticky top-[60px] z-10 -mx-4 flex gap-1 overflow-x-auto border-b border-line bg-ground/95 px-4 py-1.5 backdrop-blur md:top-[68px] lg:hidden"
                        >
                            {sections.map((section) => (
                                <a
                                    key={section.href}
                                    href={section.href}
                                    className="flex h-11 shrink-0 items-center gap-1.5 rounded-chip px-3 text-sm font-semibold whitespace-nowrap hover:bg-subtle"
                                >
                                    {section.label}
                                    {section.count ? (
                                        <span className="tabular rounded-full bg-subtle px-1.5 text-xs font-bold">{section.count}</span>
                                    ) : null}
                                </a>
                            ))}
                        </nav>

                        <section id="linea" aria-labelledby="linea-t" className="flex scroll-mt-24 flex-col gap-3 rounded-card border border-line bg-surface p-4 md:p-5">
                            <h2 id="linea-t" className="text-base font-bold">
                                Línea de tiempo
                            </h2>
                            <Timeline items={timeline} label={`Línea de tiempo de ${request.key}`} />
                        </section>

                        <RequestConversation id="conversacion" requestKey={request.key} comments={request.comments} viewerId={auth.user.id} canComment={can_comment} />

                        <div className="lg:hidden">
                            <Criteria props={props} id="criterios" />
                        </div>
                    </div>

                    <aside className="hidden flex-col gap-4 lg:sticky lg:top-[92px] lg:flex">
                        <StatusPanel props={props} />
                        <Criteria props={props} />
                    </aside>
                </div>
            </div>
        </GestorLayout>
    );
}
