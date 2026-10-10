import { router, useForm } from '@inertiajs/react';
import { useEffect, useId, useRef, useState, type DragEvent, type FormEvent, type KeyboardEvent } from 'react';
import { dropHint, moveTask } from '@/Components/ai/projects/projects';
import { TaskDetailContent } from '@/Components/ai/projects/TaskParts';
import { FilterSelect } from '@/Components/ai/triage/InboxParts';
import { KanbanCard } from '@/Components/ai/KanbanCard';
import { columnLabel, KANBAN_COLUMNS, KanbanColumn } from '@/Components/ai/KanbanColumn';
import { Elapsed } from '@/Components/ai/TimerButton';
import { Button } from '@/Components/ui/Button';
import { Dialog } from '@/Components/ui/Dialog';
import { Drawer } from '@/Components/ui/Drawer';
import { Icon } from '@/Components/ui/Icon';
import { IconButton } from '@/Components/ui/IconButton';
import { Select } from '@/Components/ui/Select';
import { TextField } from '@/Components/ui/TextField';
import { cx } from '@/Components/ui/utils';
import { ProjectLayout } from '@/Layouts/ProjectLayout';
import { route } from '@/lib/route';
import type { KanbanColumnKey, Task } from '@/types/ai';
import type { BoardFilters, ProjectsBoardProps, RunningTimer } from '@/types/pages/ai-projects';
import type { PageProps } from '@/types/shared';

const ALL = 'all';

function toQuery(filters: BoardFilters): Record<string, string | number> {
    const query: Record<string, string | number> = {};
    if (filters.assignee_id !== null) query.assignee_id = filters.assignee_id;
    if (filters.label !== null) query.label = filters.label;
    if (filters.blocked) query.blocked = 1;
    return query;
}

/** Sticky phone bar while the viewer's timer runs (B-KanbanPhone). */
function RunningTimerBar({ timer, now, onStop }: { timer: RunningTimer; now: string; onStop: () => void }) {
    return (
        <section
            aria-label="Cronómetro corriendo"
            className="sticky top-[60px] z-20 -mx-4 -mt-5 mb-4 flex items-center gap-3 bg-fg px-4 py-2.5 text-on-fg md:hidden"
        >
            <span aria-hidden="true" className="size-2.5 shrink-0 animate-pulse rounded-full bg-status-active-dot motion-reduce:animate-none" />
            <span className="flex min-w-0 flex-1 flex-col text-[13px]">
                <span className="truncate opacity-80">Cronómetro corriendo · {timer.task_key}</span>
                <Elapsed startedAt={timer.started_at} clockStartAt={now} className="text-base" />
            </span>
            <button type="button" onClick={onStop} className="inline-flex h-11 items-center gap-2 rounded-control bg-on-fg px-4 text-sm font-bold text-fg">
                <span aria-hidden="true" className="size-2 rounded-[2px] bg-current" />
                Parar
                <span className="sr-only"> cronómetro de {timer.task_key}</span>
            </button>
        </section>
    );
}

function AddTaskDialog({ projectKey, column, onClose }: { projectKey: string; column: KanbanColumnKey | null; onClose: () => void }) {
    const form = useForm<{ title: string; column: KanbanColumnKey }>({ title: '', column: column ?? 'backlog' });

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        if (!form.data.title.trim()) {
            form.setError('title', 'Escribe qué hay que hacer.');
            return;
        }
        form.post(route('ai.tasks.store', { project: projectKey }), { preserveScroll: true, onSuccess: onClose });
    };

    return (
        <Dialog
            open={column !== null}
            onClose={onClose}
            title="Agregar tarea"
            size="sm"
            onSubmit={submit}
            actions={
                <>
                    <Button variant="secondary" onClick={onClose}>
                        Cancelar
                    </Button>
                    <Button type="submit" loading={form.processing}>
                        Agregar tarea
                    </Button>
                </>
            }
        >
            <TextField
                label="Qué hay que hacer"
                value={form.data.title}
                error={form.errors.title}
                autoFocus
                onChange={(event) => {
                    form.setData('title', event.target.value);
                    form.clearErrors('title');
                }}
            />
            <Select
                label="Columna"
                value={form.data.column}
                options={KANBAN_COLUMNS.map((item) => ({ value: item.key, label: item.label }))}
                onChange={(event) => form.setData('column', event.target.value as KanbanColumnKey)}
            />
        </Dialog>
    );
}

/** B-Kanban + B-TaskDetail + B-KanbanPhone: drag cards between columns (or "Mover a…"), filters, timers and the task drawer. */
export default function ProjectsBoard(props: PageProps<ProjectsBoardProps>) {
    const { project, filters, people, labels, selected_task, running_timer, column_limits, total_tasks, blocked_total, auth } = props;
    const [tasks, setTasks] = useState<Task[]>(props.tasks);
    const [dragging, setDragging] = useState<string | null>(null);
    const [over, setOver] = useState<KanbanColumnKey | null>(null);
    const [phoneColumn, setPhoneColumn] = useState<KanbanColumnKey>('in_progress');
    const [adding, setAdding] = useState<KanbanColumnKey | null>(null);
    const [closedKey, setClosedKey] = useState<string | null>(null);
    const [announcement, setAnnouncement] = useState('');
    const tabRefs = useRef<Partial<Record<KanbanColumnKey, HTMLButtonElement | null>>>({});
    const base = useId();
    const boardUrl = route('ai.projects.board', { project: project.key });

    useEffect(() => setTasks(props.tasks), [props.tasks]);
    useEffect(() => setClosedKey(null), [selected_task]);

    const inColumn = (column: KanbanColumnKey) => tasks.filter((task) => task.column === column).sort((a, b) => a.position - b.position);
    const drawerTask = selected_task && closedKey !== selected_task.key ? selected_task : null;

    const applyFilters = (change: Partial<BoardFilters>) =>
        router.get(boardUrl, toQuery({ ...filters, ...change }), { preserveState: true, preserveScroll: true });

    const move = (task: Task, column: KanbanColumnKey, position?: number) => {
        if (task.column === column && position === undefined) return;
        const next = moveTask(tasks, task.key, column, position);
        const placed = next.find((item) => item.key === task.key)!;
        setTasks(next);
        setAnnouncement(`${task.key} movida a ${columnLabel(column)}.`);
        router.put(
            route('ai.tasks.move', { project: project.key, task: task.key }),
            { column, position: placed.position },
            { preserveScroll: true, preserveState: true, onError: () => setTasks(props.tasks) },
        );
    };

    const open = (task: Task) => {
        setClosedKey(null);
        router.get(boardUrl, { ...toQuery(filters), tarea: task.key }, { only: ['selected_task'], preserveState: true, preserveScroll: true });
    };

    const close = () => {
        if (selected_task) setClosedKey(selected_task.key);
        router.get(boardUrl, toQuery(filters), { only: ['selected_task'], preserveState: true, preserveScroll: true, replace: true });
    };

    const startTimer = (task: Pick<Task, 'key'>) => router.post(route('ai.timer.start', { project: project.key, task: task.key }), {}, { preserveScroll: true });
    const stopTimer = () => router.post(route('ai.timer.stop'), {}, { preserveScroll: true });

    const onDragStart = (event: DragEvent<HTMLElement>, task: Task) => {
        event.dataTransfer.effectAllowed = 'move';
        event.dataTransfer.setData('text/plain', task.key);
        setDragging(task.key);
    };

    const columnDrag = (column: KanbanColumnKey) => ({
        onDragOver: (event: DragEvent<HTMLElement>) => {
            if (!dragging) return;
            event.preventDefault();
            event.dataTransfer.dropEffect = 'move';
            if (over !== column) setOver(column);
        },
        onDragLeave: (event: DragEvent<HTMLElement>) => {
            if (!event.currentTarget.contains(event.relatedTarget as Node | null)) setOver((current) => (current === column ? null : current));
        },
        onDrop: (event: DragEvent<HTMLElement>) => {
            event.preventDefault();
            const task = tasks.find((item) => item.key === (dragging ?? event.dataTransfer.getData('text/plain')));
            setDragging(null);
            setOver(null);
            if (task) move(task, column);
        },
    });

    const renderCards = (column: KanbanColumnKey) =>
        inColumn(column).map((task) => (
            <KanbanCard
                key={task.key}
                task={task}
                onOpen={open}
                onMove={(item, target) => move(item, target)}
                onTimerStart={startTimer}
                onTimerStop={stopTimer}
                clockStartAt={props.now}
                onDragStart={onDragStart}
                onDragEnd={() => {
                    setDragging(null);
                    setOver(null);
                }}
                dragging={dragging === task.key}
            />
        ));

    const wipFor = (column: KanbanColumnKey) => {
        const limit = column_limits[column];
        return limit !== undefined ? { current: inColumn(column).length, limit } : undefined;
    };

    const onTabKey = (event: KeyboardEvent<HTMLDivElement>) => {
        if (event.key !== 'ArrowRight' && event.key !== 'ArrowLeft') return;
        event.preventDefault();
        const index = KANBAN_COLUMNS.findIndex((column) => column.key === phoneColumn);
        const next = KANBAN_COLUMNS[(index + (event.key === 'ArrowRight' ? 1 : -1) + KANBAN_COLUMNS.length) % KANBAN_COLUMNS.length]!.key;
        setPhoneColumn(next);
        tabRefs.current[next]?.focus();
    };

    const shown = tasks.length;
    const filtered = filters.assignee_id !== null || filters.label !== null || filters.blocked;

    return (
        <ProjectLayout shared={props} title={`Tablero ${project.key} · Gestor IA`} project={project} tab="board">
            {running_timer ? <RunningTimerBar timer={running_timer} now={props.now} onStop={stopTimer} /> : null}

            <div className="flex flex-col gap-4">
                <div className="flex flex-col gap-2 md:flex-row md:flex-wrap md:items-center">
                    <div className="-mx-4 flex gap-2 overflow-x-auto px-4 pb-1 md:mx-0 md:flex-wrap md:px-0 md:pb-0">
                        <FilterSelect
                            label="Responsable"
                            value={filters.assignee_id !== null ? String(filters.assignee_id) : ALL}
                            options={[{ value: ALL, label: 'todos' }, ...people.map((person) => ({ value: String(person.id), label: person.name }))]}
                            onChange={(value) => applyFilters({ assignee_id: value === ALL ? null : Number(value) })}
                        />
                        <FilterSelect
                            label="Etiqueta"
                            value={filters.label ?? ALL}
                            options={[{ value: ALL, label: 'todas' }, ...labels.map((label) => ({ value: label, label }))]}
                            onChange={(value) => applyFilters({ label: value === ALL ? null : value })}
                        />
                        <button
                            type="button"
                            aria-pressed={filters.blocked}
                            onClick={() => applyFilters({ blocked: !filters.blocked })}
                            className={cx(
                                'inline-flex h-11 shrink-0 items-center gap-2 rounded-chip border px-3.5 text-[13px] font-bold whitespace-nowrap',
                                filters.blocked ? 'border-fg bg-fg text-on-fg' : 'border-line-strong bg-surface text-fg hover:bg-subtle',
                            )}
                        >
                            <Icon name="blocked" size={14} />
                            Solo bloqueadas
                            <span className={cx('tabular rounded-full px-1.5 text-xs', filters.blocked ? 'bg-surface text-fg' : 'bg-status-failure-bg text-status-failure')}>
                                {blocked_total}
                            </span>
                        </button>
                    </div>
                    <p className="text-[13px] text-fg-muted md:ml-auto">
                        {filtered ? `${shown} de ${total_tasks} tareas` : `${total_tasks} tareas`}
                        <span className="hidden lg:inline"> · arrastra una tarjeta para cambiarla de columna, o usa “Mover a…”</span>
                    </p>
                </div>

                <p aria-live="polite" className="sr-only">
                    {announcement}
                </p>

                {/* Phone: one column at a time. */}
                <div className="flex flex-col gap-3 md:hidden">
                    <div role="tablist" aria-label="Columnas del tablero" onKeyDown={onTabKey} className="-mx-4 flex gap-1.5 overflow-x-auto px-4 pb-1">
                        {KANBAN_COLUMNS.map((column) => {
                            const selected = column.key === phoneColumn;
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
                                    aria-controls={`${base}-panel`}
                                    tabIndex={selected ? 0 : -1}
                                    onClick={() => setPhoneColumn(column.key)}
                                    className={cx(
                                        'inline-flex h-11 shrink-0 items-center gap-1.5 rounded-chip border px-3.5 text-[13px] whitespace-nowrap',
                                        selected ? 'border-fg bg-fg font-bold text-on-fg' : 'border-line-strong bg-surface font-semibold text-fg',
                                    )}
                                >
                                    {column.label}
                                    <span className="tabular text-xs opacity-80">{inColumn(column.key).length}</span>
                                </button>
                            );
                        })}
                    </div>
                    <p className="text-xs text-fg-muted">Desliza para ver más columnas</p>
                    <div role="tabpanel" id={`${base}-panel`} aria-labelledby={`${base}-${phoneColumn}-tab`}>
                        <KanbanColumn column={phoneColumn} count={inColumn(phoneColumn).length} wip={wipFor(phoneColumn)} onAddTask={() => setAdding(phoneColumn)}>
                            {renderCards(phoneColumn)}
                        </KanbanColumn>
                    </div>
                </div>

                {/* Tablet and desktop: the five columns. */}
                <div className="-mx-5 hidden overflow-x-auto px-5 pb-2 md:block lg:-mx-8 lg:px-8">
                    <div className="grid min-w-[1080px] grid-cols-5 items-start gap-3">
                        {KANBAN_COLUMNS.map((column) => (
                            <KanbanColumn
                                key={column.key}
                                column={column.key}
                                count={inColumn(column.key).length}
                                wip={wipFor(column.key)}
                                dropHint={over === column.key && dragging ? (dropHint(tasks, column.key, dragging, column_limits[column.key]) ?? 'Mover aquí') : null}
                                onAddTask={() => setAdding(column.key)}
                                className={cx(over === column.key && 'border-status-scheduled')}
                                {...columnDrag(column.key)}
                            >
                                {renderCards(column.key)}
                            </KanbanColumn>
                        ))}
                    </div>
                </div>
            </div>

            <AddTaskDialog key={adding ?? 'closed'} projectKey={project.key} column={adding} onClose={() => setAdding(null)} />

            <Drawer
                open={drawerTask !== null}
                onClose={close}
                width={780}
                eyebrow={drawerTask ? `${drawerTask.key} · ${project.key} ${project.name}${project.request_keys[0] ? ` · ${project.request_keys[0]}` : ''}` : null}
                title={drawerTask?.title ?? ''}
                headerActions={
                    drawerTask ? (
                        <IconButton icon="external" label="Abrir la tarea en su página" href={route('ai.tasks.show', { project: project.key, task: drawerTask.key })} />
                    ) : null
                }
            >
                {drawerTask ? (
                    <TaskDetailContent
                        key={drawerTask.key}
                        task={drawerTask}
                        projectKey={project.key}
                        people={people}
                        now={props.now}
                        viewerId={auth.user.id}
                        onTimerStart={() => startTimer(drawerTask)}
                        onTimerStop={stopTimer}
                    />
                ) : null}
            </Drawer>
        </ProjectLayout>
    );
}
