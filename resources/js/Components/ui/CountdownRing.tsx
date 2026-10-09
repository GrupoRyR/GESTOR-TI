import { countdownFraction, countdownSpoken, formatCountdown } from '@/lib/countdown';
import { cx } from './utils';

export interface CountdownRingProps {
    /** Seconds left (the caller ticks it, e.g. with `useNow`). */
    seconds: number;
    /** Full duration in seconds, for the ring (30 for a revealed value). */
    total: number;
    /** What runs out: "El valor se oculta en", "La bóveda se cierra en". */
    label: string;
    /** Px (default 44). */
    size?: number;
    className?: string;
}

/**
 * Shrinking ring with the time in the middle ("24 seg"). The ring is
 * decorative; the accessible text says what ends and when. Screen readers
 * get updates without being flooded (`aria-live` off; read on focus).
 */
export function CountdownRing({ seconds, total, label, size = 44, className }: CountdownRingProps) {
    const stroke = 3;
    const radius = (size - stroke) / 2;
    const circumference = 2 * Math.PI * radius;
    const fraction = countdownFraction(seconds, total);
    const urgent = seconds <= Math.min(10, total / 3);

    return (
        <span
            role="timer"
            aria-live="off"
            aria-label={`${label} ${countdownSpoken(seconds)}`}
            className={cx('relative inline-flex shrink-0 items-center justify-center', className)}
            style={{ width: size, height: size }}
        >
            <svg aria-hidden="true" width={size} height={size} viewBox={`0 0 ${size} ${size}`} className="-rotate-90">
                <circle cx={size / 2} cy={size / 2} r={radius} fill="none" strokeWidth={stroke} className="stroke-current opacity-20" />
                <circle
                    cx={size / 2}
                    cy={size / 2}
                    r={radius}
                    fill="none"
                    strokeWidth={stroke}
                    strokeLinecap="round"
                    strokeDasharray={circumference}
                    strokeDashoffset={circumference * (1 - fraction)}
                    className={cx('transition-[stroke-dashoffset] duration-1000 ease-linear', urgent ? 'stroke-status-maintenance-dot' : 'stroke-current')}
                />
            </svg>
            <span aria-hidden="true" className="tabular absolute text-[11px] leading-none font-bold">
                {formatCountdown(seconds).replace(' seg', ' s')}
            </span>
        </span>
    );
}
