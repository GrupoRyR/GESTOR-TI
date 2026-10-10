import { useRef, useState, type DragEvent, type ReactNode } from 'react';
import { Icon } from './Icon';
import { cx } from './utils';

export interface SortableListProps<T> {
    items: T[];
    getKey: (item: T) => string;
    /** Short name used in buttons and announcements: "GIA-102". */
    getLabel: (item: T) => string;
    /** Called with the new order after a drop or a Subir/Bajar press. */
    onReorder: (items: T[], moved: { key: string; from: number; to: number }) => void;
    /** Row content after the rank and handle. */
    renderItem: (item: T, rank: number) => ReactNode;
    /** Accessible name: "Backlog priorizado". */
    label: string;
    className?: string;
}

export function moveItem<T>(items: T[], from: number, to: number): T[] {
    const next = [...items];
    const [item] = next.splice(from, 1);
    if (item === undefined) return items;
    next.splice(Math.max(0, Math.min(to, next.length)), 0, item);
    return next;
}

/**
 * Ranked rows that reorder by dragging the handle (native HTML5 drag) or,
 * with keyboard and touch, with the Subir / Bajar buttons. Every move is
 * announced in a polite live region ("GIA-110 quedó en el puesto 3").
 */
export function SortableList<T>({ items, getKey, getLabel, onReorder, renderItem, label, className }: SortableListProps<T>) {
    const [dragging, setDragging] = useState<number | null>(null);
    const [over, setOver] = useState<number | null>(null);
    const [message, setMessage] = useState('');
    const buttons = useRef(new Map<string, HTMLButtonElement | null>());

    const commit = (from: number, to: number, focus?: 'up' | 'down') => {
        if (from === to || to < 0 || to >= items.length) return;
        const item = items[from];
        if (item === undefined) return;
        const key = getKey(item);
        onReorder(moveItem(items, from, to), { key, from, to });
        setMessage(`${getLabel(item)} quedó en el puesto ${to + 1} de ${items.length}`);
        if (focus) requestAnimationFrame(() => buttons.current.get(`${key}:${focus}`)?.focus());
    };

    const onDragStart = (index: number) => (event: DragEvent<HTMLElement>) => {
        setDragging(index);
        event.dataTransfer.effectAllowed = 'move';
        event.dataTransfer.setData('text/plain', String(index));
    };

    const onDragOver = (index: number) => (event: DragEvent<HTMLElement>) => {
        if (dragging === null) return;
        event.preventDefault();
        event.dataTransfer.dropEffect = 'move';
        setOver(index);
    };

    const onDrop = (index: number) => (event: DragEvent<HTMLElement>) => {
        event.preventDefault();
        if (dragging !== null) commit(dragging, index);
        setDragging(null);
        setOver(null);
    };

    const smallButton =
        'flex size-11 items-center justify-center rounded-control text-fg-soft transition-colors duration-150 hover:bg-subtle hover:text-fg disabled:pointer-events-none disabled:opacity-30';

    return (
        <>
            <ol aria-label={label} className={cx('flex flex-col', className)}>
                {items.map((item, index) => {
                    const key = getKey(item);
                    const name = getLabel(item);
                    const isDragging = dragging === index;
                    const isTarget = over === index && dragging !== null && dragging !== index;
                    return (
                        <li
                            key={key}
                            onDragOver={onDragOver(index)}
                            onDrop={onDrop(index)}
                            onDragEnd={() => {
                                setDragging(null);
                                setOver(null);
                            }}
                            className={cx(
                                'relative flex min-h-14 items-center gap-2 border-t border-line bg-surface pr-1 first:border-t-0',
                                isDragging && 'opacity-50',
                                isTarget &&
                                    (dragging !== null && dragging < index
                                        ? 'shadow-[inset_0_-3px_0_var(--color-status-scheduled)]'
                                        : 'shadow-[inset_0_3px_0_var(--color-status-scheduled)]'),
                            )}
                        >
                            <span
                                draggable
                                onDragStart={onDragStart(index)}
                                title={`Arrastra para mover ${name}`}
                                aria-hidden="true"
                                className="hidden h-11 w-8 shrink-0 cursor-grab items-center justify-center text-fg-muted active:cursor-grabbing md:flex"
                            >
                                <Icon name="grip" size={18} />
                            </span>
                            <span className="tabular w-7 shrink-0 text-center text-[15px] font-bold">
                                <span className="sr-only">Puesto </span>
                                {index + 1}
                            </span>
                            <div className="min-w-0 flex-1 py-2">{renderItem(item, index + 1)}</div>
                            <span className="flex shrink-0">
                                <button
                                    type="button"
                                    ref={(element) => {
                                        buttons.current.set(`${key}:up`, element);
                                    }}
                                    disabled={index === 0}
                                    onClick={() => commit(index, index - 1, 'up')}
                                    aria-label={`Subir ${name}`}
                                    title="Subir"
                                    className={smallButton}
                                >
                                    <Icon name="arrow-up" size={18} />
                                </button>
                                <button
                                    type="button"
                                    ref={(element) => {
                                        buttons.current.set(`${key}:down`, element);
                                    }}
                                    disabled={index === items.length - 1}
                                    onClick={() => commit(index, index + 1, 'down')}
                                    aria-label={`Bajar ${name}`}
                                    title="Bajar"
                                    className={smallButton}
                                >
                                    <Icon name="arrow-down" size={18} />
                                </button>
                            </span>
                        </li>
                    );
                })}
            </ol>
            <p aria-live="polite" className="sr-only">
                {message}
            </p>
        </>
    );
}
