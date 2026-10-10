import { useEffect, useId, useRef, useState, type KeyboardEvent } from 'react';
import { Icon } from '@/Components/ui/Icon';
import { moveItem } from '@/Components/ui/SortableList';
import { cx } from '@/Components/ui/utils';

export interface StepsEditorProps {
    label: string;
    steps: string[];
    onChange: (steps: string[]) => void;
    placeholder?: string;
    hint?: string;
    /** `fields.steps` or the first `fields.steps.N` error. */
    error?: string;
    required?: boolean;
}

type Focus = { index: number; target: 'input' | 'up' | 'down' };

/**
 * Ordered procedure steps: type one per row, Enter adds the next one,
 * Subir / Bajar reorder with keyboard or touch, and every move is announced.
 */
export function StepsEditor({ label, steps, onChange, placeholder, hint, error, required = false }: StepsEditorProps) {
    const baseId = useId();
    const listRef = useRef<HTMLOListElement>(null);
    const [focus, setFocus] = useState<Focus | null>(null);
    const [announcement, setAnnouncement] = useState('');
    const hintId = hint ? `${baseId}-hint` : undefined;
    const errorId = error ? `${baseId}-error` : undefined;
    const describedBy = [errorId, hintId].filter(Boolean).join(' ') || undefined;

    useEffect(() => {
        if (!focus) return;
        const selector = focus.target === 'input' ? `[data-step-input="${focus.index}"]` : `[data-step-move="${focus.index}-${focus.target}"]`;
        let element = listRef.current?.querySelector<HTMLElement>(selector);
        // A move to the first or last row disables that arrow; land on the other one.
        if (element instanceof HTMLButtonElement && element.disabled) {
            const other = focus.target === 'up' ? 'down' : 'up';
            element = listRef.current?.querySelector<HTMLElement>(`[data-step-move="${focus.index}-${other}"]`);
        }
        element?.focus();
        setFocus(null);
    }, [focus]);

    const update = (index: number, value: string) => onChange(steps.map((step, position) => (position === index ? value : step)));

    const add = (after: number) => {
        const next = [...steps.slice(0, after + 1), '', ...steps.slice(after + 1)];
        onChange(next);
        setFocus({ index: after + 1, target: 'input' });
    };

    const remove = (index: number) => {
        const next = steps.filter((_, position) => position !== index);
        onChange(next.length === 0 ? [''] : next);
        setAnnouncement(`Se quitó el paso ${index + 1}.`);
        setFocus({ index: Math.max(0, Math.min(index, next.length - 1)), target: 'input' });
    };

    const move = (index: number, direction: 'up' | 'down') => {
        const to = direction === 'up' ? index - 1 : index + 1;
        if (to < 0 || to >= steps.length) return;
        onChange(moveItem(steps, index, to));
        setAnnouncement(`El paso ${index + 1} quedó en el puesto ${to + 1} de ${steps.length}.`);
        setFocus({ index: to, target: direction });
    };

    const onKeyDown = (event: KeyboardEvent<HTMLInputElement>, index: number) => {
        if (event.key === 'Enter') {
            event.preventDefault();
            add(index);
        } else if (event.altKey && (event.key === 'ArrowUp' || event.key === 'ArrowDown')) {
            event.preventDefault();
            move(index, event.key === 'ArrowUp' ? 'up' : 'down');
            setFocus({ index: event.key === 'ArrowUp' ? index - 1 : index + 1, target: 'input' });
        } else if (event.key === 'Backspace' && steps[index] === '' && steps.length > 1) {
            event.preventDefault();
            remove(index);
        }
    };

    const moveButton = 'flex size-11 shrink-0 items-center justify-center rounded-control text-fg-soft hover:bg-subtle disabled:cursor-not-allowed disabled:opacity-35';

    return (
        <fieldset aria-describedby={describedBy} className="flex min-w-0 flex-col gap-2">
            <legend className={cx('mb-1.5 text-[13px] font-semibold', error && 'text-status-failure')}>
                {label}
                {required ? null : <span className="font-normal text-fg-muted"> (opcional)</span>}
            </legend>
            <ol ref={listRef} className="flex flex-col gap-2">
                {steps.map((step, index) => (
                    <li key={index} className="flex items-center gap-1 sm:gap-1.5">
                        <span
                            aria-hidden="true"
                            className="tabular flex size-7 shrink-0 items-center justify-center rounded-full bg-subtle text-[13px] font-bold text-fg-soft"
                        >
                            {index + 1}
                        </span>
                        <input
                            type="text"
                            data-step-input={index}
                            aria-label={`Paso ${index + 1}`}
                            aria-invalid={error ? true : undefined}
                            value={step}
                            placeholder={index === 0 ? placeholder : undefined}
                            onChange={(event) => update(index, event.target.value)}
                            onKeyDown={(event) => onKeyDown(event, index)}
                            className={cx(
                                'h-11 min-w-0 flex-1 rounded-control border bg-surface px-3 text-[15px] outline-none placeholder:text-fg-muted focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-fg',
                                error ? 'border-2 border-status-failure' : 'border-line-strong',
                            )}
                        />
                        <button
                            type="button"
                            data-step-move={`${index}-up`}
                            aria-label={`Subir el paso ${index + 1}`}
                            disabled={index === 0}
                            onClick={() => move(index, 'up')}
                            className={moveButton}
                        >
                            <Icon name="arrow-up" size={16} />
                        </button>
                        <button
                            type="button"
                            data-step-move={`${index}-down`}
                            aria-label={`Bajar el paso ${index + 1}`}
                            disabled={index === steps.length - 1}
                            onClick={() => move(index, 'down')}
                            className={moveButton}
                        >
                            <Icon name="arrow-down" size={16} />
                        </button>
                        <button
                            type="button"
                            aria-label={`Quitar el paso ${index + 1}`}
                            disabled={steps.length === 1 && step === ''}
                            onClick={() => remove(index)}
                            className={moveButton}
                        >
                            <Icon name="x" size={16} />
                        </button>
                    </li>
                ))}
            </ol>
            <button
                type="button"
                onClick={() => add(steps.length - 1)}
                className="inline-flex min-h-11 items-center gap-1.5 self-start rounded-control px-1 text-sm font-bold text-fg hover:underline"
            >
                <Icon name="plus" size={16} strokeWidth={2.4} />
                Agregar paso
            </button>
            {error ? (
                <p id={errorId} className="flex items-start gap-1.5 text-[13px] font-semibold text-status-failure">
                    <span className="mt-0.5">
                        <Icon name="alert-triangle" size={14} />
                    </span>
                    {error}
                </p>
            ) : null}
            {hint ? (
                <p id={hintId} className="text-[13px] text-fg-muted">
                    {hint} Enter agrega un paso; Alt + flechas lo mueve.
                </p>
            ) : null}
            <span role="status" aria-live="polite" className="sr-only">
                {announcement}
            </span>
        </fieldset>
    );
}
