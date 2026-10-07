import { PHASE_LABELS, requestStatus, type Phase, type RequestStatusKey } from '@/lib/status';
import { cx, FAMILY_TONE } from './utils';

const PHASES: Phase[] = [1, 2, 3, 4, 5];

export interface PhaseRailProps {
    status: RequestStatusKey;
    /** `compact` for rows (5 × 10 px), `full` with labels under each phase. */
    variant?: 'compact' | 'full';
    className?: string;
}

/**
 * The 12 statuses summarized in 5 phases. Completed phases use the text
 * color, the current one its family color, and a rejected request shows
 * the phases it will not reach as dashed.
 */
export function PhaseRail({ status, variant = 'compact', className }: PhaseRailProps) {
    const { phase, family, key, label } = requestStatus(status);
    const tone = FAMILY_TONE[family];
    const stopped = key === 'rejected';
    const description = `Fase ${phase} de 5: ${PHASE_LABELS[phase]} (${label})`;

    const segment = (index: Phase, base: string) =>
        cx(
            base,
            index < phase && 'bg-fg',
            index === phase && tone.fill,
            index > phase && (stopped ? 'border border-dashed border-line-strong' : 'bg-line'),
        );

    if (variant === 'compact') {
        return (
            <span role="img" aria-label={description} title={description} className={cx('inline-grid grid-cols-[repeat(5,10px)] gap-0.5', className)}>
                {PHASES.map((index) => (
                    <span key={index} className={segment(index, 'h-1.5 rounded-[3px]')} />
                ))}
            </span>
        );
    }

    return (
        <div role="img" aria-label={description} className={cx('flex flex-col gap-1.5', className)}>
            <div className="grid grid-cols-5 gap-1">
                {PHASES.map((index) => (
                    <span key={index} className={segment(index, 'h-2 rounded-[4px]')} />
                ))}
            </div>
            <div className="grid grid-cols-5 gap-1 text-xs text-fg-muted" aria-hidden="true">
                {PHASES.map((index) => (
                    <span key={index} className={cx('truncate', index === phase && cx('font-bold', tone.text))}>
                        {PHASE_LABELS[index]}
                    </span>
                ))}
            </div>
        </div>
    );
}
