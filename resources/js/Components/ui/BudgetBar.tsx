import type { ReactNode } from 'react';
import { cx } from './utils';

export type BudgetLevel = 'neutral' | 'warning' | 'over';

/** < 80 % neutral · 80–100 % warning · > 100 % over. */
export function budgetLevel(value: number, max: number): BudgetLevel {
    if (max <= 0) return value > 0 ? 'over' : 'neutral';
    const ratio = value / max;
    if (ratio > 1) return 'over';
    if (ratio >= 0.8) return 'warning';
    return 'neutral';
}

const FILL: Record<BudgetLevel, string> = {
    neutral: 'bg-fg-soft',
    warning: 'bg-status-maintenance-dot',
    over: 'bg-status-failure',
};

const MESSAGE: Record<BudgetLevel, string> = {
    neutral: 'text-fg-muted',
    warning: 'font-semibold text-status-maintenance',
    over: 'font-semibold text-status-failure',
};

export interface BudgetBarProps {
    /** "Horas", "Costo", "Fecha". */
    label: string;
    value: number;
    max: number;
    /** Formatted figures on the right, e.g. "92 de 120 h". */
    valueText: string;
    /** Line under the bar: what is left or how far it went over. */
    message?: ReactNode;
    className?: string;
}

/** Estimated vs. actual with fixed marks at 80 % and 100 %. */
export function BudgetBar({ label, value, max, valueText, message, className }: BudgetBarProps) {
    const level = budgetLevel(value, max);
    const percent = max > 0 ? Math.min(100, Math.max(0, (value / max) * 100)) : 100;

    return (
        <div className={cx('flex flex-col gap-1.5 text-[13px]', className)}>
            <div className="flex justify-between gap-3">
                <span className="font-bold">{label}</span>
                <span className="tabular text-right">{valueText}</span>
            </div>
            <div
                role="meter"
                aria-label={label}
                aria-valuemin={0}
                aria-valuemax={max}
                aria-valuenow={value}
                aria-valuetext={valueText}
                className="relative h-2.5 rounded-[5px] bg-subtle"
            >
                <span className={cx('absolute inset-y-0 left-0 rounded-[5px]', FILL[level])} style={{ width: `${percent}%` }} />
                <span
                    aria-hidden="true"
                    className={cx('absolute -top-[3px] -bottom-[3px] left-[80%] w-0.5', level === 'over' ? 'bg-surface' : 'bg-fg')}
                />
                {level !== 'over' && <span aria-hidden="true" className="absolute -top-[3px] right-0 -bottom-[3px] w-0.5 bg-fg" />}
            </div>
            {message ? <span className={MESSAGE[level]}>{message}</span> : null}
        </div>
    );
}
