import { Link } from '@inertiajs/react';
import { useCallback, useId, useRef, useState, type KeyboardEvent, type ReactNode } from 'react';
import { RequestActionDialogs, useRequestActions, type RequestAction } from '@/Components/ai/requests/actions/RequestActionDialogs';
import { EvaluationPanel, URGENCY_LABELS } from '@/Components/ai/requests/EvaluationPanel';
import { InternalNotes } from '@/Components/ai/requests/InternalNotes';
import { RequestConversation } from '@/Components/ai/requests/RequestConversation';
import { dueState, firstName, teamHours } from '@/Components/ai/triage/triage';
import { DUE_TO_SEMAPHORE } from '@/Components/ai/triage/FirstResponse';
import { BriefPanel } from '@/Components/brief/BriefPanel';
import { BriefTrigger } from '@/Components/brief/BriefTrigger';
import { Button } from '@/Components/ui/Button';
import { CompanyMark } from '@/Components/ui/CompanyMark';
import { Icon } from '@/Components/ui/Icon';
import { PhaseRail } from '@/Components/ui/PhaseRail';
import { Semaphore } from '@/Components/ui/Semaphore';
import { StatusChip } from '@/Components/ui/StatusChip';
import { cx } from '@/Components/ui/utils';
import { GestorLayout } from '@/Layouts/GestorLayout';
import { briefFileName, buildRequestBrief, type BriefSectionKey } from '@/lib/brief';
import { can } from '@/lib/can';
import { formatFileSize, formatLongDate, formatShortDate, formatTime } from '@/lib/format';
import { route } from '@/lib/route';
import { requestStatus } from '@/lib/status';
import type { ItShowProps } from '@/types/pages/ai-triage';
import type { PageProps } from '@/types/shared';

type Props = PageProps<ItShowProps>;

const number = (value: number) => value.toLocaleString('es-CO', { maximumFractionDigits: 1 });

function Answer({ term, children }: { term: string; children: ReactNode }) {
    return (
        <div className="flex flex-col gap-1 md:grid md:grid-cols-[170px_minmax(0,1fr)] md:gap-4">
            <dt className="text-[13px] font-semibold text-fg-muted">{term}</dt>
            <dd className="text-[15px] leading-normal">{children}</dd>
        </div>
    );
}

type ConversationTab = 'public' | 'notes';

function ConversationTabs({ props }: { props: Props }) {
    const { request, auth, now } = props;
    const [tab, setTab] = useState<ConversationTab>('public');
    const base = useId();
    const refs = useRef<Record<ConversationTab, HTMLButtonElement | null>>({ public: null, notes: null });
    const notes = request.internal_notes ?? [];
    const name = firstName(request.requester.name);
    const closed = request.status === 'closed' || request.status === 'rejected';

    const tabs: { key: ConversationTab; label: string; count: number }[] = [
        { key: 'public', label: 'Conversación pública', count: request.comments.length },
        { key: 'notes', label: 'Notas internas', count: notes.length },
    ];

    const onKeyDown = (event: KeyboardEvent<HTMLDivElement>) => {
        if (event.key !== 'ArrowRight' && event.key !== 'ArrowLeft') return;
        event.preventDefault();
        const next: ConversationTab = tab === 'public' ? 'notes' : 'public';
        setTab(next);
        refs.current[next]?.focus();
    };

    return (
        <section aria-label="Conversación" className="flex flex-col gap-3 rounded-card border border-line bg-surface p-4 md:p-5">
            <div role="tablist" aria-label="Conversación" onKeyDown={onKeyDown} className="flex gap-1 border-b border-line">
                {tabs.map((item) => {
                    const selected = item.key === tab;
                    return (
                        <button
                            key={item.key}
                            ref={(element) => {
                                refs.current[item.key] = element;
                            }}
                            type="button"
                            role="tab"
                            id={`${base}-${item.key}-tab`}
                            aria-selected={selected}
                            aria-controls={`${base}-${item.key}`}
                            tabIndex={selected ? 0 : -1}
                            onClick={() => setTab(item.key)}
                            className={cx(
                                '-mb-px flex h-11 items-center gap-1.5 border-b-2 px-2.5 text-sm whitespace-nowrap transition-colors duration-150',
                                selected ? 'border-fg font-bold text-fg' : 'border-transparent font-semibold text-fg-soft hover:text-fg',
                            )}
                        >
                            {item.key === 'notes' ? <Icon name="lock" size={14} /> : null}
                            {item.label}
                            <span
                                className={cx(
                                    'tabular rounded-full px-1.5 text-xs font-bold',
                                    item.key === 'notes' ? 'bg-status-local-bg text-status-local' : 'bg-subtle',
                                )}
                            >
                                {item.count}
                            </span>
                        </button>
                    );
                })}
            </div>
            <div role="tabpanel" id={`${base}-public`} aria-labelledby={`${base}-public-tab`} hidden={tab !== 'public'}>
                <RequestConversation
                    requestKey={request.key}
                    comments={request.comments}
                    viewerId={auth.user.id}
                    canComment={!closed}
                    composerLabel={`Responder a ${name}`}
                    composerPlaceholder="Escribe tu respuesta"
                    embedded
                />
            </div>
            <div role="tabpanel" id={`${base}-notes`} aria-labelledby={`${base}-notes-tab`} hidden={tab !== 'notes'}>
                <InternalNotes requestKey={request.key} notes={notes} requesterName={name} now={new Date(now)} />
            </div>
        </section>
    );
}

/**
 * Request detail for IT (holders of `ai.manage`): what the requester asked,
 * a duplicate warning, public conversation and internal notes in tabs, and
 * the evaluation panel with every action (dialogs shared with the inbox).
 * Stage D: "Brief para Claude" in the header when the server sends `brief`.
 */
export function ItRequestView(props: Props) {
    const { request, evaluation, duplicates, area_hour_value, first_response, companies, auth, now } = props;
    const actions = useRequestActions();
    const company = companies.find((item) => item.id === request.company_id);
    const canViewCosts = can(auth.user, 'ai.costs.view');
    const name = firstName(request.requester.name);
    const pending = first_response.answered_at === null ? dueState(first_response.due_at, new Date(now)) : null;
    const duplicate = duplicates[0];
    const weekly = teamHours(request.hours_per_week, request.people);
    const { answers } = request;
    const open = (action: RequestAction, mergeInto: string | null = null) => actions.open(action, [request], mergeInto);
    const createdWeekday = formatLongDate(request.created_at).split(' ')[0];
    const brief = props.brief ?? null;
    const briefState = props.brief_state ?? null;
    const [briefOpen, setBriefOpen] = useState(brief !== null && briefState?.open === true);
    const buildBrief = useCallback((sections: BriefSectionKey[]) => (brief ? buildRequestBrief(brief, sections) : ''), [brief]);

    return (
        <GestorLayout
            shared={props}
            title={`${request.key} · Bandeja`}
            tab="inbox"
            mode="it"
            breadcrumb={[{ label: 'Bandeja', href: route('ai.inbox.index') }, { label: request.key }]}
        >
            <div className="mx-auto flex w-full max-w-[1280px] flex-col gap-4">
                <header className="flex flex-col gap-2.5">
                    <div className="flex flex-wrap items-center gap-2">
                        {company ? <CompanyMark company={company} size={22} /> : null}
                        <b className="tabular text-sm text-fg-soft">{request.key}</b>
                        <StatusChip status={request.status} size="sm" />
                        {pending ? (
                            <span
                                className={cx(
                                    'inline-flex h-[26px] items-center rounded-chip px-2.5',
                                    pending.level === 'late' ? 'bg-status-failure-bg' : pending.level === 'today' ? 'bg-status-maintenance-bg' : 'bg-status-active-bg',
                                )}
                            >
                                <Semaphore level={DUE_TO_SEMAPHORE[pending.level]}>
                                    Primera respuesta {pending.label.charAt(0).toLowerCase() + pending.label.slice(1)}
                                </Semaphore>
                            </span>
                        ) : null}
                    </div>
                    <h1 className="max-w-[60ch] font-display text-[24px] leading-[1.15] font-bold tracking-[-0.02em] md:text-[30px]">{request.title}</h1>
                    <p className="text-sm text-fg-soft">
                        {request.requester.name}
                        {request.requester.job_title ? `, ${request.requester.job_title}` : ''} · {request.area.name}
                        {company ? `, ${company.short_name}` : ''} · entró el {createdWeekday} {formatShortDate(request.created_at)}, {formatTime(request.created_at)}
                        {request.votes > 0 ? (
                            <>
                                {' · '}
                                <b className="text-fg">
                                    +{request.votes} {request.votes === 1 ? 'voto' : 'votos'}
                                </b>
                            </>
                        ) : null}
                    </p>
                    {brief ? (
                        <div className="flex flex-wrap gap-2 pt-1">
                            <BriefTrigger expanded={briefOpen} onOpen={() => setBriefOpen(true)} />
                        </div>
                    ) : null}
                    <PhaseRail status={request.status} variant="full" className="max-w-[560px]" />
                </header>

                <div className="flex flex-col gap-4 lg:grid lg:grid-cols-[minmax(0,1fr)_400px] lg:grid-rows-[auto_auto_1fr] lg:items-start lg:gap-x-6">
                    <div className="flex min-w-0 flex-col gap-4 lg:col-start-1 lg:row-start-1">
                        {duplicate ? (
                            <section
                                aria-label="Posible duplicado"
                                className="flex flex-col gap-3 rounded-card border border-status-maintenance/25 bg-status-maintenance-bg p-3.5 sm:flex-row sm:items-center"
                            >
                                <span aria-hidden="true" className="flex size-10 shrink-0 items-center justify-center rounded-control bg-surface text-status-maintenance">
                                    <Icon name="pull-request" size={18} />
                                </span>
                                <span className="flex min-w-0 flex-1 flex-col text-sm">
                                    <b>
                                        Se parece a {duplicate.key} · {duplicate.votes} {duplicate.votes === 1 ? 'persona' : 'personas'}
                                    </b>
                                    <span className="text-[13px] text-fg-soft">
                                        {duplicate.title} · {requestStatus(duplicate.status).label}
                                        {duplicate.rank !== null ? `, puesto ${duplicate.rank}` : ''}
                                    </span>
                                </span>
                                <span className="flex shrink-0 items-center gap-1">
                                    <Link
                                        href={route('ai.requests.show', { request: duplicate.key })}
                                        className="flex h-11 items-center px-2.5 text-sm font-semibold text-fg-soft underline underline-offset-2 hover:text-fg"
                                    >
                                        Ver {duplicate.key}
                                    </Link>
                                    <Button variant="secondary" icon="pull-request" onClick={() => open('merge', duplicate.key)}>
                                        Unir
                                    </Button>
                                </span>
                            </section>
                        ) : null}

                        <section aria-labelledby="pidio-t" className="flex flex-col gap-4 rounded-card border border-line bg-surface p-4 md:p-5">
                            <h2 id="pidio-t" className="text-base font-bold">
                                Lo que pidió {name}
                            </h2>
                            <dl className="flex flex-col gap-3.5">
                                <Answer term="El problema">{answers.problem}</Answer>
                                {answers.current_process ? <Answer term="Cómo se hace hoy">{answers.current_process}</Answer> : null}
                                <Answer term="Tiempo hoy">
                                    {request.hours_per_week !== null && request.people !== null && weekly !== null ? (
                                        <>
                                            <b>
                                                {number(request.hours_per_week)} h a la semana × {request.people} {request.people === 1 ? 'persona' : 'personas'}
                                            </b>{' '}
                                            <span className="text-fg-muted">· {number(weekly)} horas del equipo cada semana</span>
                                        </>
                                    ) : (
                                        <span className="text-fg-muted">No lo sabe todavía</span>
                                    )}
                                </Answer>
                                <Answer term="Resultado esperado">{answers.expected_result}</Answer>
                                {answers.success_criteria ? <Answer term="Cómo sabrá que funcionó">{answers.success_criteria}</Answer> : null}
                                {answers.systems.length > 0 ? (
                                    <Answer term="Sistemas">
                                        <span className="flex flex-wrap gap-1.5">
                                            {answers.systems.map((system) => (
                                                <span key={system} className="inline-flex h-7 items-center rounded-chip bg-subtle px-2.5 text-[13px] font-semibold">
                                                    {system}
                                                </span>
                                            ))}
                                        </span>
                                        {answers.systems_notes ? <span className="mt-1 block text-sm text-fg-soft">{answers.systems_notes}</span> : null}
                                    </Answer>
                                ) : null}
                                {request.urgency ? (
                                    <Answer term="Urgencia">
                                        <b>{URGENCY_LABELS[request.urgency]}</b>
                                        {answers.deadline ? <span> · para el {formatShortDate(answers.deadline)}</span> : null}
                                        {answers.deadline_reason ? <span className="text-fg-soft"> · “{answers.deadline_reason}”</span> : null}
                                    </Answer>
                                ) : null}
                                {request.attachments.length > 0 ? (
                                    <Answer term="Adjuntos">
                                        <span className="flex flex-wrap gap-2">
                                            {request.attachments.map((file) => (
                                                <a
                                                    key={file.id}
                                                    href={file.url}
                                                    className="inline-flex h-11 items-center gap-2 rounded-control border border-line px-3 text-sm font-semibold no-underline hover:bg-subtle"
                                                >
                                                    <Icon name="paperclip" size={15} />
                                                    {file.name}
                                                    <span className="tabular text-xs font-normal text-fg-muted">{formatFileSize(file.size)}</span>
                                                </a>
                                            ))}
                                        </span>
                                    </Answer>
                                ) : null}
                            </dl>
                        </section>
                    </div>

                    <aside aria-label="Evaluación" className="flex flex-col gap-4 lg:col-start-2 lg:row-span-3 lg:row-start-1">
                        <EvaluationPanel
                            request={request}
                            evaluation={evaluation}
                            areaHourValue={area_hour_value}
                            canViewCosts={canViewCosts}
                            firstResponse={pending}
                            onAction={(action) => open(action)}
                        />
                    </aside>

                    <div className="min-w-0 lg:col-start-1 lg:row-start-2">
                        <ConversationTabs props={props} />
                    </div>
                </div>
            </div>

            <RequestActionDialogs
                actions={actions}
                data={props}
                companies={companies}
                canViewCosts={canViewCosts}
                viewerId={auth.user.id}
                estimate={request.estimate}
                mergeFallback={duplicates}
            />

            {brief ? (
                <BriefPanel
                    open={briefOpen}
                    onClose={() => setBriefOpen(false)}
                    subject={`${brief.key} · ${brief.title}`}
                    build={buildBrief}
                    fileName={briefFileName(brief.key)}
                    initialView={briefState?.view}
                    initiallyCopied={briefState?.copied}
                />
            ) : null}
        </GestorLayout>
    );
}
