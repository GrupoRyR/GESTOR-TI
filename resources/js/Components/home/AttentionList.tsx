import { Link } from '@inertiajs/react';
import { Icon } from '@/Components/ui/Icon';
import { cx } from '@/Components/ui/utils';

export interface AttentionRow {
    key: string;
    title: string;
    count: number;
    /** One-line summary, e.g. "GIA-148 vencida hace 1 día". */
    summary: string;
    href: string;
    tone: string;
}

/** Phone: every attention group as one tappable row with its count. */
export function AttentionList({ rows }: { rows: AttentionRow[] }) {
    return (
        <section aria-label="Lo que requiere atención" className="overflow-hidden rounded-card border border-line bg-surface">
            <ul>
                {rows.map((row, index) => (
                    <li key={row.key} className={cx(index > 0 && 'border-t border-line')}>
                        <Link
                            href={row.href}
                            className="flex min-h-14 items-center gap-3 px-4 py-3.5 no-underline focus-visible:-outline-offset-2"
                        >
                            <span className={cx('tabular w-7 shrink-0 font-display text-[22px] leading-none font-bold', row.tone)}>
                                {row.count}
                            </span>
                            <span className="flex min-w-0 flex-1 flex-col text-sm">
                                <b className="font-bold">{row.title}</b>
                                <span className="truncate text-[13px] text-fg-muted">{row.summary}</span>
                            </span>
                            <Icon name="chevron-right" size={18} className="text-fg-muted" />
                        </Link>
                    </li>
                ))}
            </ul>
        </section>
    );
}
