import type { ReactNode } from 'react';
import { requestStatus, type RequestStatusKey } from '@/lib/status';
import { StatusChip } from './StatusChip';
import { cx, FAMILY_TONE } from './utils';

export interface BarListItem {
    key: string;
    /** Category name; ignored when `status` is set (the status chip is the label). */
    label?: ReactNode;
    value: number;
    /** Bars are request statuses: chip as label and the family color as fill. */
    status?: RequestStatusKey;
    /** Secondary text after the label: "86 h al mes". */
    note?: ReactNode;
}

export interface BarListProps {
    /** Accessible name: "Horas ahorradas por área en septiembre". */
    label: string;
    items: BarListItem[];
    formatValue: (value: number) => string;
    /** Scale maximum; defaults to the largest value. */
    max?: number;
    /** Sort descending (default true). Keep false for an already meaningful order. */
    sort?: boolean;
    /** Width of the label column from 640 px (default 160). */
    labelWidth?: number;
    className?: string;
}

/**
 * Horizontal bars with the value at the tip. One color for plain categories;
 * status bars use their family color because each bar *is* a status.
 */
export function BarList({ label, items, formatValue, max, sort = true, labelWidth = 160, className }: BarListProps) {
    const rows = sort ? [...items].sort((a, b) => b.value - a.value) : items;
    const top = max ?? Math.max(0, ...rows.map((item) => item.value));

    return (
        <ul aria-label={label} className={cx('flex flex-col gap-2.5', className)} style={{ ['--bar-label' as string]: `${labelWidth}px` }}>
            {rows.map((item) => {
                const percent = top > 0 ? Math.min(100, (item.value / top) * 100) : 0;
                const fill = item.status ? FAMILY_TONE[requestStatus(item.status).family].fill : 'bg-viz-1';
                const name = item.status ? requestStatus(item.status).label : item.label;
                return (
                    <li key={item.key} className="grid grid-cols-1 items-center gap-x-3 gap-y-1 sm:grid-cols-[var(--bar-label)_minmax(0,1fr)]">
                        <span className="flex min-w-0 flex-wrap items-center gap-x-2 text-sm">
                            {item.status ? <StatusChip status={item.status} size="sm" /> : <span className="truncate font-semibold">{item.label}</span>}
                            {item.note ? <span className="text-[13px] text-fg-muted">{item.note}</span> : null}
                        </span>
                        <span className="flex min-w-0 items-center gap-2">
                            <span className="relative h-4 min-w-0 flex-1" aria-hidden="true">
                                <span
                                    className={cx('absolute inset-y-0 left-0 rounded-r-[4px]', fill)}
                                    style={{ width: `${percent}%`, minWidth: item.value > 0 ? 2 : 0 }}
                                />
                            </span>
                            <span className="tabular w-[4.5rem] shrink-0 text-right text-[13px] font-bold">
                                <span className="sr-only">{typeof name === 'string' ? `${name}: ` : ''}</span>
                                {formatValue(item.value)}
                            </span>
                        </span>
                    </li>
                );
            })}
        </ul>
    );
}
