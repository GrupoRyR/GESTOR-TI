import { Link } from '@inertiajs/react';
import { useId } from 'react';
import { cx } from '@/Components/ui/utils';
import type { AttentionItem } from '@/types/pages/home';
import { countTone, itemHref } from './attention';

export interface AttentionCardProps {
    title: string;
    items: AttentionItem[];
    /** Shown when the group is empty. */
    empty: string;
}

/** Tablet and desktop: one attention group with its count and items. */
export function AttentionCard({ title, items, empty }: AttentionCardProps) {
    const titleId = useId();
    return (
        <section aria-labelledby={titleId} className="flex flex-col gap-3 rounded-card border border-line bg-surface p-[18px]">
            <div className="flex items-center justify-between gap-3">
                <h2 id={titleId} className="font-sans text-sm font-bold tracking-normal md:text-[15px] lg:text-sm">
                    {title}
                </h2>
                <span className={cx('tabular font-display text-[26px] leading-none font-bold', countTone(items.map((item) => item.severity)))}>
                    {items.length}
                </span>
            </div>
            {items.length === 0 ? (
                <p className="text-[13px] text-fg-muted">{empty}</p>
            ) : (
                <ul className="flex flex-col gap-1">
                    {items.map((item) => (
                        <li key={item.id}>
                            <Link
                                href={itemHref(item)}
                                className="-mx-2 flex min-h-11 flex-col justify-center gap-0.5 rounded-control px-2 py-1.5 text-sm leading-[1.35] no-underline transition-colors duration-150 hover:bg-ground lg:text-[13px]"
                            >
                                <b className="font-bold">{item.title}</b>
                                <span className="text-fg-soft">{item.detail}</span>
                            </Link>
                        </li>
                    ))}
                </ul>
            )}
        </section>
    );
}
