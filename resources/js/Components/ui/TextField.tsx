import type { InputHTMLAttributes, ReactNode } from 'react';
import { controlClasses, Field, useFieldIds } from './Field';

export interface TextFieldProps extends Omit<InputHTMLAttributes<HTMLInputElement>, 'size'> {
    label: ReactNode;
    hint?: ReactNode;
    error?: ReactNode;
    /** `md` 44 px (default) · `lg` 48 px for forms on phone. */
    size?: 'md' | 'lg';
    optional?: boolean;
    containerClassName?: string;
}

/** Labelled text input with hint and error wired through `aria-describedby`. */
export function TextField({
    label,
    hint,
    error,
    size = 'md',
    optional,
    id,
    className,
    containerClassName,
    type = 'text',
    ...rest
}: TextFieldProps) {
    const ids = useFieldIds(id, hint, error);
    return (
        <Field label={label} hint={hint} error={error} ids={ids} optional={optional} className={containerClassName}>
            <input
                id={ids.inputId}
                type={type}
                aria-invalid={error ? true : undefined}
                aria-describedby={ids.describedBy}
                className={[controlClasses(Boolean(error), size), className].filter(Boolean).join(' ')}
                {...rest}
            />
        </Field>
    );
}
