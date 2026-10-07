import { useId, type ReactNode } from 'react';
import { Icon } from './Icon';
import { cx } from './utils';

export interface FieldIds {
    inputId: string;
    hintId: string | undefined;
    errorId: string | undefined;
    describedBy: string | undefined;
}

/** Stable ids for a labelled control with optional hint and error. */
export function useFieldIds(id: string | undefined, hint: ReactNode, error: ReactNode): FieldIds {
    const generated = useId();
    const inputId = id ?? generated;
    const hintId = hint ? `${inputId}-hint` : undefined;
    const errorId = error ? `${inputId}-error` : undefined;
    const describedBy = [errorId, hintId].filter(Boolean).join(' ') || undefined;
    return { inputId, hintId, errorId, describedBy };
}

/** Control border/focus classes shared by text inputs and selects. */
export function controlClasses(invalid: boolean, size: 'md' | 'lg' = 'md'): string {
    return cx(
        'w-full rounded-control border bg-surface px-3.5 text-base text-fg placeholder:text-fg-muted',
        'transition-colors duration-150 disabled:cursor-not-allowed disabled:bg-subtle disabled:text-fg-muted',
        'focus-visible:outline-2 focus-visible:outline-offset-1',
        size === 'md' ? 'h-11' : 'h-12',
        invalid ? 'border-status-failure focus-visible:outline-status-failure' : 'border-line-strong hover:border-fg-muted',
    );
}

export interface FieldProps {
    label: ReactNode;
    hint?: ReactNode;
    error?: ReactNode;
    ids: FieldIds;
    optional?: boolean;
    className?: string;
    children: ReactNode;
}

/** Label above, control, then error and hint. */
export function Field({ label, hint, error, ids, optional = false, className, children }: FieldProps) {
    return (
        <div className={cx('flex flex-col gap-1.5', className)}>
            <label htmlFor={ids.inputId} className="text-[13px] font-semibold text-fg">
                {label}
                {optional ? <span className="font-normal text-fg-muted"> (opcional)</span> : null}
            </label>
            {children}
            {error ? (
                <p id={ids.errorId} className="flex items-start gap-1.5 text-[13px] font-semibold text-status-failure">
                    <span className="mt-0.5">
                        <Icon name="alert-triangle" size={14} />
                    </span>
                    {error}
                </p>
            ) : null}
            {hint ? (
                <p id={ids.hintId} className="text-[13px] leading-snug text-fg-muted">
                    {hint}
                </p>
            ) : null}
        </div>
    );
}
