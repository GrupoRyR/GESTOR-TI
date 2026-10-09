import type { ReactNode } from 'react';
import { Icon } from './Icon';
import { cx } from './utils';

export interface StatTileProps {
    /** What the number is: "Horas ahorradas al mes". */
    label: string;
    /** Formatted value: "210 h", "4,2 de 5". */
    value: string;
    /** Context under the number: "45 calificaciones de 4 apps". */
    note?: ReactNode;
    /**
     * Change against the previous period, already phrased: "+24 h frente a
     * agosto". `good` decides the color, never the sign.
     */
    delta?: { text: string; direction: 'up' | 'down'; good: boolean };
    /** Marks figures only visible with `ai.costs.view`. */
    restricted?: boolean;
    className?: string;
}

/**
 * Quiet KPI: label, one large number, one line of context. No icon tiles or
 * colored backgrounds; the delta carries an arrow so it never relies on color.
 */
export function StatTile({ label, value, note, delta, restricted = false, className }: StatTileProps) {
    return (
        <div className={cx('flex min-w-0 flex-col gap-1.5 rounded-card border border-line bg-surface p-4 md:p-5', className)}>
            <span className="flex items-center gap-1.5 text-[13px] font-semibold text-fg-soft">
                {label}
                {restricted ? <Icon name="lock" size={13} label="Visible solo con permiso de costos" /> : null}
            </span>
            <span className="font-sans text-[28px] leading-none font-bold md:text-[32px]">{value}</span>
            {delta ? (
                <span
                    className={cx(
                        'flex items-center gap-1 text-[13px] font-semibold',
                        delta.good ? 'text-status-active' : 'text-status-failure',
                    )}
                >
                    <Icon name={delta.direction === 'up' ? 'arrow-up' : 'arrow-down'} size={13} strokeWidth={2.4} />
                    {delta.text}
                </span>
            ) : null}
            {note ? <span className="text-[13px] leading-snug text-fg-muted">{note}</span> : null}
        </div>
    );
}
