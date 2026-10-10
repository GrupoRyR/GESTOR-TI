import type { CSSProperties } from 'react';
import { cx } from './utils';

export interface SkeletonProps {
    className?: string;
    style?: CSSProperties;
}

/** Placeholder block; size it with classes. */
export function Skeleton({ className, style }: SkeletonProps) {
    return <span aria-hidden="true" style={style} className={cx('block rounded-[5px] bg-subtle motion-safe:animate-pulse', className)} />;
}

const LINE_WIDTHS = [
    ['60%', '40%'],
    ['48%', '32%'],
    ['66%', '38%'],
    ['52%', '28%'],
];

export interface SkeletonRowsProps {
    rows?: number;
    /** Announced while loading. */
    label?: string;
    className?: string;
}

/** Loading table with the real shape: avatar, two lines, a column and a chip. */
export function SkeletonRows({ rows = 4, label = 'Cargando…', className }: SkeletonRowsProps) {
    return (
        <div aria-busy="true" className={cx('flex flex-col', className)}>
            <span role="status" className="sr-only">
                {label}
            </span>
            {Array.from({ length: rows }, (_, index) => {
                const [first, second] = LINE_WIDTHS[index % LINE_WIDTHS.length] ?? ['60%', '40%'];
                return (
                    <div
                        key={index}
                        className={cx(
                            'grid h-[60px] grid-cols-[40px_minmax(0,1fr)_90px] items-center gap-4 px-5 md:grid-cols-[40px_minmax(0,1fr)_120px_90px]',
                            index < rows - 1 && 'border-b border-subtle',
                        )}
                    >
                        <Skeleton className="size-9 rounded-full" />
                        <span className="flex flex-col gap-1.5">
                            <Skeleton className="h-2.5 bg-line" style={{ width: first }} />
                            <Skeleton className="h-2 rounded-[4px]" style={{ width: second }} />
                        </span>
                        <Skeleton className="hidden h-2.5 md:block" />
                        <Skeleton className="h-6 rounded-chip" />
                    </div>
                );
            })}
        </div>
    );
}
