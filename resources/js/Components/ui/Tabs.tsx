import { Link } from '@inertiajs/react';
import { cx } from './utils';

export interface TabItem {
    key: string;
    label: string;
    href: string;
    /** Optional count after the label. */
    count?: number;
}

export interface TabsProps {
    items: TabItem[];
    current: string;
    /** Accessible name of the navigation, e.g. "Secciones de Administración". */
    label: string;
    className?: string;
}

/** Page-level link tabs with a brand underline; scrolls sideways on phone. */
export function Tabs({ items, current, label, className }: TabsProps) {
    return (
        <nav aria-label={label} className={cx('overflow-x-auto [scrollbar-width:none]', className)}>
            <ul className="flex h-14 min-w-max items-stretch gap-1">
                {items.map((item) => {
                    const active = item.key === current;
                    return (
                        <li key={item.key} className="flex">
                            <Link
                                href={item.href}
                                aria-current={active ? 'page' : undefined}
                                className={cx(
                                    'flex items-center gap-2 border-b-[3px] px-3.5 text-sm whitespace-nowrap transition-colors duration-150 focus-visible:-outline-offset-2',
                                    active
                                        ? 'border-brand-button font-bold text-fg'
                                        : 'border-transparent font-semibold text-fg-soft hover:border-line-strong hover:text-fg',
                                )}
                            >
                                {item.label}
                                {item.count !== undefined ? (
                                    <span className="tabular rounded-chip bg-subtle px-1.5 py-0.5 text-xs font-bold text-fg-soft">
                                        {item.count}
                                    </span>
                                ) : null}
                            </Link>
                        </li>
                    );
                })}
            </ul>
        </nav>
    );
}
