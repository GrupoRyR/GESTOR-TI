import type { ReactNode } from 'react';
import { Icon, type IconName } from './Icon';
import { cx } from './utils';

export interface StateTileProps {
    icon: IconName;
    tone?: 'neutral' | 'danger';
}

/** 52 px icon tile used by the empty, error and no-permission states. */
export function StateTile({ icon, tone = 'neutral' }: StateTileProps) {
    return (
        <span
            className={cx(
                'flex size-[52px] shrink-0 items-center justify-center rounded-icon',
                tone === 'danger' ? 'bg-status-failure-bg text-status-failure' : 'bg-subtle text-fg-soft',
            )}
        >
            <Icon name={icon} size={24} />
        </span>
    );
}

export interface EmptyStateProps {
    icon?: IconName;
    title: ReactNode;
    description?: ReactNode;
    /** Usually one primary and one secondary Button. */
    actions?: ReactNode;
    /** Card with border (default) or bare, for use inside a Panel. */
    bordered?: boolean;
    className?: string;
}

/** Nothing here yet: say why and what to do next. */
export function EmptyState({ icon = 'inbox', title, description, actions, bordered = true, className }: EmptyStateProps) {
    return (
        <section
            className={cx(
                'flex flex-col items-start gap-3.5',
                bordered && 'rounded-card border border-line bg-surface p-6 md:p-8',
                className,
            )}
        >
            <StateTile icon={icon} />
            <h2 className="font-display text-xl leading-tight font-bold md:text-2xl">{title}</h2>
            {description ? <p className="max-w-[480px] text-[15px] leading-normal text-fg-soft">{description}</p> : null}
            {actions ? <div className="flex flex-wrap gap-2">{actions}</div> : null}
        </section>
    );
}
