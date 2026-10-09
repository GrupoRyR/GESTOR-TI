import { Skeleton } from '@/Components/ui/Skeleton';
import { cx } from '@/Components/ui/utils';
import { KANBAN_COLUMNS } from './KanbanColumn';

const WIDTHS = [78, 62, 85, 70, 58, 90, 66];
/** Placeholder cards per column, like the real board (B-States). */
const CARDS = [2, 2, 3, 2, 3];

export interface SkeletonBoardProps {
    /** "P-07 · Visualizador de cartera". */
    title: string;
    className?: string;
}

/**
 * Loading Kanban (B-States): the real column names appear at once and the
 * cards are shapes. Use it while a board loads client-side (e.g. a deferred
 * prop); Inertia page visits render the finished board directly.
 */
export function SkeletonBoard({ title, className }: SkeletonBoardProps) {
    let n = 0;
    const next = () => WIDTHS[n++ % WIDTHS.length] ?? 70;
    return (
        <section aria-busy="true" aria-label={`Kanban de ${title} cargando`} className={cx('flex min-w-0 flex-col gap-3', className)}>
            <div className="flex flex-wrap items-baseline justify-between gap-2">
                <h2 className="font-display text-lg font-bold">{title}</h2>
                <span role="status" className="text-[13px] text-fg-muted">
                    Cargando tareas…
                </span>
            </div>
            <div className="overflow-x-auto">
                <div className="grid min-w-[760px] grid-cols-5 gap-3">
                    {KANBAN_COLUMNS.map((column, index) => (
                        <div key={column.key} className="flex flex-col gap-2 rounded-card bg-subtle/60 p-2.5">
                            <span className="px-1 text-[13px] font-bold text-fg-soft">{column.label}</span>
                            {Array.from({ length: CARDS[index] ?? 2 }, (_, card) => {
                                const width = next();
                                return (
                                    <span key={card} aria-hidden="true" className="flex flex-col gap-2 rounded-[12px] border border-line bg-surface p-3">
                                        <Skeleton className="h-2.5 bg-line" style={{ width: `${width}%` }} />
                                        <Skeleton className="h-2" style={{ width: `${width - 22}%` }} />
                                        <Skeleton className="h-5 w-[40%] rounded-chip" />
                                    </span>
                                );
                            })}
                        </div>
                    ))}
                </div>
            </div>
        </section>
    );
}
