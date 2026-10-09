import { useId, type ReactNode } from 'react';
import { cx } from './utils';

export interface SwitchProps {
    checked: boolean;
    onChange: (checked: boolean) => void;
    label: ReactNode;
    description?: ReactNode;
    disabled?: boolean;
    /** Hide the visible label (still announced). */
    hideLabel?: boolean;
    name?: string;
    className?: string;
}

/** On/off toggle that applies immediately (`role="switch"`). */
export function Switch({ checked, onChange, label, description, disabled = false, hideLabel = false, name, className }: SwitchProps) {
    const id = useId();
    const labelId = `${id}-label`;
    const descriptionId = description ? `${id}-description` : undefined;

    return (
        <div className={cx('flex min-h-11 items-center justify-between gap-4', disabled && 'opacity-60', className)}>
            <span className={cx('flex min-w-0 flex-col gap-0.5 text-sm leading-5', hideLabel && 'sr-only')}>
                <span id={labelId} className="font-semibold">
                    {label}
                </span>
                {description ? (
                    <span id={descriptionId} className="text-[13px] text-fg-muted">
                        {description}
                    </span>
                ) : null}
            </span>
            <button
                type="button"
                role="switch"
                name={name}
                aria-checked={checked}
                aria-labelledby={labelId}
                aria-describedby={descriptionId}
                disabled={disabled}
                onClick={() => onChange(!checked)}
                className="group inline-flex h-11 w-14 shrink-0 items-center justify-center rounded-control disabled:cursor-not-allowed"
            >
                <span
                    aria-hidden="true"
                    className={cx(
                        'relative inline-flex h-[26px] w-11 items-center rounded-full transition-colors duration-150',
                        checked ? 'bg-brand-button' : 'bg-line-strong',
                    )}
                >
                    <span
                        className={cx(
                            'absolute left-[3px] size-5 rounded-full bg-white shadow-sm transition-transform duration-150',
                            checked && 'translate-x-[18px]',
                        )}
                    />
                </span>
            </button>
        </div>
    );
}
