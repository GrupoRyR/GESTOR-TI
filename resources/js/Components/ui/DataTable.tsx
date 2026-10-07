import { Link } from '@inertiajs/react';
import type { CSSProperties, Key, ReactNode } from 'react';
import { cx } from './utils';

export interface DataTableColumn<T> {
    key: string;
    header: ReactNode;
    /** Grid track, e.g. "84px" or "minmax(0, 1fr)" (default). */
    width?: string;
    align?: 'start' | 'center' | 'end';
    render: (row: T) => ReactNode;
    /**
     * With `rowHref`, this cell holds the row's link and its click area is
     * stretched over the whole row. Use the column that names the row
     * (defaults to the first column). Other interactive cells stay on top.
     */
    rowLink?: boolean;
    /** Visually hidden header (still announced), for icon-only columns. */
    hideHeader?: boolean;
}

export interface DataTableProps<T> {
    columns: DataTableColumn<T>[];
    rows: T[];
    rowKey: (row: T) => Key;
    rowHref?: (row: T) => string;
    /** Accessible name of the table. */
    label: string;
    /** Shown instead of rows when `rows` is empty. */
    empty?: ReactNode;
    /** Minimum width before the wrapper scrolls sideways (default 720 px). */
    minWidth?: number;
    className?: string;
}

const ALIGN = { start: 'justify-self-start text-left', center: 'justify-self-center text-center', end: 'justify-self-end text-right' };

/**
 * Dense data grid (ARIA table roles on CSS grid): 40 px header on the subtle
 * surface, rows of at least 56 px. Scrolls horizontally on narrow screens.
 */
export function DataTable<T>({ columns, rows, rowKey, rowHref, label, empty, minWidth = 720, className }: DataTableProps<T>) {
    const template: CSSProperties = {
        gridTemplateColumns: columns.map((column) => column.width ?? 'minmax(0, 1fr)').join(' '),
    };
    const linkIndex = Math.max(
        0,
        columns.findIndex((column) => column.rowLink),
    );
    const rowClasses = 'grid items-center gap-x-4 px-5';

    return (
        <div className={cx('overflow-x-auto', className)}>
            <div role="table" aria-label={label} style={{ minWidth }}>
                <div role="rowgroup">
                    <div role="row" style={template} className={cx(rowClasses, 'h-10 bg-subtle text-xs font-bold text-fg-muted')}>
                        {columns.map((column) => (
                            <span
                                key={column.key}
                                role="columnheader"
                                className={ALIGN[column.align ?? 'start']}
                            >
                                {/* sr-only on an inner span: the header cell must keep its grid track. */}
                                {column.hideHeader ? <span className="sr-only">{column.header}</span> : column.header}
                            </span>
                        ))}
                    </div>
                </div>
                <div role="rowgroup">
                    {rows.length === 0 && empty ? (
                        <div role="row" className="border-t border-line">
                            <div role="cell" className="px-5 py-8">
                                {empty}
                            </div>
                        </div>
                    ) : null}
                    {rows.map((row) => {
                        const href = rowHref?.(row);
                        return (
                            <div
                                key={rowKey(row)}
                                role="row"
                                style={template}
                                className={cx(
                                    rowClasses,
                                    'relative min-h-14 border-t border-line py-2 text-sm',
                                    href && 'transition-colors duration-150 hover:bg-ground has-focus-visible:bg-ground',
                                )}
                            >
                                {columns.map((column, index) => {
                                    const content = column.render(row);
                                    const isLink = href !== undefined && index === linkIndex;
                                    return (
                                        <div
                                            key={column.key}
                                            role="cell"
                                            className={cx(
                                                'min-w-0',
                                                ALIGN[column.align ?? 'start'],
                                                // Interactive cells sit above the stretched row link.
                                                href !== undefined &&
                                                    !isLink &&
                                                    'has-[a]:relative has-[a]:z-10 has-[button]:relative has-[button]:z-10 has-[input]:relative has-[input]:z-10',
                                            )}
                                        >
                                            {isLink ? (
                                                <Link
                                                    href={href}
                                                    className="block min-w-0 text-inherit no-underline outline-none after:absolute after:inset-0 after:content-[''] focus-visible:after:rounded-sm focus-visible:after:outline-2 focus-visible:after:-outline-offset-2 focus-visible:after:outline-fg"
                                                >
                                                    {content}
                                                </Link>
                                            ) : (
                                                content
                                            )}
                                        </div>
                                    );
                                })}
                            </div>
                        );
                    })}
                </div>
            </div>
        </div>
    );
}
