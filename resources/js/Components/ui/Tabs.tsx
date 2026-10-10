import { Link } from '@inertiajs/react';
import { Icon, type IconName } from './Icon';
import { cx } from './utils';

export interface TabItem {
    key: string;
    label: string;
    href: string;
    /** Optional count after the label. */
    count?: number;
    /** Optional icon before the label (e.g. the lock on Bóveda). Decorative. */
    icon?: IconName;
    /** Extra words for screen readers only, e.g. "(protegida)". */
    srHint?: string;
}

export interface TabsProps {
    items: TabItem[];
    current: string;
    /** Accessible name of the navigation, e.g. "Secciones de Administración". */
    label: string;
    /** `brand` underline (module tabs, default) or `neutral` (nested tabs, e.g. a project). */
    tone?: 'brand' | 'neutral';
    /** `md` 56 px (default) · `sm` 44 px for nested tabs. */
    size?: 'md' | 'sm';
    className?: string;
}

/** Page-level link tabs with an underline; scrolls sideways on phone. */
export function Tabs({ items, current, label, tone = 'brand', size = 'md', className }: TabsProps) {
    return (
        <nav aria-label={label} className={cx('relative overflow-x-auto [scrollbar-width:none]', className)}>
            <ul className={cx('flex min-w-max items-stretch gap-1', size === 'md' ? 'h-14' : 'h-11')}>
                {items.map((item) => {
                    const active = item.key === current;
                    return (
                        <li key={item.key} className="flex">
                            <Link
                                href={item.href}
                                aria-current={active ? 'page' : undefined}
                                className={cx(
                                    'flex items-center border-b-[3px] text-sm whitespace-nowrap transition-colors duration-150 focus-visible:-outline-offset-2',
                                    size === 'md' ? 'gap-2 px-3.5' : 'gap-1.5 px-3',
                                    active
                                        ? cx('font-bold text-fg', tone === 'brand' ? 'border-brand-button' : 'border-fg')
                                        : 'border-transparent font-semibold text-fg-soft hover:border-line-strong hover:text-fg',
                                )}
                            >
                                {item.icon ? <Icon name={item.icon} size={14} /> : null}
                                {item.label}
                                {item.srHint ? <span className="sr-only"> {item.srHint}</span> : null}
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
