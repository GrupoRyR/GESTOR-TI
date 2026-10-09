import { useId } from 'react';
import { cx } from './utils';

export type Rating = 1 | 2 | 3 | 4 | 5;

const VALUES: Rating[] = [1, 2, 3, 4, 5];

export interface RatingScaleProps {
    /** The question: "¿Resolvió tu necesidad?". */
    legend: string;
    /** End labels: ["Nada", "Por completo"]. */
    ends: [string, string];
    value: Rating | null;
    onChange: (value: Rating) => void;
    name?: string;
    className?: string;
}

/**
 * 1–5 scale with big square buttons (radio group): arrow keys move, the
 * end labels give the numbers meaning ("1 · Nada", "5 · Por completo").
 */
export function RatingScale({ legend, ends, value, onChange, name, className }: RatingScaleProps) {
    const generated = useId();
    const group = name ?? generated;
    const endsId = `${group}-ends`;

    return (
        <fieldset aria-describedby={endsId} className={cx('flex min-w-0 flex-col gap-2.5', className)}>
            <legend className="mb-2.5 text-[15px] font-bold">{legend}</legend>
            <div className="grid grid-cols-5 gap-2">
                {VALUES.map((rating) => {
                    const checked = rating === value;
                    const end = rating === 1 ? ends[0] : rating === 5 ? ends[1] : null;
                    return (
                        <label
                            key={rating}
                            className={cx(
                                'flex h-14 cursor-pointer items-center justify-center rounded-control border text-lg font-bold transition-colors duration-150',
                                'has-focus-visible:outline-2 has-focus-visible:outline-offset-2 has-focus-visible:outline-fg',
                                checked ? 'border-fg bg-fg text-on-fg' : 'border-line-strong bg-surface text-fg hover:border-fg-muted',
                            )}
                        >
                            <input
                                type="radio"
                                name={group}
                                value={rating}
                                checked={checked}
                                onChange={() => onChange(rating)}
                                aria-label={end ? `${rating}, ${end}` : String(rating)}
                                className="sr-only"
                            />
                            <span aria-hidden="true">{rating}</span>
                        </label>
                    );
                })}
            </div>
            <div id={endsId} className="flex justify-between gap-3 text-[13px] text-fg-muted">
                <span>1 · {ends[0]}</span>
                <span className="text-right">5 · {ends[1]}</span>
            </div>
        </fieldset>
    );
}
