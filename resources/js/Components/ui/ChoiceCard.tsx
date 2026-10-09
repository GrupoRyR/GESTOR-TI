import { useId, type ReactNode } from 'react';
import { Icon, type IconName } from './Icon';
import { cx } from './utils';

export interface ChoiceOption<V extends string = string> {
    value: V;
    title: string;
    description?: string;
    icon?: IconName;
    /** Extra content shown inside the selected card (e.g. the chosen app). */
    detail?: ReactNode;
}

export interface ChoiceCardGroupProps<V extends string = string> {
    /** Visible legend, e.g. "Tipo de solicitud". */
    legend: string;
    /** Hide the legend visually when the page heading already says it. */
    hideLegend?: boolean;
    name?: string;
    options: ChoiceOption<V>[];
    value: V | null;
    onChange: (value: V) => void;
    error?: string;
    /** `list` stacks the cards (phone), `grid` puts two per row from 640 px. */
    layout?: 'list' | 'grid';
    className?: string;
}

/**
 * Single choice shown as large cards. Built on real radio inputs, so arrow
 * keys move the selection and screen readers announce "1 de 4".
 */
export function ChoiceCardGroup<V extends string = string>({
    legend,
    hideLegend = false,
    name,
    options,
    value,
    onChange,
    error,
    layout = 'list',
    className,
}: ChoiceCardGroupProps<V>) {
    const generated = useId();
    const groupName = name ?? generated;
    const errorId = `${groupName}-error`;

    return (
        <fieldset aria-describedby={error ? errorId : undefined} className={cx('flex min-w-0 flex-col gap-2.5', className)}>
            <legend className={cx('mb-2.5 text-[13px] font-semibold', hideLegend && 'sr-only')}>{legend}</legend>
            <div className={cx('grid gap-2.5', layout === 'grid' && 'sm:grid-cols-2')}>
                {options.map((option) => {
                    const checked = option.value === value;
                    return (
                        <label
                            key={option.value}
                            className={cx(
                                'relative flex cursor-pointer flex-col gap-3 rounded-card border bg-surface p-4 transition-colors duration-150',
                                'has-focus-visible:outline-2 has-focus-visible:outline-offset-2 has-focus-visible:outline-fg',
                                checked ? 'border-2 border-fg p-[15px]' : 'border-line-strong hover:border-fg-muted',
                            )}
                        >
                            <span className="flex items-start gap-3">
                                {option.icon ? (
                                    <span
                                        aria-hidden="true"
                                        className={cx(
                                            'flex size-10 shrink-0 items-center justify-center rounded-icon',
                                            checked ? 'bg-fg text-on-fg' : 'bg-subtle text-fg',
                                        )}
                                    >
                                        <Icon name={option.icon} size={20} />
                                    </span>
                                ) : null}
                                <span className="flex min-w-0 flex-1 flex-col gap-0.5">
                                    <span className="text-[15px] font-bold">{option.title}</span>
                                    {option.description ? (
                                        <span className="text-[13px] leading-snug text-fg-muted">{option.description}</span>
                                    ) : null}
                                </span>
                                <input
                                    type="radio"
                                    name={groupName}
                                    value={option.value}
                                    checked={checked}
                                    onChange={() => onChange(option.value)}
                                    className="peer sr-only"
                                />
                                <span
                                    aria-hidden="true"
                                    className={cx(
                                        'mt-0.5 flex size-[22px] shrink-0 items-center justify-center rounded-full border-2',
                                        checked ? 'border-fg bg-fg text-on-fg' : 'border-line-strong',
                                    )}
                                >
                                    {checked ? <Icon name="check" size={12} strokeWidth={3} /> : null}
                                </span>
                            </span>
                            {checked && option.detail ? <span className="pl-[52px] text-[13px]">{option.detail}</span> : null}
                        </label>
                    );
                })}
            </div>
            {error ? (
                <p id={errorId} className="flex items-start gap-1.5 text-[13px] font-semibold text-status-failure">
                    <span className="mt-0.5">
                        <Icon name="alert-triangle" size={14} />
                    </span>
                    {error}
                </p>
            ) : null}
        </fieldset>
    );
}
