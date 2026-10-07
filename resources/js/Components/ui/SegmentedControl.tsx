import { cx } from './utils';

export interface SegmentOption<V extends string = string> {
    value: V;
    label: string;
}

export interface SegmentedControlProps<V extends string = string> {
    options: SegmentOption<V>[];
    value: V;
    onChange: (value: V) => void;
    /** Accessible name of the group, e.g. "Empresa". */
    label: string;
    size?: 'sm' | 'md';
    className?: string;
}

/** Mutually exclusive choice as a group of toggle buttons (`aria-pressed`). */
export function SegmentedControl<V extends string = string>({
    options,
    value,
    onChange,
    label,
    size = 'md',
    className,
}: SegmentedControlProps<V>) {
    return (
        <div role="group" aria-label={label} className={cx('inline-flex shrink-0 gap-0.5 rounded-control bg-subtle p-[3px]', className)}>
            {options.map((option) => {
                const pressed = option.value === value;
                return (
                    <button
                        key={option.value}
                        type="button"
                        aria-pressed={pressed}
                        onClick={() => {
                            if (!pressed) onChange(option.value);
                        }}
                        className={cx(
                            'rounded-chip text-[13px] whitespace-nowrap transition-colors duration-150',
                            size === 'md' ? 'h-[38px] px-3.5' : 'h-[38px] px-3',
                            pressed
                                ? 'bg-surface font-bold text-fg shadow-[0_1px_2px_rgb(23_25_28/0.12)]'
                                : 'font-semibold text-fg-soft hover:text-fg',
                        )}
                    >
                        {option.label}
                    </button>
                );
            })}
        </div>
    );
}
