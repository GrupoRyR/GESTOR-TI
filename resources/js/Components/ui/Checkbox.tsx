import { useId, type InputHTMLAttributes, type ReactNode } from 'react';
import { cx } from './utils';

export interface CheckboxProps extends Omit<InputHTMLAttributes<HTMLInputElement>, 'type'> {
    label: ReactNode;
    description?: ReactNode;
    containerClassName?: string;
}

/** 20 px native checkbox in the brand button color; the whole row is the 44 px target. */
export function Checkbox({ label, description, id, containerClassName, className, ...rest }: CheckboxProps) {
    const generated = useId();
    const inputId = id ?? generated;
    const descriptionId = description ? `${inputId}-description` : undefined;

    return (
        <label
            htmlFor={inputId}
            className={cx(
                'flex min-h-11 cursor-pointer items-start gap-3 py-2.5 has-disabled:cursor-not-allowed has-disabled:opacity-60',
                containerClassName,
            )}
        >
            <input
                id={inputId}
                type="checkbox"
                aria-describedby={descriptionId}
                className={cx('mt-px size-5 shrink-0 cursor-[inherit] accent-brand-button', className)}
                {...rest}
            />
            <span className="flex min-w-0 flex-col gap-0.5 text-sm leading-5">
                <span className="font-semibold">{label}</span>
                {description ? (
                    <span id={descriptionId} className="text-[13px] text-fg-muted">
                        {description}
                    </span>
                ) : null}
            </span>
        </label>
    );
}
