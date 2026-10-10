import { useEffect, useState, type ReactNode } from 'react';
import { controlClasses, Field, useFieldIds } from './Field';

const HEX = /^#[0-9a-fA-F]{6}$/;

export interface ColorFieldProps {
    label: ReactNode;
    /** "#RRGGBB". */
    value: string;
    onChange: (hex: string) => void;
    hint?: ReactNode;
    error?: ReactNode;
    name?: string;
    id?: string;
    disabled?: boolean;
    className?: string;
}

/**
 * Swatch (native color picker) plus a hex text input. The text only reports
 * a change when it is a full "#RRGGBB" value.
 */
export function ColorField({ label, value, onChange, hint, error, name, id, disabled, className }: ColorFieldProps) {
    const ids = useFieldIds(id, hint, error);
    const [text, setText] = useState(value.toUpperCase());

    useEffect(() => {
        setText(value.toUpperCase());
    }, [value]);

    const swatch = HEX.test(value) ? value : '#000000';
    const invalidText = text !== '' && !HEX.test(text);

    return (
        <Field label={label} hint={hint} error={error} ids={ids} className={className}>
            <div className="flex items-center gap-2">
                <input
                    type="color"
                    value={swatch}
                    disabled={disabled}
                    aria-label="Elegir color"
                    onChange={(event) => onChange(event.target.value.toUpperCase())}
                    className="h-11 w-11 shrink-0 cursor-pointer rounded-control border border-line-strong bg-surface p-1 disabled:cursor-not-allowed"
                />
                <input
                    id={ids.inputId}
                    name={name}
                    type="text"
                    inputMode="text"
                    autoComplete="off"
                    spellCheck={false}
                    maxLength={7}
                    value={text}
                    disabled={disabled}
                    aria-invalid={error || invalidText ? true : undefined}
                    aria-describedby={ids.describedBy}
                    onChange={(event) => {
                        const next = event.target.value.trim();
                        const normalized = next.startsWith('#') ? next : `#${next}`;
                        setText(normalized.toUpperCase());
                        if (HEX.test(normalized)) onChange(normalized.toUpperCase());
                    }}
                    className={[controlClasses(Boolean(error) || invalidText), 'tabular font-semibold uppercase'].join(' ')}
                />
            </div>
        </Field>
    );
}
