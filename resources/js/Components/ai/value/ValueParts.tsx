import { useId, useState, type ReactNode } from 'react';
import { BarList, type BarListItem } from '@/Components/ui/BarList';
import { Icon } from '@/Components/ui/Icon';
import { StatusChip } from '@/Components/ui/StatusChip';
import { cx } from '@/Components/ui/utils';

/** Dashboard block: heading, one-line description, optional right-side control. */
export function ValueBlock({ title, description, actions, children, className }: { title: string; description?: ReactNode; actions?: ReactNode; children: ReactNode; className?: string }) {
    const id = useId();
    return (
        <section aria-labelledby={id} className={cx('flex min-w-0 flex-col gap-4 rounded-card border border-line bg-surface p-4 md:p-5', className)}>
            <div className="flex flex-wrap items-start justify-between gap-x-3 gap-y-1">
                <div className="flex min-w-0 flex-col gap-0.5">
                    <h2 id={id} className="font-display text-lg leading-tight font-bold">
                        {title}
                    </h2>
                    {description ? <span className="text-[13px] text-fg-muted">{description}</span> : null}
                </div>
                {actions}
            </div>
            {children}
        </section>
    );
}

/** "Ver tabla" toggle for a bar list: same values as a plain table (the WCAG twin of the chart). */
export function BarListWithTable({
    label,
    items,
    formatValue,
    nameHeader,
    valueHeader,
    labelWidth,
    sort,
}: {
    label: string;
    items: BarListItem[];
    formatValue: (value: number) => string;
    nameHeader: string;
    valueHeader: string;
    labelWidth?: number;
    sort?: boolean;
}) {
    const [table, setTable] = useState(false);
    const rows = sort === false ? items : [...items].sort((a, b) => b.value - a.value);
    return (
        <div className="flex flex-col gap-3">
            <button
                type="button"
                aria-pressed={table}
                onClick={() => setTable((value) => !value)}
                className="-mt-2 inline-flex h-11 items-center gap-1.5 self-end rounded-control px-2 text-[13px] font-bold text-fg-soft hover:bg-subtle hover:text-fg"
            >
                <Icon name={table ? 'trend' : 'table'} size={16} />
                {table ? 'Ver gráfico' : 'Ver tabla'}
            </button>
            {table ? (
                <table className="w-full border-collapse text-sm">
                    <caption className="sr-only">{label}</caption>
                    <thead>
                        <tr className="border-b border-line text-left text-xs text-fg-muted">
                            <th scope="col" className="py-2 font-bold">
                                {nameHeader}
                            </th>
                            <th scope="col" className="py-2 text-right font-bold">
                                {valueHeader}
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        {rows.map((item) => (
                            <tr key={item.key} className="border-b border-line last:border-0">
                                <th scope="row" className="py-2 text-left font-semibold">
                                    {item.status ? <StatusChip status={item.status} size="sm" /> : item.label}
                                </th>
                                <td className="tabular py-2 text-right">{formatValue(item.value)}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            ) : (
                <BarList label={label} items={rows} formatValue={formatValue} labelWidth={labelWidth} sort={false} />
            )}
        </div>
    );
}

/** Mini horizontal bar for a table cell (decorative; the number is written next to it). */
export function MiniBar({ value, max }: { value: number; max: number }) {
    const percent = max > 0 ? Math.min(100, (value / max) * 100) : 0;
    return (
        <span aria-hidden="true" className="relative block h-3 min-w-0 flex-1 rounded-r-[4px] bg-subtle">
            <span className="absolute inset-y-0 left-0 rounded-r-[4px] bg-viz-1" style={{ width: `${percent}%` }} />
        </span>
    );
}
