import type { ReactNode, SelectHTMLAttributes } from 'react';
import { controlClasses, Field, useFieldIds } from './Field';
import { Icon } from './Icon';

export interface SelectOption {
    value: string;
    label: string;
    disabled?: boolean;
}

export interface SelectProps extends Omit<SelectHTMLAttributes<HTMLSelectElement>, 'size'> {
    label: ReactNode;
    options: SelectOption[];
    /** First, empty option ("Selecciona…"). */
    placeholder?: string;
    hint?: ReactNode;
    error?: ReactNode;
    size?: 'md' | 'lg';
    optional?: boolean;
    containerClassName?: string;
}

/** Native select (best on phones), styled like the text inputs. */
export function Select({
    label,
    options,
    placeholder,
    hint,
    error,
    size = 'md',
    optional,
    id,
    className,
    containerClassName,
    ...rest
}: SelectProps) {
    const ids = useFieldIds(id, hint, error);
    return (
        <Field label={label} hint={hint} error={error} ids={ids} optional={optional} className={containerClassName}>
            <div className="relative">
                <select
                    id={ids.inputId}
                    aria-invalid={error ? true : undefined}
                    aria-describedby={ids.describedBy}
                    className={[controlClasses(Boolean(error), size), 'appearance-none pr-10', className].filter(Boolean).join(' ')}
                    {...rest}
                >
                    {placeholder !== undefined && <option value="">{placeholder}</option>}
                    {options.map((option) => (
                        <option key={option.value} value={option.value} disabled={option.disabled}>
                            {option.label}
                        </option>
                    ))}
                </select>
                <span className="pointer-events-none absolute inset-y-0 right-3 flex items-center text-fg-muted">
                    <Icon name="chevron-down" size={16} strokeWidth={2.2} />
                </span>
            </div>
        </Field>
    );
}
