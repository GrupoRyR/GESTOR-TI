import { Icon } from '@/Components/ui/Icon';
import { cx } from '@/Components/ui/utils';
import { elapsedSeconds, formatElapsed, formatElapsedSpoken } from '@/lib/timer';
import { useClock } from '@/lib/useClock';

export interface TimerButtonProps {
    /** Task key for the accessible name: "T4". */
    taskKey: string;
    running: boolean;
    /** Instant the current run started (required while running). */
    startedAt?: string | null;
    onStart: () => void;
    onStop: () => void;
    /**
     * `icon` 44 × 44 start button (cards without a running timer) ·
     * `md` full-width card button · `lg` the "Trabajando ahora" banner.
     */
    size?: 'icon' | 'md' | 'lg';
    /** Mock/test clock origin; the device clock otherwise. */
    clockStartAt?: string;
    disabled?: boolean;
    className?: string;
}

/**
 * Task stopwatch. Running shows "Parar · 01:12:40" on a dark button;
 * stopped shows "Iniciar". The elapsed time derives from `startedAt`, so a
 * reload never loses it; the server records the entry on stop.
 */
export function TimerButton({ taskKey, running, startedAt, onStart, onStop, size = 'md', clockStartAt, disabled, className }: TimerButtonProps) {
    const now = useClock({ startAt: clockStartAt, running });
    const seconds = running && startedAt ? elapsedSeconds(startedAt, now) : 0;

    if (!running) {
        if (size === 'icon') {
            return (
                <button
                    type="button"
                    onClick={onStart}
                    disabled={disabled}
                    aria-label={`Iniciar cronómetro en ${taskKey}`}
                    title="Iniciar cronómetro"
                    className={cx(
                        'flex size-11 shrink-0 items-center justify-center rounded-control border border-line-strong bg-surface text-fg transition-colors duration-150 hover:bg-subtle disabled:opacity-40',
                        className,
                    )}
                >
                    <Icon name="play" size={16} />
                </button>
            );
        }
        return (
            <button
                type="button"
                onClick={onStart}
                disabled={disabled}
                aria-label={`Iniciar cronómetro en ${taskKey}`}
                className={cx(
                    'inline-flex shrink-0 items-center justify-center gap-2 rounded-control border border-line-strong bg-surface font-bold text-fg transition-colors duration-150 hover:bg-subtle disabled:opacity-40',
                    size === 'lg' ? 'h-12 px-5 text-[15px]' : 'h-11 px-4 text-[13px]',
                    className,
                )}
            >
                <Icon name="play" size={14} />
                Iniciar
            </button>
        );
    }

    const label = `Parar cronómetro de ${taskKey}. Lleva ${formatElapsedSpoken(seconds)}`;

    return (
        <button
            type="button"
            onClick={onStop}
            disabled={disabled}
            aria-label={label}
            className={cx(
                'inline-flex shrink-0 items-center justify-center gap-2 rounded-control bg-fg font-bold text-on-fg transition-[filter] duration-150 hover:brightness-[1.15] disabled:opacity-40 dark:hover:brightness-[0.9]',
                size === 'lg' ? 'h-12 px-5 text-[15px]' : 'h-11 px-4 text-[13px]',
                size === 'md' && 'w-full',
                className,
            )}
        >
            <span aria-hidden="true" className="size-2 shrink-0 rounded-[2px] bg-current" />
            <span aria-hidden="true">
                Parar · <span className="tabular">{formatElapsed(seconds)}</span>
            </span>
        </button>
    );
}

export interface ElapsedProps {
    startedAt: string;
    clockStartAt?: string;
    className?: string;
}

/** Large running time for banners ("01:12:40"), as a `timer` role. */
export function Elapsed({ startedAt, clockStartAt, className }: ElapsedProps) {
    const now = useClock({ startAt: clockStartAt });
    const seconds = elapsedSeconds(startedAt, now);
    return (
        <span role="timer" aria-label={formatElapsedSpoken(seconds)} className={cx('tabular font-bold', className)}>
            {formatElapsed(seconds)}
        </span>
    );
}
