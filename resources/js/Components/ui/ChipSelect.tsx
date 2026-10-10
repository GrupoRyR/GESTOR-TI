import { Icon, type IconName } from './Icon';
import { cx } from './utils';

export interface ChipOption<V extends string = string> {
    value: V;
    label: string;
    icon?: IconName;
}

interface ChipSelectBase<V extends string> {
    /** Accessible name of the group, e.g. "Sistemas que usa". */
    label: string;
    options: ChipOption<V>[];
    size?: 'sm' | 'md';
    className?: string;
}

export interface ChipSelectMultiProps<V extends string = string> extends ChipSelectBase<V> {
    multiple: true;
    value: V[];
    onChange: (value: V[]) => void;
}

export interface ChipSelectSingleProps<V extends string = string> extends ChipSelectBase<V> {
    multiple?: false;
    value: V | null;
    /** Pressing the selected chip again clears it (null). */
    onChange: (value: V | null) => void;
}

export type ChipSelectProps<V extends string = string> = ChipSelectMultiProps<V> | ChipSelectSingleProps<V>;

/**
 * Wrapping row of toggle chips (`aria-pressed`). Selected chips are dark
 * with a check, so the state never depends on color alone.
 */
export function ChipSelect<V extends string = string>(props: ChipSelectProps<V>) {
    const { label, options, size = 'md', className } = props;

    const isPressed = (value: V) => (props.multiple ? props.value.includes(value) : props.value === value);

    const toggle = (value: V) => {
        if (props.multiple) {
            props.onChange(props.value.includes(value) ? props.value.filter((item) => item !== value) : [...props.value, value]);
        } else {
            props.onChange(props.value === value ? null : value);
        }
    };

    return (
        <div role="group" aria-label={label} className={cx('flex flex-wrap gap-2', className)}>
            {options.map((option) => {
                const pressed = isPressed(option.value);
                return (
                    <button
                        key={option.value}
                        type="button"
                        aria-pressed={pressed}
                        onClick={() => toggle(option.value)}
                        className={cx(
                            'inline-flex items-center gap-1.5 rounded-chip border whitespace-nowrap transition-colors duration-150',
                            size === 'md' ? 'h-11 px-3.5 text-sm' : 'h-9 px-3 text-[13px]',
                            pressed
                                ? 'border-fg bg-fg font-bold text-on-fg'
                                : 'border-line-strong bg-surface font-semibold text-fg hover:border-fg-muted',
                        )}
                    >
                        {pressed ? <Icon name="check" size={14} strokeWidth={2.6} /> : option.icon ? <Icon name={option.icon} size={14} /> : null}
                        {option.label}
                    </button>
                );
            })}
        </div>
    );
}
