import type { ReactNode, Ref, TextareaHTMLAttributes } from 'react';
import { Field, useFieldIds } from './Field';
import { cx } from './utils';

export interface TextAreaProps extends TextareaHTMLAttributes<HTMLTextAreaElement> {
    label: ReactNode;
    hint?: ReactNode;
    error?: ReactNode;
    optional?: boolean;
    containerClassName?: string;
    ref?: Ref<HTMLTextAreaElement>;
}

/** Labelled multi-line field; same border, focus and error treatment as TextField. */
export function TextArea({ label, hint, error, optional, id, className, containerClassName, rows = 3, ...rest }: TextAreaProps) {
    const ids = useFieldIds(id, hint, error);
    return (
        <Field label={label} hint={hint} error={error} ids={ids} optional={optional} className={containerClassName}>
            <textarea
                id={ids.inputId}
                rows={rows}
                aria-invalid={error ? true : undefined}
                aria-describedby={ids.describedBy}
                className={cx(
                    'w-full resize-y rounded-control border bg-surface px-3.5 py-3 text-base leading-normal text-fg placeholder:text-fg-muted',
                    'transition-colors duration-150 focus-visible:outline-2 focus-visible:outline-offset-1',
                    'disabled:cursor-not-allowed disabled:bg-subtle disabled:text-fg-muted',
                    error ? 'border-status-failure focus-visible:outline-status-failure' : 'border-line-strong hover:border-fg-muted',
                    className,
                )}
                {...rest}
            />
        </Field>
    );
}
