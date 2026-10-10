import { Link } from '@inertiajs/react';
import type { ButtonHTMLAttributes } from 'react';
import { Icon, type IconName } from './Icon';
import { cx } from './utils';

export interface IconButtonProps extends Omit<ButtonHTMLAttributes<HTMLButtonElement>, 'children' | 'aria-label'> {
    icon: IconName;
    /** Accessible name; also shown as a tooltip. Include the badge meaning, e.g. "Notificaciones, 4 sin leer". */
    label: string;
    /** Numeric badge in the top-right corner; hidden at 0. */
    badge?: number;
    /** Navigate with Inertia instead of acting. */
    href?: string;
    iconSize?: number;
}

/** 44 × 44 icon-only control. */
export function IconButton({ icon, label, badge, href, iconSize = 20, className, ...rest }: IconButtonProps) {
    const classes = cx(
        'relative inline-flex size-11 shrink-0 items-center justify-center rounded-control text-fg',
        'transition-colors duration-150 hover:bg-subtle disabled:pointer-events-none disabled:opacity-50',
        className,
    );
    const content = (
        <>
            <Icon name={icon} size={iconSize} />
            {badge !== undefined && badge > 0 ? (
                <span
                    aria-hidden="true"
                    className="tabular absolute top-1.5 right-1.5 flex h-[18px] min-w-[18px] items-center justify-center rounded-full bg-status-failure px-1 text-[11px] leading-none font-bold text-white dark:text-on-fg"
                >
                    {badge > 99 ? '99+' : badge}
                </span>
            ) : null}
        </>
    );

    if (href !== undefined) {
        return (
            <Link href={href} aria-label={label} title={label} className={classes}>
                {content}
            </Link>
        );
    }

    return (
        <button type="button" aria-label={label} title={label} className={classes} {...rest}>
            {content}
        </button>
    );
}
