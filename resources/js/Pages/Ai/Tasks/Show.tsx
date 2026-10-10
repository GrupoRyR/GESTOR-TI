import { router, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { SubtaskList } from '@/Components/ai/projects/TaskParts';
import { columnLabel } from '@/Components/ai/KanbanColumn';
import { Elapsed } from '@/Components/ai/TimerButton';
import { TaskGitHubBlock, TaskGitHubHistory, TaskPullRequestCard } from '@/Components/github/TaskGitHubParts';
import { Avatar } from '@/Components/ui/Avatar';
import { Button } from '@/Components/ui/Button';
import { Icon } from '@/Components/ui/Icon';
import { cx } from '@/Components/ui/utils';
import { GestorLayout } from '@/Layouts/GestorLayout';
import { formatRelative, formatShortDate, formatTime } from '@/lib/format';
import { route } from '@/lib/route';
import { formatHours } from '@/lib/timer';
import type { TasksShowProps } from '@/types/pages/ai-projects';
import type { PageProps } from '@/types/shared';

/**
 * B-TaskPhone: the task with its stopwatch as the hero, subtasks and a quick
 * comment. Stage D adds the PR card and history (D-GitHubPhone) or the issue
 * block with "Crear issue en GitHub". Centered column on desktop.
 */
export default function TasksShow(props: PageProps<TasksShowProps>) {
    const { project, task, today_project_hours, auth } = props;
    const github = props.github ?? null;
    const now = new Date(props.now);
    const running = task.timer?.running === true && task.timer.started_at !== null;
    const mine = !task.timer || task.timer.person_id === auth.user.id;
    const last = task.comments[task.comments.length - 1];
    const form = useForm<{ body: string }>({ body: '' });
    /** Ziggy appends `tarea` as a query string: the board opens with this task's drawer. */
    const board = route('ai.projects.board', { project: project.key, tarea: task.key });

    const start = () => router.post(route('ai.timer.start', { project: project.key, task: task.key }), {}, { preserveScroll: true });
    const stop = () => router.post(route('ai.timer.stop'), {}, { preserveScroll: true });

    const send = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        if (!form.data.body.trim()) {
            form.setError('body', 'Escribe el comentario.');
            return;
        }
        form.post(route('ai.tasks.comments.store', { project: project.key, task: task.key }), { preserveScroll: true, onSuccess: () => form.reset() });
    };

    return (
        <GestorLayout
            shared={props}
            title={`${project.key} ${task.key} · Gestor IA`}
            tab="projects"
            mode="it"
            breadcrumb={[
                { label: 'Tablero', href: board },
                { label: `${project.key} · ${task.key}` },
            ]}
        >
            <div className="mx-auto flex w-full max-w-[560px] flex-col gap-5">
                <div className="flex flex-col gap-2">
                    <h1 className="font-display text-[24px] leading-[1.15] font-bold tracking-[-0.02em] md:text-[28px]">{task.title}</h1>
                    <div className="flex flex-wrap items-center gap-x-3 gap-y-1.5 text-[13px] text-fg-soft">
                        <span className="inline-flex items-center gap-1.5 font-semibold">
                            <Icon name={task.column === 'done' ? 'check-circle' : 'list'} size={14} />
                            {columnLabel(task.column)}
                        </span>
                        {task.due_at ? (
                            <span className="inline-flex items-center gap-1.5">
                                <Icon name="calendar" size={14} />
                                vence {formatShortDate(task.due_at)}
                            </span>
                        ) : null}
                        <span className="inline-flex items-center gap-1">
                            {task.assignees.map((person) => (
                                <Avatar key={person.id} name={person.name} size="xs" decorative={false} />
                            ))}
                        </span>
                    </div>
                    {task.blocked_by ? (
                        <p className="flex items-start gap-2 text-[13px] leading-snug font-semibold text-status-failure">
                            <Icon name="blocked" size={14} className="mt-0.5" />
                            Bloqueada: espera {task.blocked_by.title.charAt(0).toLowerCase() + task.blocked_by.title.slice(1)} ({task.blocked_by.area_name})
                        </p>
                    ) : null}
                </div>

                {github?.pull_request ? <TaskPullRequestCard github={github} now={props.now} /> : null}
                {github && !github.pull_request ? (
                    <section aria-labelledby="task-github" className="flex flex-col gap-2.5 rounded-card border border-line bg-surface p-4">
                        <h2 id="task-github" className="text-sm font-bold">
                            GitHub
                        </h2>
                        <TaskGitHubBlock github={github} task={task} projectKey={project.key} now={props.now} />
                    </section>
                ) : null}
                {github?.pull_request ? <TaskGitHubHistory github={github} now={props.now} /> : null}

                {task.column !== 'done' ? (
                    <section
                        aria-label="Cronómetro"
                        className={cx('flex flex-col items-center gap-3 rounded-card px-5 py-6 text-center', running ? 'bg-fg text-on-fg' : 'border border-line bg-surface')}
                    >
                        {running && task.timer?.started_at ? (
                            <>
                                <span className="inline-flex items-center gap-2 text-[13px] opacity-80">
                                    <span aria-hidden="true" className="size-2 animate-pulse rounded-full bg-status-active-dot motion-reduce:animate-none" />
                                    Corriendo desde las {formatTime(task.timer.started_at)}
                                </span>
                                <Elapsed startedAt={task.timer.started_at} clockStartAt={props.now} className="font-display text-[56px] leading-none tracking-[-0.02em]" />
                                {mine ? (
                                    <button
                                        type="button"
                                        onClick={stop}
                                        className="inline-flex h-14 w-full max-w-[320px] items-center justify-center gap-2.5 rounded-control bg-on-fg text-base font-bold text-fg"
                                    >
                                        <span aria-hidden="true" className="size-2.5 rounded-[2px] bg-current" />
                                        Parar
                                        <span className="sr-only"> cronómetro de {task.key}</span>
                                    </button>
                                ) : (
                                    <p className="text-[13px] opacity-80">Este cronómetro es de otra persona.</p>
                                )}
                            </>
                        ) : (
                            <>
                                <span className="text-[13px] text-fg-muted">El cronómetro está parado</span>
                                <span className="tabular font-display text-[56px] leading-none font-bold tracking-[-0.02em] text-fg-muted">00:00:00</span>
                                <Button size="xl" icon="play" fullWidth onClick={start} className="max-w-[320px]">
                                    Iniciar
                                </Button>
                            </>
                        )}
                        {today_project_hours > 0 ? (
                            <p className={cx('text-[13px]', running ? 'opacity-80' : 'text-fg-muted')}>Hoy llevas {formatHours(today_project_hours)} en este proyecto</p>
                        ) : null}
                    </section>
                ) : null}

                <section className="rounded-card border border-line bg-surface p-4">
                    <SubtaskList projectKey={project.key} taskKey={task.key} subtasks={task.subtasks} canAdd={false} size="lg" />
                </section>

                <form onSubmit={send} className="flex flex-col gap-2 rounded-card border border-line bg-surface p-4">
                    <label htmlFor="quick-comment" className="text-sm font-bold">
                        Comentario rápido
                    </label>
                    <div className="flex items-start gap-2">
                        <input
                            id="quick-comment"
                            value={form.data.body}
                            placeholder="Comentario"
                            aria-invalid={form.errors.body ? true : undefined}
                            aria-describedby={form.errors.body ? 'quick-comment-error' : undefined}
                            onChange={(event) => {
                                form.setData('body', event.target.value);
                                form.clearErrors('body');
                            }}
                            className="h-12 min-w-0 flex-1 rounded-control border border-line-strong bg-surface px-3.5 text-base"
                        />
                        <button
                            type="submit"
                            aria-label="Enviar comentario"
                            disabled={form.processing}
                            className="flex size-12 shrink-0 items-center justify-center rounded-control bg-brand-button text-brand-button-ink hover:brightness-[0.93] disabled:opacity-60"
                        >
                            <Icon name="message" size={18} />
                        </button>
                    </div>
                    {form.errors.body ? (
                        <p id="quick-comment-error" className="text-[13px] font-semibold text-status-failure">
                            {form.errors.body}
                        </p>
                    ) : null}
                    {form.recentlySuccessful ? (
                        <p role="status" className="text-[13px] font-semibold text-status-active">
                            Comentario enviado.
                        </p>
                    ) : null}
                    {last ? (
                        <p className="text-[13px] leading-snug text-fg-muted">
                            Último: {last.author.name.split(' ')[0]}, {formatRelative(last.created_at, now)} “{last.body.length > 48 ? `${last.body.slice(0, 48).trimEnd()}…` : last.body}”
                        </p>
                    ) : null}
                </form>

                <div className="flex flex-wrap gap-2">
                    <Button variant="secondary" icon="clock" href={route('ai.time-entries.create', { project: project.key, task: task.key })}>
                        Agregar horas a mano
                    </Button>
                    <Button variant="quiet" href={board}>
                        Ver detalle completo
                    </Button>
                </div>
            </div>
        </GestorLayout>
    );
}
