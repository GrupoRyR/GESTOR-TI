import { useId, type DragEvent, type ReactNode } from 'react';
import { Icon } from '@/Components/ui/Icon';
import { cx } from '@/Components/ui/utils';
import type { KanbanColumnKey } from '@/types/ai';

export const KANBAN_COLUMNS: { key: KanbanColumnKey; label: string }[] = [
    { key: 'backlog', label: 'Backlog' },
    { key: 'todo', label: 'Por hacer' },
    { key: 'in_progress', label: 'En progreso' },
    { key: 'review', label: 'En revisión' },
    { key: 'done', label: 'Hecho' },
];

export const columnLabel = (key: KanbanColumnKey) => KANBAN_COLUMNS.find((column) => column.key === key)?.label ?? key;

export interface WipLimit {
    current: number;
    limit: number;
}

/** "2 de 3" (blue) · "3 de 3 · al límite" (amber) · "4 de 3 · pasó el límite" (red). */
export function WipChip({ current, limit }: WipLimit) {
    const state = current > limit ? 'over' : current === limit ? 'full' : 'ok';
    return (
        <span
            title="Límite de tareas en progreso"
            className={cx(
                'tabular inline-flex h-6 shrink-0 items-center gap-1 rounded-chip px-2 text-xs font-bold whitespace-nowrap',
                state === 'ok' && 'bg-status-scheduled-bg text-status-scheduled',
                state === 'full' && 'bg-status-maintenance-bg text-status-maintenance',
                state === 'over' && 'bg-status-failure-bg text-status-failure',
            )}
        >
            {state !== 'ok' ? <Icon name="alert-triangle" size={12} /> : null}
            <span className="sr-only">Límite: </span>
            {current} de {limit}
            {state === 'full' ? ' · al límite' : state === 'over' ? ' · pasó el límite' : ''}
        </span>
    );
}

export interface KanbanColumnProps {
    column: KanbanColumnKey;
    count: number;
    /** Only columns with a WIP limit (En progreso). */
    wip?: WipLimit;
    /** Drop preview while a card hovers this column: "Quedarían 3 de 3". */
    dropHint?: string | null;
    onDragOver?: (event: DragEvent<HTMLElement>) => void;
    onDragLeave?: (event: DragEvent<HTMLElement>) => void;
    onDrop?: (event: DragEvent<HTMLElement>) => void;
    onAddTask?: () => void;
    className?: string;
    children?: ReactNode;
}

/** One Kanban column: title, count, optional WIP chip, cards and "Agregar tarea". */
export function KanbanColumn({
    column,
    count,
    wip,
    dropHint,
    onDragOver,
    onDragLeave,
    onDrop,
    onAddTask,
    className,
    children,
}: KanbanColumnProps) {
    const titleId = useId();
    return (
        <section
            aria-labelledby={titleId}
            onDragOver={onDragOver}
            onDragLeave={onDragLeave}
            onDrop={onDrop}
            className={cx('relative flex min-w-0 flex-col gap-2 rounded-card border border-line bg-subtle p-2.5', className)}
        >
            <div className="flex min-h-7 items-center gap-2 px-1 pt-1 pb-0.5">
                <h2 id={titleId} className="font-display text-base font-bold whitespace-nowrap">
                    {columnLabel(column)}
                </h2>
                <span className="tabular text-[13px] font-bold text-fg-muted">
                    <span className="sr-only">, </span>
                    {count}
                    <span className="sr-only"> {count === 1 ? 'tarea' : 'tareas'}</span>
                </span>
                {wip ? (
                    <span className="ml-auto">
                        <WipChip {...wip} />
                    </span>
                ) : null}
            </div>
            <ul className="flex flex-col gap-2">{children}</ul>
            {dropHint ? (
                <div
                    aria-hidden="true"
                    className="flex h-[84px] shrink-0 flex-col items-center justify-center gap-0.5 rounded-[12px] border-2 border-dashed border-status-scheduled bg-status-scheduled-bg text-[13px] font-bold text-status-scheduled"
                >
                    <span>Suelta aquí</span>
                    <span className="text-xs font-semibold">{dropHint}</span>
                </div>
            ) : null}
            {onAddTask ? (
                <button
                    type="button"
                    onClick={onAddTask}
                    className="flex h-11 shrink-0 items-center justify-center gap-1.5 rounded-[12px] border border-dashed border-line-strong text-[13px] font-bold text-fg-soft transition-colors duration-150 hover:bg-surface hover:text-fg"
                >
                    <Icon name="plus" size={14} strokeWidth={2.4} />
                    Agregar tarea
                </button>
            ) : null}
        </section>
    );
}
