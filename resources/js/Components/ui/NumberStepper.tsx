import { useState, type ReactNode } from 'react';
import { Field, useFieldIds } from './Field';
import { Icon } from './Icon';
import { cx } from './utils';

export interface NumberStepperProps {
    label: ReactNode;
    hint?: ReactNode;
    error?: ReactNode;
    /** Null = empty field. */
    value: number | null;
    onChange: (value: number | null) => void;
    min?: number;
    max?: number;
    step?: number;
    /** Unit after the number, e.g. "h" or "personas". */
    unit?: string;
    id?: string;
    className?: string;
}

const clamp = (value: number, min: number, max: number) => Math.min(max, Math.max(min, value));

/** Parses "1,5" or "1.5"; empty → null; garbage → undefined (keep typing). */
function parse(text: string): number | null | undefined {
    const trimmed = text.trim().replace(',', '.');
    if (trimmed === '') return null;
    const value = Number(trimmed);
    return Number.isFinite(value) ? value : undefined;
}

const display = (value: number | null) => (value === null ? '' : String(value).replace('.', ','));

/**
 * Number input between 44 px − and + buttons. Typing accepts a decimal
 * comma; the buttons move by `step` within `[min, max]`.
 */
export function NumberStepper({
    label,
    hint,
    error,
    value,
    onChange,
    min = 0,
    max = Number.MAX_SAFE_INTEGER,
    step = 1,
    unit,
    id,
    className,
}: NumberStepperProps) {
    const ids = useFieldIds(id, hint, error);
    const [draft, setDraft] = useState<string | null>(null);
    const text = draft ?? display(value);

    const nudge = (direction: 1 | -1) => {
        const base = value ?? (direction > 0 ? min - step : min);
        const next = clamp(Math.round((base + direction * step) / step) * step, min, max);
        setDraft(null);
        onChange(Number(next.toFixed(4)));
    };

    const buttonClasses =
        'flex size-11 shrink-0 items-center justify-center rounded-control border border-line-strong bg-surface text-fg transition-colors duration-150 hover:bg-subtle disabled:pointer-events-none disabled:opacity-40';

    return (
        <Field label={label} hint={hint} error={error} ids={ids} className={className}>
            <div className="flex items-center gap-2">
                <button
                    type="button"
                    aria-label={typeof label === 'string' ? `Bajar ${label}` : 'Bajar'}
                    aria-controls={ids.inputId}
                    disabled={value !== null && value <= min}
                    onClick={() => nudge(-1)}
                    className={buttonClasses}
                >
                    <span aria-hidden="true" className="h-0.5 w-3.5 rounded-full bg-current" />
                </button>
                <div
                    className={cx(
                        'flex h-12 min-w-0 flex-1 items-center gap-1.5 rounded-control border bg-surface px-3.5',
                        'has-focus-visible:outline-2 has-focus-visible:outline-offset-1',
                        error ? 'border-status-failure has-focus-visible:outline-status-failure' : 'border-line-strong has-focus-visible:outline-fg',
                    )}
                >
                    <input
                        id={ids.inputId}
                        type="text"
                        inputMode="decimal"
                        value={text}
                        aria-invalid={error ? true : undefined}
                        aria-describedby={ids.describedBy}
                        onChange={(event) => {
                            setDraft(event.target.value);
                            const parsed = parse(event.target.value);
                            if (parsed !== undefined) onChange(parsed === null ? null : clamp(parsed, min, max));
                        }}
                        onBlur={() => setDraft(null)}
                        className="tabular w-full min-w-0 bg-transparent text-center text-lg font-bold text-fg outline-none"
                    />
                    {unit ? (
                        <span aria-hidden="true" className="shrink-0 text-sm text-fg-muted">
                            {unit}
                        </span>
                    ) : null}
                </div>
                <button
                    type="button"
                    aria-label={typeof label === 'string' ? `Subir ${label}` : 'Subir'}
                    aria-controls={ids.inputId}
                    disabled={value !== null && value >= max}
                    onClick={() => nudge(1)}
                    className={buttonClasses}
                >
                    <Icon name="plus" size={16} strokeWidth={2.4} />
                </button>
            </div>
        </Field>
    );
}
