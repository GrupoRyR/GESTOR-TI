import type { ReactNode } from 'react';
import { cx } from './utils';

export type SemaphoreLevel = 'ok' | 'attention' | 'late';

const DEFAULT_LABELS: Record<SemaphoreLevel, string> = {
    ok: 'Al día',
    attention: 'Atención',
    late: 'Atrasada',
};

const TEXT: Record<SemaphoreLevel, string> = {
    ok: 'text-status-active',
    attention: 'text-status-maintenance',
    late: 'text-status-failure',
};

export interface SemaphoreProps {
    level: SemaphoreLevel;
    /** Visible text; defaults to "Al día" / "Atención" / "Atrasada". */
    children?: ReactNode;
    className?: string;
}

/** Traffic light with a different shape per level: circle, triangle, square. Always with text. */
export function Semaphore({ level, children, className }: SemaphoreProps) {
    return (
        <span className={cx('inline-flex items-center gap-1.5 text-[13px] font-semibold', TEXT[level], className)}>
            {level === 'ok' && <span aria-hidden="true" className="size-2.5 shrink-0 rounded-full bg-status-active-dot" />}
            {level === 'attention' && (
                <svg aria-hidden="true" width="12" height="12" viewBox="0 0 12 12" className="shrink-0 fill-status-maintenance-dot">
                    <path d="M6 1 11 11H1z" />
                </svg>
            )}
            {level === 'late' && <span aria-hidden="true" className="size-2.5 shrink-0 rounded-[2px] bg-status-failure" />}
            {children ?? DEFAULT_LABELS[level]}
        </span>
    );
}
