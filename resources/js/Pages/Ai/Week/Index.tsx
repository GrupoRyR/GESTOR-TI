import { router, useForm } from '@inertiajs/react';
import { useId, useRef, useState, type FormEvent, type KeyboardEvent } from 'react';
import { DueText } from '@/Components/ai/triage/FirstResponse';
import { dueState, sinceLabel } from '@/Components/ai/triage/triage';
import { Elapsed, TimerButton } from '@/Components/ai/TimerButton';
import { Button } from '@/Components/ui/Button';
import { Checkbox } from '@/Components/ui/Checkbox';
import { CompanyMark } from '@/Components/ui/CompanyMark';
import { Icon } from '@/Components/ui/Icon';
import { TextArea } from '@/Components/ui/TextArea';
import { cx } from '@/Components/ui/utils';
import { GestorLayout } from '@/Layouts/GestorLayout';
import { formatLongDate, formatShortDate, formatTime } from '@/lib/format';
import { route } from '@/lib/route';
import type { RunningTimer, WeekColumn, WeekIndexProps, WeekItem, WeeklyReview } from '@/types/pages/ai-triage';
import type { Company, PageProps } from '@/types/shared';

const COLUMNS: { key: WeekColumn; title: string; short: string; tone: string; empty: string }[] = [
    { key: 'due', title: 'Vence esta semana', short: 'Vence', tone: 'bg-status-failure-bg text-status-failure', empty: 'Nada vence esta semana.' },
    { key: 'blocked', title: 'Bloqueado', short: 'Bloqueado', tone: 'bg-status-failure-bg text-status-failure', empty: 'Nada bloqueado.' },
    { key: 'waiting', title: 'Espera respuesta de un usuario', short: 'Espera', tone: 'bg-status-maintenance-bg text-status-maintenance', empty: 'Nadie te debe una respuesta.' },
];

const capitalize = (text: string) => text.charAt(0).toUpperCase() + text.slice(1);

/** "Miércoles 7 oct". */
function dayLabel(iso: string): string {
    return `${capitalize(formatLongDate(iso).split(' ')[0] ?? '')} ${formatShortDate(iso)}`;
}

function RemindButton({ label, url }: { label: string; url: string }) {
    const [sent, setSent] = useState(false);
    return (
        <button
            type="button"
            disabled={sent}
            onClick={() => router.post(url, {}, { preserveScroll: true, onSuccess: () => setSent(true) })}
            className="inline-flex h-11 shrink-0 items-center gap-1.5 rounded-control px-3 text-[13px] font-bold text-fg-soft hover:bg-subtle hover:text-fg disabled:text-status-active"
        >
            {sent ? <Icon name="check" size={14} /> : null}
            {sent ? 'Recordado' : label}
        </button>
    );
}

function ItemAction({ item }: { item: WeekItem }) {
    const { target } = item;
    switch (target.type) {
        case 'request':
            return item.column === 'due' ? (
                <Button variant="secondary" href={route('ai.requests.show', { request: target.key })} className="shrink-0">
                    Responder
                </Button>
            ) : (
                <RemindButton label="Recordar" url={route('ai.requests.remind', { request: target.key })} />
            );
        case 'task':
            return (
                <TimerButton
                    taskKey={`${target.project_key} ${target.task_key}`}
                    running={false}
                    onStart={() => router.post(route('ai.timer.start', { project: target.project_key, task: target.task_key }), {}, { preserveScroll: true })}
                    onStop={() => undefined}
                />
            );
        case 'project':
            return (
                <Button variant="secondary" href={route('ai.projects.show', { project: target.key })} className="shrink-0">
                    Abrir
                </Button>
            );
        case 'requirement':
            return <RemindButton label={`Recordar a ${target.area_name}`} url={route('ai.requirements.remind', { requirement: target.id })} />;
        case 'approval':
            return <RemindButton label="Recordar" url={route('ai.approvals.remind', { request: target.key })} />;
    }
}

function ItemCard({ item, company, now }: { item: WeekItem; company: Company | undefined; now: Date }) {
    let status = null;
    if (item.column === 'due' && item.due_at) {
        const state = dueState(item.due_at, now, 'agenda');
        status = <DueText level={state.level} label={state.label} />;
    } else if (item.column === 'blocked' && item.since) {
        status = <span className="text-[13px] font-semibold text-status-failure">{sinceLabel(item.since, now).replace(/^hace /, '')} esperando</span>;
    } else if (item.since) {
        status = <span className="text-[13px] font-semibold text-status-maintenance">{sinceLabel(item.since, now)}</span>;
    }

    return (
        <article className="flex flex-col gap-2 rounded-[12px] border border-line bg-surface p-3.5">
            <div className="flex items-center gap-2">
                {company ? <CompanyMark company={company} size={20} /> : null}
                <b className="tabular text-[13px]">{item.ref_label}</b>
                <span className="ml-auto">{status}</span>
            </div>
            <p className="text-sm leading-snug font-semibold">{item.title}</p>
            {item.reason ? (
                <p className="flex items-start gap-1.5 text-[13px] leading-snug text-status-failure">
                    <Icon name="blocked" size={14} className="mt-0.5" />
                    {item.reason}
                </p>
            ) : null}
            <div className="flex items-center justify-between gap-2">
                <span className={cx('flex min-w-0 items-center gap-1.5 truncate text-[13px]', item.column === 'waiting' ? 'text-fg-soft' : 'text-fg-muted')}>
                    {item.column === 'waiting' ? <Icon name="user" size={14} /> : null}
                    {item.person}
                </span>
                <ItemAction item={item} />
            </div>
        </article>
    );
}

function TimerStrip({ timer, now }: { timer: RunningTimer; now: string }) {
    const stop = () => router.post(route('ai.timer.stop'), {}, { preserveScroll: true });
    const where = [`${timer.project_key} · ${timer.task_key}`, `${timer.subtasks_done} de ${timer.subtasks_total} subtareas`, timer.due_at ? `vence ${formatShortDate(timer.due_at)}` : null]
        .filter(Boolean)
        .join(' · ');
    return (
        <section aria-label="Cronómetro" className="flex items-center gap-3 rounded-card bg-fg px-4 py-3 text-on-fg md:gap-5 md:px-5">
            <span aria-hidden="true" className="size-2.5 shrink-0 animate-pulse rounded-full bg-status-active-dot motion-reduce:animate-none" />
            <span className="flex min-w-0 flex-1 flex-col">
                <span className="truncate text-xs opacity-75">
                    <span className="hidden md:inline">Trabajando ahora · </span>
                    {where}
                </span>
                <b className="truncate text-[15px]">{timer.task_title}</b>
            </span>
            <Elapsed startedAt={timer.started_at} clockStartAt={now} className="hidden font-display text-[28px] md:inline" />
            <button
                type="button"
                onClick={stop}
                className="inline-flex h-11 shrink-0 items-center gap-2 rounded-control bg-on-fg px-4 text-sm font-bold text-fg hover:brightness-95"
            >
                <span aria-hidden="true" className="size-2 rounded-[2px] bg-current" />
                <span className="hidden md:inline">Parar</span>
                <span className="md:hidden">
                    <span className="sr-only">Parar cronómetro de {timer.project_key} {timer.task_key}, lleva </span>
                    <Elapsed startedAt={timer.started_at} clockStartAt={now} />
                </span>
            </button>
        </section>
    );
}

function ReviewCard({ review }: { review: WeeklyReview }) {
    const form = useForm<{ decided: string[]; notes: string }>({ decided: [], notes: '' });
    const titleId = useId();

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        if (form.data.decided.length === 0 && !form.data.notes.trim()) {
            form.setError('notes', 'Marca lo que se decidió o escríbelo.');
            return;
        }
        form.transform((data) => ({ decided: data.decided, notes: data.notes.trim() || null }));
        form.post(route('ai.reviews.store'), { preserveScroll: true, onSuccess: () => form.reset() });
    };

    return (
        <form onSubmit={submit} aria-labelledby={titleId} className="flex flex-col gap-3 rounded-card border border-line bg-surface p-4 md:p-5">
            <div className="flex items-center gap-3">
                <span aria-hidden="true" className="flex size-11 shrink-0 items-center justify-center rounded-icon bg-brand-tint text-brand-ink">
                    <Icon name="calendar" size={20} />
                </span>
                <span className="flex flex-col">
                    <h2 id={titleId} className="text-base font-bold">
                        {review.title}
                    </h2>
                    <span className="text-[13px] text-fg-muted">
                        {dayLabel(review.at)}, {formatTime(review.at)}
                    </span>
                </span>
            </div>
            <fieldset className="flex flex-col">
                <legend className="text-xs font-semibold text-fg-muted">Para decidir</legend>
                {review.agenda.map((point) => (
                    <Checkbox
                        key={point.id}
                        label={<span className="font-normal">{point.text}</span>}
                        checked={form.data.decided.includes(point.id)}
                        onChange={(event) => {
                            form.clearErrors('notes');
                            form.setData('decided', event.target.checked ? [...form.data.decided, point.id] : form.data.decided.filter((id) => id !== point.id));
                        }}
                    />
                ))}
            </fieldset>
            <TextArea
                label="Qué se decidió"
                optional
                rows={2}
                value={form.data.notes}
                error={form.errors.notes}
                onChange={(event) => {
                    form.setData('notes', event.target.value);
                    form.clearErrors('notes');
                }}
            />
            <Button type="submit" loading={form.processing} className="self-start">
                Registrar lo decidido
            </Button>
            {form.recentlySuccessful ? (
                <p role="status" className="text-[13px] font-semibold text-status-active">
                    Quedó registrado.
                </p>
            ) : null}
            {review.previous ? (
                <p className="text-xs text-fg-muted">
                    La anterior fue el {formatShortDate(review.previous.at)}: {review.previous.decisions}{' '}
                    {review.previous.decisions === 1 ? 'decisión registrada' : 'decisiones registradas'}.
                </p>
            ) : null}
        </form>
    );
}

/** "Mi semana" (B-MyWeek / B-MyWeekPhone): what is due, blocked or waiting on someone, the running timer and the weekly review. */
export default function WeekIndex(props: PageProps<WeekIndexProps>) {
    const { items, running_timer, review, today, week_ends_on, companies, current_company_id } = props;
    const now = new Date(props.now);
    const [tab, setTab] = useState<WeekColumn>('due');
    const tabRefs = useRef<Record<WeekColumn, HTMLButtonElement | null>>({ due: null, blocked: null, waiting: null });
    const base = useId();
    const byColumn = (column: WeekColumn) => items.filter((item) => item.column === column);
    const counts = { due: byColumn('due').length, blocked: byColumn('blocked').length, waiting: byColumn('waiting').length };
    const companyOf = (id: number) => (current_company_id === null ? companies.find((company) => company.id === id) : undefined);

    const summary = [
        `${counts.due} ${counts.due === 1 ? 'cosa vence' : 'cosas vencen'} esta semana`,
        `${counts.blocked} ${counts.blocked === 1 ? 'está bloqueada' : 'están bloqueadas'}`,
        `${counts.waiting} ${counts.waiting === 1 ? 'espera' : 'esperan'} respuesta de alguien`,
    ];

    const onTabKey = (event: KeyboardEvent<HTMLDivElement>) => {
        if (event.key !== 'ArrowRight' && event.key !== 'ArrowLeft') return;
        event.preventDefault();
        const index = COLUMNS.findIndex((column) => column.key === tab);
        const next = COLUMNS[(index + (event.key === 'ArrowRight' ? 1 : -1) + COLUMNS.length) % COLUMNS.length]!.key;
        setTab(next);
        tabRefs.current[next]?.focus();
    };

    const list = (column: WeekColumn) => {
        const rows = byColumn(column);
        const meta = COLUMNS.find((item) => item.key === column)!;
        return rows.length === 0 ? (
            <p className="rounded-[12px] border border-dashed border-line-strong p-4 text-sm text-fg-muted">{meta.empty}</p>
        ) : (
            <ul className="flex flex-col gap-2">
                {rows.map((item) => (
                    <li key={item.id}>
                        <ItemCard item={item} company={companyOf(item.company_id)} now={now} />
                    </li>
                ))}
            </ul>
        );
    };

    return (
        <GestorLayout shared={props} title="Mi semana · Gestor IA" tab="week" mode="it">
            <div className="flex flex-col gap-4">
                <div className="flex flex-col gap-1">
                    <h1 className="font-display text-[26px] leading-tight font-bold tracking-[-0.02em] md:text-[30px]">Mi semana</h1>
                    <p className="hidden text-sm text-fg-soft md:block">
                        {dayLabel(today)} · <b className="text-fg">{`${summary[0]}, ${summary[1]} y ${summary[2]}`}</b>
                    </p>
                </div>

                {running_timer ? <TimerStrip timer={running_timer} now={props.now} /> : null}

                {/* Phone and tablet: one column at a time. */}
                <div className="flex flex-col gap-3 lg:hidden">
                    <div role="tablist" aria-label="Mi semana" onKeyDown={onTabKey} className="grid grid-cols-3 gap-0.5 rounded-control bg-subtle p-[3px]">
                        {COLUMNS.map((column) => {
                            const selected = column.key === tab;
                            return (
                                <button
                                    key={column.key}
                                    ref={(element) => {
                                        tabRefs.current[column.key] = element;
                                    }}
                                    type="button"
                                    role="tab"
                                    id={`${base}-${column.key}-tab`}
                                    aria-selected={selected}
                                    aria-controls={`${base}-${column.key}`}
                                    tabIndex={selected ? 0 : -1}
                                    onClick={() => setTab(column.key)}
                                    className={cx(
                                        'flex h-11 items-center justify-center gap-1.5 rounded-chip text-[13px] transition-colors duration-150',
                                        selected ? 'bg-surface font-bold text-fg shadow-[0_1px_2px_rgb(23_25_28/0.12)]' : 'font-semibold text-fg-soft',
                                    )}
                                >
                                    {column.short}
                                    <span className={cx('tabular rounded-full px-1.5 text-xs font-bold', column.tone)}>{counts[column.key]}</span>
                                </button>
                            );
                        })}
                    </div>
                    {tab === 'due' ? <p className="text-[13px] text-fg-muted">Esta semana, hasta el {formatLongDate(week_ends_on).split(' ')[0]} {formatShortDate(week_ends_on)}</p> : null}
                    {COLUMNS.map((column) => (
                        <div key={column.key} role="tabpanel" id={`${base}-${column.key}`} aria-labelledby={`${base}-${column.key}-tab`} hidden={tab !== column.key}>
                            {list(column.key)}
                        </div>
                    ))}
                </div>

                {/* Desktop: the three columns side by side. */}
                <div className="hidden gap-4 lg:grid lg:grid-cols-3 lg:items-start">
                    {COLUMNS.map((column) => (
                        <section key={column.key} aria-label={column.title} className="flex flex-col gap-2.5 rounded-card bg-subtle p-3">
                            <h2 className="flex items-center gap-2 px-1 text-sm font-bold">
                                {column.title}
                                <span className={cx('tabular rounded-full px-1.5 text-xs', column.tone)}>{counts[column.key]}</span>
                            </h2>
                            {list(column.key)}
                        </section>
                    ))}
                </div>

                {review ? (
                    <div className="max-w-[720px]">
                        <ReviewCard review={review} />
                    </div>
                ) : null}
            </div>
        </GestorLayout>
    );
}
