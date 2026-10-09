import { Semaphore, type SemaphoreLevel } from '@/Components/ui/Semaphore';
import { cx } from '@/Components/ui/utils';
import type { DueLevel, FirstResponseLevel } from './triage';

export const DUE_TO_SEMAPHORE: Record<DueLevel, SemaphoreLevel> = { late: 'late', today: 'attention', ok: 'ok' };

export interface DueTextProps {
    level: FirstResponseLevel;
    label: string;
    className?: string;
}

/**
 * Deadline or first-response state with its semáforo shape (square late,
 * triangle today, circle on time). Answered requests show plain muted text.
 */
export function DueText({ level, label, className }: DueTextProps) {
    if (level === 'answered') return <span className={cx('text-[13px] text-fg-muted', className)}>{label}</span>;
    return (
        <Semaphore level={DUE_TO_SEMAPHORE[level]} className={className}>
            {label}
        </Semaphore>
    );
}
