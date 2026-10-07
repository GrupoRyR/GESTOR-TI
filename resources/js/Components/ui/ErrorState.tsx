import type { ReactNode } from 'react';
import { StateTile } from './EmptyState';
import { cx } from './utils';

export interface ErrorStateProps {
    title: ReactNode;
    /** What happened and how to fix it. */
    description?: ReactNode;
    actions?: ReactNode;
    /** Technical footnote: last attempt, error code. */
    footnote?: ReactNode;
    className?: string;
}

/** Something failed: red border, announced immediately. */
export function ErrorState({ title, description, actions, footnote, className }: ErrorStateProps) {
    return (
        <section
            role="alert"
            className={cx('flex flex-col items-start gap-3.5 rounded-card border border-status-failure-line bg-surface p-6 md:p-8', className)}
        >
            <StateTile icon="alert-triangle" tone="danger" />
            <h2 className="font-display text-xl leading-tight font-bold md:text-2xl">{title}</h2>
            {description ? <div className="max-w-[520px] text-[15px] leading-normal text-fg-soft">{description}</div> : null}
            {actions ? <div className="flex flex-wrap gap-2">{actions}</div> : null}
            {footnote ? <p className="text-xs text-fg-muted">{footnote}</p> : null}
        </section>
    );
}
