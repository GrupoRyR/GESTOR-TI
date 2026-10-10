import { useId, type FormEvent, type Ref } from 'react';
import { Icon } from '@/Components/ui/Icon';
import { cx } from '@/Components/ui/utils';

export interface ComposerProps {
    value: string;
    onChange: (value: string) => void;
    /** Called with the trimmed question; empty questions are ignored. */
    onSubmit: (question: string) => void;
    /** Assistant off, no permission… the input and the button turn off. */
    disabled?: boolean;
    /** Waiting for the answer (also turns the controls off). */
    busy?: boolean;
    /** Default "Pregunta sobre sistemas, informes o procesos". Use it to explain a disabled state. */
    placeholder?: string;
    /** Server validation error for the question (Laravel `errors.question`). */
    error?: string;
    maxLength?: number;
    inputRef?: Ref<HTMLInputElement>;
    className?: string;
}

/**
 * Question box pinned under the log: 44 px input and a 44 px send button in
 * the company color. Disabled states keep the label and say why in the
 * placeholder ("El asistente está apagado", "Espera la respuesta").
 */
export function Composer({
    value,
    onChange,
    onSubmit,
    disabled = false,
    busy = false,
    placeholder = 'Pregunta sobre sistemas, informes o procesos',
    error,
    maxLength = 500,
    inputRef,
    className,
}: ComposerProps) {
    const id = useId();
    const errorId = `${id}-error`;
    const off = disabled || busy;

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        const question = value.trim();
        if (off || question === '') return;
        onSubmit(question);
    };

    return (
        <form onSubmit={submit} className={cx('flex shrink-0 flex-col gap-1.5 border-t border-line bg-surface px-3 py-2.5 md:px-5 md:py-3.5', className)}>
            <div className="flex items-center gap-2 md:gap-2.5">
                <label htmlFor={id} className="sr-only">
                    Escribe tu pregunta
                </label>
                <input
                    ref={inputRef}
                    id={id}
                    type="text"
                    autoComplete="off"
                    enterKeyHint="send"
                    value={value}
                    maxLength={maxLength}
                    disabled={off}
                    placeholder={placeholder}
                    aria-invalid={error ? true : undefined}
                    aria-describedby={error ? errorId : undefined}
                    onChange={(event) => onChange(event.target.value)}
                    className={cx(
                        'h-11 min-w-0 flex-1 rounded-control border bg-ground px-3.5 text-[15px] text-fg placeholder:text-fg-muted disabled:cursor-not-allowed',
                        error ? 'border-status-failure' : 'border-line-strong',
                    )}
                />
                <button
                    type="submit"
                    disabled={off}
                    aria-label={off ? 'Enviar pregunta (no disponible)' : 'Enviar pregunta'}
                    title="Enviar pregunta"
                    className={cx(
                        'flex size-11 shrink-0 items-center justify-center rounded-control transition-[filter] duration-150',
                        off ? 'cursor-not-allowed bg-line text-fg-muted' : 'bg-brand-button text-brand-button-ink hover:brightness-[0.93]',
                    )}
                >
                    <Icon name="send" size={20} />
                </button>
            </div>
            {error ? (
                <p id={errorId} className="flex items-center gap-1.5 text-[13px] font-semibold text-status-failure">
                    <Icon name="alert-triangle" size={14} />
                    {error}
                </p>
            ) : null}
        </form>
    );
}
