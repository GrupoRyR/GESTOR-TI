import { useEffect, useId, useRef, useState, type DragEvent, type KeyboardEvent } from 'react';
import { CiStatusLabel } from '@/Components/github/CiStatusLabel';
import { GitHubRefChip } from '@/Components/github/GitHubKeyChip';
import { Avatar } from '@/Components/ui/Avatar';
import { Icon } from '@/Components/ui/Icon';
import { cx } from '@/Components/ui/utils';
import { reviewReason } from '@/lib/taskGitHub';
import type { KanbanColumnKey, Task } from '@/types/ai';
import { KANBAN_COLUMNS } from './KanbanColumn';
import { TimerButton } from './TimerButton';

const SHORT_DATE = new Intl.DateTimeFormat('es-CO', { day: 'numeric', month: 'short', timeZone: 'UTC' });

/** "2026-10-18" → "18 oct". */
export function shortDate(date: string): string {
    return SHORT_DATE.format(new Date(`${date.slice(0, 10)}T00:00:00Z`)).replace('.', '');
}

export interface KanbanCardProps {
    task: Task;
    /** Opens the task drawer. */
    onOpen: (task: Task) => void;
    /** Keyboard / touch alternative to dragging. */
    onMove: (task: Task, column: KanbanColumnKey) => void;
    onTimerStart?: (task: Task) => void;
    onTimerStop?: (task: Task) => void;
    /** Mock clock origin for the running timer. */
    clockStartAt?: string;
    /** Native drag handlers from the board; the card is draggable when given. */
    onDragStart?: (event: DragEvent<HTMLElement>, task: Task) => void;
    onDragEnd?: (event: DragEvent<HTMLElement>) => void;
    /** Lifted look of the card being dragged. */
    dragging?: boolean;
    /** Request key of the project ("GIA-139"): explains why a PR moved the card to review. */
    requestKey?: string | null;
    /** The issue was just created from the Gestor ("Issue #57 nuevo"). */
    newIssue?: boolean;
}

function MoveMenu({ task, onMove }: { task: Task; onMove: (column: KanbanColumnKey) => void }) {
    const [open, setOpen] = useState(false);
    const trigger = useRef<HTMLButtonElement>(null);
    const menu = useRef<HTMLUListElement>(null);
    const menuId = useId();
    const targets = KANBAN_COLUMNS.filter((column) => column.key !== task.column);

    useEffect(() => {
        if (!open) return;
        menu.current?.querySelector<HTMLButtonElement>('button')?.focus();
        const close = (event: MouseEvent) => {
            if (!menu.current?.contains(event.target as Node) && event.target !== trigger.current) setOpen(false);
        };
        document.addEventListener('mousedown', close);
        return () => document.removeEventListener('mousedown', close);
    }, [open]);

    const onKeyDown = (event: KeyboardEvent<HTMLUListElement>) => {
        const items = [...(menu.current?.querySelectorAll<HTMLButtonElement>('button') ?? [])];
        const index = items.indexOf(document.activeElement as HTMLButtonElement);
        if (event.key === 'Escape') {
            event.preventDefault();
            setOpen(false);
            trigger.current?.focus();
        } else if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            event.preventDefault();
            const next = (index + (event.key === 'ArrowDown' ? 1 : -1) + items.length) % items.length;
            items[next]?.focus();
        } else if (event.key === 'Tab') {
            setOpen(false);
        }
    };

    return (
        <div className="relative">
            <button
                ref={trigger}
                type="button"
                aria-haspopup="menu"
                aria-expanded={open}
                aria-controls={open ? menuId : undefined}
                aria-label={`Mover ${task.key} a…`}
                title="Mover a…"
                onClick={() => setOpen((value) => !value)}
                className="flex size-11 items-center justify-center rounded-control text-fg-muted transition-colors duration-150 hover:bg-subtle hover:text-fg"
            >
                <Icon name="move" size={16} />
            </button>
            {open ? (
                <ul
                    ref={menu}
                    id={menuId}
                    role="menu"
                    aria-label={`Mover ${task.key} a`}
                    onKeyDown={onKeyDown}
                    className="absolute top-full right-0 z-20 mt-1 flex w-48 flex-col rounded-control border border-line bg-surface p-1 shadow-card"
                >
                    {targets.map((column) => (
                        <li key={column.key} role="none">
                            <button
                                type="button"
                                role="menuitem"
                                onClick={() => {
                                    setOpen(false);
                                    onMove(column.key);
                                    trigger.current?.focus();
                                }}
                                className="flex h-11 w-full items-center rounded-chip px-3 text-left text-sm font-semibold hover:bg-subtle focus-visible:bg-subtle"
                            >
                                {column.label}
                            </button>
                        </li>
                    ))}
                </ul>
            ) : null}
        </div>
    );
}

/**
 * Task card: key and labels, title (opens the drawer), blocked reason,
 * assignee, due date, subtasks, GitHub chips and the timer. Drag it between
 * columns, or use "Mover a…" with keyboard or touch.
 */
export function KanbanCard({
    task,
    onOpen,
    onMove,
    onTimerStart,
    onTimerStop,
    clockStartAt,
    onDragStart,
    onDragEnd,
    dragging = false,
    requestKey = null,
    newIssue = false,
}: KanbanCardProps) {
    const running = task.timer?.running === true;
    const blocked = task.blocked_by !== null;
    const done = task.column === 'done';
    const assignee = task.assignees[0];
    const pr = task.github?.pr;
    const issue = task.github?.issue;
    const reason = reviewReason(task.column, pr ?? null, requestKey);

    return (
        <li
            draggable={onDragStart !== undefined}
            onDragStart={onDragStart ? (event) => onDragStart(event, task) : undefined}
            onDragEnd={onDragEnd}
            className={cx(
                'relative flex flex-col gap-1.5 rounded-[12px] border bg-surface px-3 py-2.5',
                running ? 'border-2 border-fg px-[11px] py-[9px]' : blocked ? 'border-status-failure-line' : 'border-line',
                onDragStart && 'cursor-grab active:cursor-grabbing',
                dragging && '-rotate-[2.5deg] border-fg opacity-90 shadow-[0_18px_36px_rgb(23_25_28/0.22),0_2px_6px_rgb(23_25_28/0.12)]',
            )}
        >
            <div className="flex items-start gap-1.5">
                <div className="flex min-w-0 flex-1 flex-wrap items-center gap-1.5 pt-1">
                    <b className="text-xs text-fg-soft">{task.key}</b>
                    {task.labels.map((label) => (
                        <span key={label} className="inline-flex h-5 items-center rounded-[8px] bg-subtle px-[7px] text-xs font-semibold whitespace-nowrap text-fg-soft">
                            {label}
                        </span>
                    ))}
                    {done ? <Icon name="check-circle" size={14} label="Hecha" className="text-status-active" /> : null}
                </div>
                <MoveMenu task={task} onMove={(column) => onMove(task, column)} />
            </div>

            <button
                type="button"
                onClick={() => onOpen(task)}
                className="-mt-1 text-left text-sm leading-[1.3] font-semibold text-fg hover:underline hover:underline-offset-2"
            >
                {task.title}
            </button>

            {task.blocked_by ? (
                <p className="flex items-start gap-1.5 text-xs leading-[1.35] font-semibold text-status-failure">
                    <span className="mt-px">
                        <Icon name="blocked" size={14} />
                    </span>
                    Bloqueada: espera {task.blocked_by.title.charAt(0).toLowerCase() + task.blocked_by.title.slice(1)} ({task.blocked_by.area_name})
                </p>
            ) : null}

            <div className="flex min-h-7 flex-wrap items-center gap-x-2 gap-y-1">
                {assignee ? (
                    <span title={assignee.name} className="inline-flex">
                        <Avatar name={assignee.name} size="xs" tone={running ? 'strong' : 'subtle'} decorative={false} />
                    </span>
                ) : null}
                {task.due_at && !done ? (
                    <span className="inline-flex items-center gap-1 text-xs whitespace-nowrap text-fg-muted">
                        <Icon name="calendar" size={13} />
                        <span className="sr-only">Vence </span>
                        {shortDate(task.due_at)}
                    </span>
                ) : null}
                {task.subtasks_total > 0 ? (
                    <span className="tabular inline-flex items-center gap-1 text-xs whitespace-nowrap text-fg-muted">
                        <Icon name="subtasks" size={13} />
                        {task.subtasks_done} de {task.subtasks_total}
                        <span className="sr-only"> subtareas</span>
                    </span>
                ) : null}
                {pr ? (
                    <GitHubRefChip kind="pr" number={pr.number} note={pr.state === 'merged' ? 'fusionado' : pr.state === 'closed' ? 'cerrado' : undefined} />
                ) : issue && !done ? (
                    <GitHubRefChip kind="issue" number={issue.number} note={newIssue ? 'nuevo' : undefined} />
                ) : null}
                {pr && pr.state !== 'merged' && pr.state !== 'closed' && task.github?.ci ? <CiStatusLabel status={task.github.ci} /> : null}
                {!done && !running && onTimerStart && onTimerStop ? (
                    <TimerButton
                        taskKey={task.key}
                        running={false}
                        size="icon"
                        onStart={() => onTimerStart(task)}
                        onStop={() => onTimerStop(task)}
                        className="ml-auto"
                    />
                ) : null}
            </div>

            {reason ? (
                <p className="flex items-start gap-1.5 text-xs leading-[1.35] font-semibold text-status-maintenance">
                    <span className="mt-px">
                        <Icon name="pull-request" size={13} />
                    </span>
                    {reason}
                </p>
            ) : null}

            {running && onTimerStart && onTimerStop ? (
                <TimerButton
                    taskKey={task.key}
                    running
                    startedAt={task.timer?.started_at}
                    clockStartAt={clockStartAt}
                    onStart={() => onTimerStart(task)}
                    onStop={() => onTimerStop(task)}
                />
            ) : null}
        </li>
    );
}
