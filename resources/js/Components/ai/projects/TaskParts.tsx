import { router, useForm } from '@inertiajs/react';
import { useEffect, useRef, useState, type FormEvent, type ReactNode } from 'react';
import { KANBAN_COLUMNS } from '@/Components/ai/KanbanColumn';
import { TimerButton } from '@/Components/ai/TimerButton';
import { TaskGitHubBlock } from '@/Components/github/TaskGitHubParts';
import { Avatar } from '@/Components/ui/Avatar';
import { Button } from '@/Components/ui/Button';
import { Icon, type IconName } from '@/Components/ui/Icon';
import { TextArea } from '@/Components/ui/TextArea';
import { cx } from '@/Components/ui/utils';
import { formatFileSize, formatRelative, formatShortDate } from '@/lib/format';
import { route } from '@/lib/route';
import { formatHours } from '@/lib/timer';
import type { Comment, GithubLink, KanbanColumnKey, PersonRef, Subtask, TaskDetail } from '@/types/ai';
import type { TaskGitHub } from '@/types/pages/ai-github';
import type { TaskUpdatePayload } from '@/types/pages/ai-projects';

const update = (projectKey: string, taskKey: string, data: TaskUpdatePayload) =>
    router.put(route('ai.tasks.update', { project: projectKey, task: taskKey }), { ...data }, { preserveScroll: true, preserveState: true });

function Block({ title, aside, children }: { title: ReactNode; aside?: ReactNode; children: ReactNode }) {
    return (
        <section className="flex flex-col gap-2.5">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <h3 className="text-sm font-bold">{title}</h3>
                {aside}
            </div>
            {children}
        </section>
    );
}

// ---- subtasks -----------------------------------------------------------------

export interface SubtaskListProps {
    projectKey: string;
    taskKey: string;
    subtasks: Subtask[];
    /** Inline "Agregar subtarea" form. */
    canAdd?: boolean;
    /** Larger rows for the phone page. */
    size?: 'md' | 'lg';
}

/** Checklist; each box saves right away (PATCH `ai.subtasks.toggle`). */
export function SubtaskList({ projectKey, taskKey, subtasks, canAdd = true, size = 'md' }: SubtaskListProps) {
    const [done, setDone] = useState<Record<number, boolean>>(() => Object.fromEntries(subtasks.map((item) => [item.id, item.done])));
    const [adding, setAdding] = useState(false);
    const form = useForm<{ title: string }>({ title: '' });
    const count = subtasks.filter((item) => done[item.id]).length;

    useEffect(() => setDone(Object.fromEntries(subtasks.map((item) => [item.id, item.done]))), [subtasks]);

    const toggle = (subtask: Subtask, checked: boolean) => {
        setDone((current) => ({ ...current, [subtask.id]: checked }));
        router.patch(
            route('ai.subtasks.toggle', { project: projectKey, task: taskKey, subtask: subtask.id }),
            { done: checked },
            { preserveScroll: true, preserveState: true, onError: () => setDone((current) => ({ ...current, [subtask.id]: !checked })) },
        );
    };

    const add = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        if (!form.data.title.trim()) {
            form.setError('title', 'Escribe la subtarea.');
            return;
        }
        form.post(route('ai.subtasks.store', { project: projectKey, task: taskKey }), { preserveScroll: true, onSuccess: () => form.reset() });
    };

    return (
        <Block
            title={
                <>
                    Subtareas{' '}
                    <span className="tabular font-semibold text-fg-muted" aria-live="polite">
                        {count} de {subtasks.length}
                    </span>
                </>
            }
        >
            <ul className="flex flex-col">
                {subtasks.map((subtask) => (
                    <li key={subtask.id}>
                        <label className={cx('flex cursor-pointer items-start gap-3 rounded-control px-1', size === 'lg' ? 'min-h-12 py-3' : 'min-h-11 py-2.5', 'hover:bg-subtle')}>
                            <input
                                type="checkbox"
                                checked={done[subtask.id] ?? false}
                                onChange={(event) => toggle(subtask, event.target.checked)}
                                className="mt-px size-5 shrink-0 accent-brand-button"
                            />
                            <span className={cx('text-sm leading-5', done[subtask.id] && 'text-fg-muted line-through decoration-fg-muted')}>{subtask.title}</span>
                        </label>
                    </li>
                ))}
            </ul>
            {canAdd ? (
                adding ? (
                    <form onSubmit={add} className="flex flex-col gap-2 sm:flex-row sm:items-start">
                        <label className="sr-only" htmlFor={`subtask-${taskKey}`}>
                            Nueva subtarea
                        </label>
                        <input
                            id={`subtask-${taskKey}`}
                            autoFocus
                            value={form.data.title}
                            onChange={(event) => {
                                form.setData('title', event.target.value);
                                form.clearErrors('title');
                            }}
                            placeholder="Qué falta hacer"
                            aria-invalid={form.errors.title ? true : undefined}
                            className="h-11 min-w-0 flex-1 rounded-control border border-line-strong bg-surface px-3.5 text-base"
                        />
                        <div className="flex gap-2">
                            <Button type="submit" loading={form.processing}>
                                Agregar
                            </Button>
                            <Button variant="secondary" onClick={() => setAdding(false)}>
                                Cancelar
                            </Button>
                        </div>
                        {form.errors.title ? <p className="text-[13px] font-semibold text-status-failure">{form.errors.title}</p> : null}
                    </form>
                ) : (
                    <button
                        type="button"
                        onClick={() => setAdding(true)}
                        className="inline-flex h-11 items-center gap-1.5 self-start rounded-control px-2 text-[13px] font-bold text-fg-soft hover:bg-subtle hover:text-fg"
                    >
                        <Icon name="plus" size={14} strokeWidth={2.4} />
                        Agregar subtarea
                    </button>
                )
            ) : null}
        </Block>
    );
}

// ---- comments -----------------------------------------------------------------

export interface TaskCommentsProps {
    projectKey: string;
    taskKey: string;
    comments: Comment[];
    now: Date;
}

/** Task conversation (IT only) with the reply box. */
export function TaskComments({ projectKey, taskKey, comments, now }: TaskCommentsProps) {
    const form = useForm<{ body: string }>({ body: '' });

    const send = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        if (!form.data.body.trim()) {
            form.setError('body', 'Escribe el comentario.');
            return;
        }
        form.post(route('ai.tasks.comments.store', { project: projectKey, task: taskKey }), { preserveScroll: true, onSuccess: () => form.reset() });
    };

    return (
        <Block
            title={
                <>
                    Comentarios <span className="tabular font-semibold text-fg-muted">{comments.length}</span>
                </>
            }
        >
            {comments.length > 0 ? (
                <ol className="flex flex-col gap-3">
                    {comments.map((comment) => (
                        <li key={comment.id} className="flex items-start gap-2.5">
                            <Avatar name={comment.author.name} size="sm" />
                            <div className="flex min-w-0 flex-col gap-0.5">
                                <span className="text-[13px]">
                                    <b>{comment.author.name}</b> <span className="text-fg-muted">{formatRelative(comment.created_at, now)}</span>
                                </span>
                                <p className="text-sm leading-snug">{comment.body}</p>
                            </div>
                        </li>
                    ))}
                </ol>
            ) : (
                <p className="text-[13px] text-fg-muted">Nadie ha comentado esta tarea.</p>
            )}
            <form onSubmit={send} className="flex flex-col gap-2">
                <TextArea
                    label="Tu comentario"
                    rows={2}
                    value={form.data.body}
                    error={form.errors.body}
                    onChange={(event) => {
                        form.setData('body', event.target.value);
                        form.clearErrors('body');
                    }}
                />
                <Button type="submit" variant="secondary" loading={form.processing} className="self-end">
                    Comentar
                </Button>
            </form>
        </Block>
    );
}

// ---- GitHub -------------------------------------------------------------------

const PR_STATE: Record<NonNullable<GithubLink['pr']>['state'], string> = { open: 'abierto', review: 'en revisión', merged: 'unido', closed: 'cerrado' };
const CI_STATE: Record<NonNullable<GithubLink['ci']>, { label: string; tone: string; icon: IconName }> = {
    passing: { label: 'CI pasando', tone: 'bg-status-active-bg text-status-active', icon: 'check-circle' },
    failing: { label: 'CI fallando', tone: 'bg-status-failure-bg text-status-failure', icon: 'x-circle' },
    running: { label: 'CI corriendo', tone: 'bg-status-scheduled-bg text-status-scheduled', icon: 'refresh' },
};

function GithubChips({ github, note }: { github: GithubLink | null; note?: string | null }) {
    const chip = 'inline-flex h-7 items-center gap-1.5 rounded-chip px-2.5 text-[13px] font-semibold';
    return (
        <Block title="GitHub">
            {github && (github.issue || github.pr || github.ci) ? (
                <div className="flex flex-wrap gap-1.5">
                    {github.issue ? (
                        <span className={cx(chip, 'bg-subtle text-fg-soft')}>
                            <Icon name="issue" size={13} />
                            Issue #{github.issue.number} {github.issue.state === 'open' ? 'abierto' : 'cerrado'}
                        </span>
                    ) : null}
                    {github.pr ? (
                        <span className={cx(chip, github.pr.state === 'review' ? 'bg-status-maintenance-bg text-status-maintenance' : 'bg-subtle text-fg-soft')}>
                            <Icon name="pull-request" size={13} />
                            PR #{github.pr.number} {PR_STATE[github.pr.state]}
                        </span>
                    ) : null}
                    {github.ci ? (
                        <span className={cx(chip, CI_STATE[github.ci].tone)}>
                            <Icon name={CI_STATE[github.ci].icon} size={13} />
                            {CI_STATE[github.ci].label}
                        </span>
                    ) : null}
                </div>
            ) : (
                <p className="text-[13px] text-fg-muted">Sin issue ni PR.</p>
            )}
            {note ? <p className="text-xs text-fg-muted">{note}</p> : null}
        </Block>
    );
}

// ---- drawer content -----------------------------------------------------------

export interface TaskDetailContentProps {
    task: TaskDetail;
    projectKey: string;
    people: PersonRef[];
    now: string;
    /** Viewer's id: the timer only stops for its owner. */
    viewerId: number;
    onTimerStart: () => void;
    onTimerStop: () => void;
    /** Stage D · issue, PR and "Crear issue en GitHub"; without it the Stage B chips show. */
    github?: TaskGitHub | null;
    /** The issue was just created (marks it "nuevo"). */
    issueIsNew?: boolean;
}

/** B-TaskDetail body: status, labels, timer, description, subtasks, criteria, comments; side facts in a 260 px column. */
export function TaskDetailContent({ task, projectKey, people, now, viewerId, onTimerStart, onTimerStop, github = null, issueIsNew = false }: TaskDetailContentProps) {
    const clock = new Date(now);
    const [editing, setEditing] = useState(false);
    const description = useForm<{ description: string }>({ description: task.description });
    const file = useRef<HTMLInputElement>(null);
    const running = task.timer?.running === true;
    const timerOwner = !task.timer || task.timer.person_id === viewerId;
    const total = task.time_by_person.reduce((sum, row) => sum + row.hours, 0);
    const assignable = people.filter((person) => !task.assignees.some((assignee) => assignee.id === person.id));
    const prNote = task.github?.pr ? `El PR #${task.github.pr.number} se revisa junto con la tarea.` : null;

    const saveDescription = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        description.put(route('ai.tasks.update', { project: projectKey, task: task.key }), { preserveScroll: true, onSuccess: () => setEditing(false) });
    };

    const attach = (selected: File | undefined) => {
        if (!selected) return;
        router.post(route('ai.tasks.attachments.store', { project: projectKey, task: task.key }), { file: selected }, { forceFormData: true, preserveScroll: true });
    };

    return (
        <div className="grid gap-6 md:grid-cols-[minmax(0,1fr)_240px] lg:grid-cols-[minmax(0,1fr)_260px]">
            <div className="flex min-w-0 flex-col gap-6">
                <div className="flex flex-wrap items-end gap-3">
                    <label className="flex flex-col gap-1.5 text-[13px] font-semibold">
                        Estado
                        <select
                            value={task.column}
                            onChange={(event) => update(projectKey, task.key, { column: event.target.value as KanbanColumnKey })}
                            className="h-11 rounded-control border border-line-strong bg-surface px-3 text-sm font-semibold"
                        >
                            {KANBAN_COLUMNS.map((column) => (
                                <option key={column.key} value={column.key}>
                                    {column.label}
                                </option>
                            ))}
                        </select>
                    </label>
                    <ul className="flex flex-wrap gap-1.5 pb-2" aria-label="Etiquetas">
                        {task.labels.map((label) => (
                            <li key={label} className="inline-flex h-6 items-center rounded-[8px] bg-subtle px-2 text-xs font-semibold text-fg-soft">
                                {label}
                            </li>
                        ))}
                    </ul>
                    {task.column !== 'done' && timerOwner ? (
                        <TimerButton
                            taskKey={task.key}
                            running={running}
                            startedAt={task.timer?.started_at}
                            clockStartAt={now}
                            onStart={onTimerStart}
                            onStop={onTimerStop}
                            size="lg"
                            className="ml-auto"
                        />
                    ) : null}
                </div>

                {task.blocked_by ? (
                    <p className="flex items-start gap-2 rounded-control border border-status-failure-line bg-status-failure-bg px-3.5 py-3 text-sm font-semibold text-status-failure">
                        <Icon name="blocked" size={16} className="mt-0.5" />
                        Bloqueada: espera {task.blocked_by.title.charAt(0).toLowerCase() + task.blocked_by.title.slice(1)} ({task.blocked_by.area_name})
                    </p>
                ) : null}

                <Block
                    title="Descripción"
                    aside={
                        !editing ? (
                            <button type="button" onClick={() => setEditing(true)} className="inline-flex h-11 items-center gap-1.5 rounded-control px-2 text-[13px] font-bold text-fg-soft hover:bg-subtle hover:text-fg">
                                <Icon name="edit" size={14} />
                                Editar
                            </button>
                        ) : null
                    }
                >
                    {editing ? (
                        <form onSubmit={saveDescription} className="flex flex-col gap-2">
                            <TextArea
                                label="Descripción"
                                rows={4}
                                value={description.data.description}
                                error={description.errors.description}
                                onChange={(event) => description.setData('description', event.target.value)}
                                containerClassName="[&>label]:sr-only"
                            />
                            <div className="flex gap-2 self-end">
                                <Button
                                    variant="secondary"
                                    onClick={() => {
                                        description.reset();
                                        setEditing(false);
                                    }}
                                >
                                    Cancelar
                                </Button>
                                <Button type="submit" loading={description.processing}>
                                    Guardar
                                </Button>
                            </div>
                        </form>
                    ) : (
                        <p className="text-sm leading-relaxed text-fg-soft">{task.description || 'Sin descripción.'}</p>
                    )}
                </Block>

                <SubtaskList projectKey={projectKey} taskKey={task.key} subtasks={task.subtasks} />

                {task.criteria.length > 0 ? (
                    <Block title="Criterios de aceptación relacionados">
                        <ul className="flex flex-col gap-2">
                            {task.criteria.map((criterion) => (
                                <li key={criterion.id} className="flex items-start gap-2 text-sm">
                                    <Icon name={criterion.validated ? 'check-circle' : 'clipboard-check'} size={16} className={cx('mt-0.5', criterion.validated ? 'text-status-active' : 'text-fg-muted')} />
                                    {criterion.text}
                                </li>
                            ))}
                        </ul>
                    </Block>
                ) : null}

                <TaskComments projectKey={projectKey} taskKey={task.key} comments={task.comments} now={clock} />
            </div>

            <aside aria-label="Datos de la tarea" className="flex min-w-0 flex-col gap-5 border-line md:border-l md:pl-5">
                <Block title="Responsables">
                    <ul className="flex flex-col">
                        {task.assignees.map((person) => (
                            <li key={person.id} className="flex min-h-11 items-center gap-2">
                                <Avatar name={person.name} size="xs" />
                                <span className="min-w-0 flex-1 truncate text-sm font-semibold">{person.name}</span>
                                <button
                                    type="button"
                                    aria-label={`Quitar a ${person.name}`}
                                    title="Quitar"
                                    onClick={() => update(projectKey, task.key, { assignee_ids: task.assignees.filter((a) => a.id !== person.id).map((a) => a.id) })}
                                    className="flex size-11 items-center justify-center rounded-control text-fg-muted hover:bg-subtle hover:text-fg"
                                >
                                    <Icon name="x" size={14} />
                                </button>
                            </li>
                        ))}
                    </ul>
                    {assignable.length > 0 ? (
                        <label className="relative inline-flex h-11 items-center gap-1.5 self-start rounded-control px-2 text-[13px] font-bold text-fg-soft hover:bg-subtle hover:text-fg">
                            <Icon name="plus" size={14} strokeWidth={2.4} />
                            Agregar responsable
                            <select
                                aria-label="Agregar responsable"
                                value=""
                                onChange={(event) =>
                                    event.target.value && update(projectKey, task.key, { assignee_ids: [...task.assignees.map((a) => a.id), Number(event.target.value)] })
                                }
                                className="absolute inset-0 cursor-pointer appearance-none opacity-0"
                            >
                                <option value="">Elige a alguien</option>
                                {assignable.map((person) => (
                                    <option key={person.id} value={person.id}>
                                        {person.name}
                                    </option>
                                ))}
                            </select>
                        </label>
                    ) : null}
                </Block>

                <Block title="Fechas">
                    <dl className="grid grid-cols-2 gap-3 text-[13px]">
                        <div className="flex flex-col gap-1">
                            <dt className="text-fg-muted">Inicio</dt>
                            <dd className="font-semibold">{task.started_at ? formatShortDate(task.started_at) : 'Sin empezar'}</dd>
                        </div>
                        <div className="flex flex-col gap-1">
                            <dt>
                                <label htmlFor={`due-${task.key}`} className="text-fg-muted">
                                    Vence
                                </label>
                            </dt>
                            <dd>
                                <input
                                    id={`due-${task.key}`}
                                    type="date"
                                    value={task.due_at?.slice(0, 10) ?? ''}
                                    onChange={(event) => update(projectKey, task.key, { due_at: event.target.value || null })}
                                    className="h-11 w-full rounded-control border border-line-strong bg-surface px-2 text-sm font-semibold"
                                />
                            </dd>
                        </div>
                    </dl>
                </Block>

                <Block title="Tiempo registrado">
                    {task.time_by_person.length > 0 ? (
                        <dl className="flex flex-col gap-1.5 text-[13px]">
                            {task.time_by_person.map((row) => (
                                <div key={row.person.id} className="flex items-center gap-2">
                                    <Avatar name={row.person.name} size="xs" />
                                    <dt className="min-w-0 flex-1 truncate">{row.person.name}</dt>
                                    <dd className="tabular font-semibold">{formatHours(row.hours)}</dd>
                                </div>
                            ))}
                            <div className="flex items-center justify-between border-t border-line pt-1.5">
                                <dt className="font-bold">Total en la tarea</dt>
                                <dd className="tabular font-bold">{formatHours(total)}</dd>
                            </div>
                        </dl>
                    ) : (
                        <p className="text-[13px] text-fg-muted">Nadie ha registrado horas.</p>
                    )}
                    {running ? <p className="text-xs text-fg-muted">Sin contar el cronómetro que corre ahora.</p> : null}
                    <Button variant="secondary" icon="clock" href={route('ai.time-entries.create', { project: projectKey, task: task.key })} className="self-start">
                        Agregar horas a mano
                    </Button>
                </Block>

                {github ? (
                    <Block title="GitHub">
                        <TaskGitHubBlock github={github} task={task} projectKey={projectKey} now={now} issueIsNew={issueIsNew} />
                    </Block>
                ) : (
                    <GithubChips github={task.github} note={prNote} />
                )}

                <Block title="Adjuntos">
                    {task.attachments.length > 0 ? (
                        <ul className="flex flex-col gap-1">
                            {task.attachments.map((attachment) => (
                                <li key={attachment.id}>
                                    <a href={attachment.url} className="flex min-h-11 items-center gap-2 rounded-control px-1 text-[13px] text-fg no-underline hover:bg-subtle">
                                        <Icon name="paperclip" size={14} className="text-fg-muted" />
                                        <span className="min-w-0 flex-1 truncate font-semibold">{attachment.name}</span>
                                        <span className="tabular text-xs text-fg-muted">{formatFileSize(attachment.size)}</span>
                                    </a>
                                </li>
                            ))}
                        </ul>
                    ) : null}
                    <input ref={file} type="file" className="sr-only" tabIndex={-1} aria-hidden="true" onChange={(event) => attach(event.target.files?.[0])} />
                    <button
                        type="button"
                        onClick={() => file.current?.click()}
                        className="inline-flex h-11 items-center gap-1.5 self-start rounded-control px-2 text-[13px] font-bold text-fg-soft hover:bg-subtle hover:text-fg"
                    >
                        <Icon name="upload" size={14} />
                        Adjuntar archivo
                    </button>
                </Block>
            </aside>
        </div>
    );
}
