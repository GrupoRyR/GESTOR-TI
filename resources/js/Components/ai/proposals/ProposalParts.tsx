import { useId, type ReactNode } from 'react';
import { SIZES, SizeBars } from '@/Components/ai/requests/EvaluationPanel';
import { urgencyOptions } from '@/Components/ai/requests/urgency';
import { Avatar } from '@/Components/ui/Avatar';
import { controlClasses, Field, useFieldIds } from '@/Components/ui/Field';
import { Icon, type IconName } from '@/Components/ui/Icon';
import { StatusChip } from '@/Components/ui/StatusChip';
import { cx } from '@/Components/ui/utils';
import type { EffortSize, Urgency } from '@/lib/priority';
import type { OutcomeLine } from '@/lib/proposal';
import type { PersonRef } from '@/types/ai';

/** Error line under a group of controls; its id goes in the group's `aria-describedby`. */
export function GroupError({ id, children }: { id: string; children: ReactNode }) {
    return (
        <p id={id} className="flex items-start gap-1.5 text-[13px] font-semibold text-status-failure">
            <span className="mt-0.5">
                <Icon name="alert-triangle" size={14} />
            </span>
            {children}
        </p>
    );
}

/** Panel of the form: white card with a display heading. */
export function FormSection({
    id,
    title,
    hint,
    tone = 'plain',
    children,
    className,
}: {
    id: string;
    title: string;
    hint?: ReactNode;
    /** `project`: blue outline (only for people who can approve projects). */
    tone?: 'plain' | 'project';
    children: ReactNode;
    className?: string;
}) {
    return (
        <section
            aria-labelledby={id}
            className={cx(
                'flex min-w-0 flex-col gap-4 rounded-card bg-surface p-4 md:px-[22px] md:py-5',
                tone === 'project' ? 'border-[1.5px] border-status-scheduled' : 'border border-line',
                className,
            )}
        >
            <div className="flex flex-col gap-1">
                <h2 id={id} className="font-display text-lg font-bold tracking-[-0.02em]">
                    {title}
                </h2>
                {hint ? <p className="text-xs leading-snug text-fg-muted">{hint}</p> : null}
            </div>
            {children}
        </section>
    );
}

export interface SelectGroup {
    label: string;
    options: { value: string; label: string }[];
}

/** Native select with `optgroup`s (Área principal grouped by company), styled like `Select`. */
export function GroupedSelect({
    id,
    label,
    groups,
    value,
    onChange,
    placeholder,
    hint,
    error,
}: {
    id: string;
    label: string;
    groups: SelectGroup[];
    value: string;
    onChange: (value: string) => void;
    placeholder: string;
    hint?: ReactNode;
    error?: string;
}) {
    const ids = useFieldIds(id, hint, error);
    return (
        <Field label={label} hint={hint} error={error} ids={ids}>
            <div className="relative">
                <select
                    id={ids.inputId}
                    value={value}
                    onChange={(event) => onChange(event.target.value)}
                    aria-invalid={error ? true : undefined}
                    aria-describedby={ids.describedBy}
                    className={cx(controlClasses(Boolean(error)), 'appearance-none pr-10')}
                >
                    <option value="">{placeholder}</option>
                    {groups.map((group) => (
                        <optgroup key={group.label} label={group.label}>
                            {group.options.map((option) => (
                                <option key={option.value} value={option.value}>
                                    {option.label}
                                </option>
                            ))}
                        </optgroup>
                    ))}
                </select>
                <span className="pointer-events-none absolute inset-y-0 right-3 flex items-center text-fg-muted">
                    <Icon name="chevron-down" size={16} strokeWidth={2.2} />
                </span>
            </div>
        </Field>
    );
}

/** "Urgencia": the wizard's four choices as compact radio cards, two per row. */
export function UrgencyPicker({
    id,
    today,
    value,
    onChange,
    error,
}: {
    id: string;
    today: string;
    value: Urgency | null;
    onChange: (value: Urgency) => void;
    error?: string;
}) {
    const name = useId();
    const errorId = `${id}-error`;
    return (
        <fieldset id={id} aria-describedby={error ? errorId : undefined} className="@container flex min-w-0 flex-col gap-2">
            <legend className="mb-2 text-[13px] font-semibold">Urgencia</legend>
            <div className="grid grid-cols-1 gap-1.5 @[18rem]:grid-cols-2">
                {urgencyOptions(today).map((option) => (
                    <label
                        key={option.value}
                        className={cx(
                            'flex min-h-14 cursor-pointer items-center gap-2 rounded-control border bg-surface px-2.5 py-2 transition-colors duration-150',
                            'has-checked:border-2 has-checked:border-fg has-checked:px-[9px] has-focus-visible:outline-2 has-focus-visible:outline-offset-2 has-focus-visible:outline-fg',
                            error ? 'border-status-failure' : 'border-line-strong hover:border-fg-muted',
                        )}
                    >
                        <input
                            type="radio"
                            name={name}
                            value={option.value}
                            checked={value === option.value}
                            onChange={() => onChange(option.value)}
                            className="size-[18px] shrink-0 accent-brand-button"
                        />
                        <span className="flex min-w-0 flex-col text-sm leading-tight">
                            <b>{option.title}</b>
                            <span className="text-xs text-fg-muted">{option.description}</span>
                        </span>
                    </label>
                ))}
            </div>
            {error ? <GroupError id={errorId}>{error}</GroupError> : null}
        </fieldset>
    );
}

/** "Talla · esfuerzo de TI": segmented radios with the rising size bars (same look as the evaluation). */
export function SizePicker({ id, value, onChange, error }: { id: string; value: EffortSize | null; onChange: (value: EffortSize) => void; error?: string }) {
    const name = useId();
    const errorId = `${id}-error`;
    return (
        <fieldset id={id} aria-describedby={error ? errorId : undefined} className="flex min-w-0 flex-col gap-2">
            <legend className="mb-2 text-[13px] font-semibold">Talla · esfuerzo de TI</legend>
            <div className={cx('grid grid-cols-4 gap-1 rounded-control bg-subtle p-[3px]', error && 'outline-2 outline-status-failure')}>
                {SIZES.map((size) => (
                    <label
                        key={size}
                        className="flex h-11 cursor-pointer items-center justify-center gap-1.5 rounded-chip text-sm font-semibold text-fg-soft transition-colors duration-150 has-checked:bg-surface has-checked:font-bold has-checked:text-fg has-checked:shadow-[0_1px_2px_rgb(23_25_28/0.14)] has-focus-visible:outline-2 has-focus-visible:outline-fg"
                    >
                        <input type="radio" name={name} value={size} checked={value === size} onChange={() => onChange(size)} className="sr-only" />
                        {size}
                        <SizeBars size={size} />
                    </label>
                ))}
            </div>
            {error ? <GroupError id={errorId}>{error}</GroupError> : null}
        </fieldset>
    );
}

const number = (value: number) => value.toLocaleString('es-CO', { maximumFractionDigits: 1 });

/** Team hours per week (wizard "Cuánto tiempo" card). `invalid` = hours or people need fixing. */
export function TeamHoursCard({ hours, people, invalid }: { hours: number | null; people: number | null; invalid: boolean }) {
    const total = !invalid && hours !== null && people !== null ? hours * people : null;
    const peopleText = people !== null ? `${number(people)} ${people === 1 ? 'persona' : 'personas'}` : '— personas';
    return (
        <div
            aria-live="polite"
            className={cx(
                'flex items-center justify-between gap-3 rounded-[14px] px-4 py-3.5',
                total !== null ? 'bg-fg text-on-fg' : 'bg-subtle text-fg-soft',
            )}
        >
            {total !== null ? (
                <>
                    <span className="flex flex-col gap-0.5">
                        <span className="text-[13px] opacity-80">
                            {number(hours ?? 0)} h × {peopleText}
                        </span>
                        <b className="text-sm">horas a la semana del equipo</b>
                    </span>
                    <span className="tabular font-display text-4xl leading-none font-bold tracking-[-0.02em] whitespace-nowrap">{number(total)} h</span>
                </>
            ) : invalid ? (
                <>
                    <span className="flex flex-col gap-0.5">
                        <span className="text-[13px]">— × {peopleText}</span>
                        <b className="text-sm text-fg">Corrige las horas para calcular</b>
                    </span>
                    <span className="tabular font-display text-4xl leading-none font-bold tracking-[-0.02em] whitespace-nowrap">— h</span>
                </>
            ) : (
                <span className="text-sm">Escribe las horas y las personas para ver el total del equipo.</span>
            )}
        </div>
    );
}

/** Suggested priority (lib/priority) and the "Prioridad final" override. */
export function PriorityBox({
    id,
    live,
    explanation,
    value,
    onChange,
    onReset,
}: {
    id: string;
    live: number | null;
    explanation: string;
    /** Shown value: the override, else the suggestion. */
    value: number | null;
    onChange: (value: number | null) => void;
    /** "Usar la sugerida" when the override differs. */
    onReset?: () => void;
}) {
    const hintId = `${id}-hint`;
    return (
        <div className="grid grid-cols-[auto_minmax(0,1fr)] items-center gap-x-3.5 gap-y-3 rounded-[14px] border border-line px-3.5 py-3 sm:grid-cols-[auto_minmax(0,1fr)_96px]">
            <span aria-live="polite" className={cx('tabular min-w-12 text-center font-display text-[40px] leading-none font-bold tracking-[-0.02em]', live === null && 'text-fg-muted')}>
                <span className="sr-only">Prioridad sugerida: </span>
                {live ?? '—'}
            </span>
            <span className="flex min-w-0 flex-col gap-0.5 text-[13px] leading-snug">
                <b className="text-sm">Prioridad sugerida</b>
                <span className="text-fg-soft">{explanation}</span>
            </span>
            <div className="col-span-2 flex flex-col gap-1 sm:col-span-1">
                <label htmlFor={id} className="text-xs font-semibold">
                    Prioridad final
                </label>
                <input
                    id={id}
                    type="number"
                    inputMode="numeric"
                    min={0}
                    max={100}
                    value={value ?? ''}
                    aria-describedby={hintId}
                    onChange={(event) =>
                        onChange(event.target.value === '' ? null : Math.max(0, Math.min(100, Math.round(Number(event.target.value)))))
                    }
                    className="tabular h-11 w-full rounded-control border border-line-strong bg-surface px-3 text-center text-base font-bold text-fg"
                />
            </div>
            <p id={hintId} className="col-span-2 flex flex-wrap items-center gap-x-2 text-xs text-fg-muted sm:col-span-3">
                Si la cambias, queda en el historial con tu nombre.
                {onReset && live !== null ? (
                    <button type="button" onClick={onReset} className="min-h-11 font-semibold text-fg-soft underline underline-offset-2 hover:text-fg">
                        Usar la sugerida ({live})
                    </button>
                ) : null}
            </p>
        </div>
    );
}

/** "Horas por persona": one decimal box per IT person and the live total. */
export function EstimateRows({
    id,
    team,
    hours,
    total,
    onChange,
    error,
}: {
    id: string;
    team: PersonRef[];
    hours: Record<number, string>;
    total: number;
    onChange: (personId: number, value: string) => void;
    error?: string;
}) {
    const titleId = `${id}-title`;
    const errorId = `${id}-error`;
    return (
        <div id={id} role="group" aria-labelledby={titleId} aria-describedby={error ? errorId : undefined} className="flex flex-col gap-2">
            <div className="flex items-baseline justify-between gap-3 text-[13px] font-semibold">
                <span id={titleId}>Horas por persona</span>
                <span aria-live="polite" className="tabular text-fg-soft">
                    Total <b className="text-fg">{number(total)} h</b>
                </span>
            </div>
            {team.map((person) => {
                const inputId = `${id}-${person.id}`;
                return (
                    <div key={person.id} className="grid grid-cols-[minmax(0,1fr)_96px] items-center gap-2.5">
                        <span className="flex min-w-0 flex-col">
                            <b className="truncate text-sm">{person.name}</b>
                            {person.job_title ? <span className="truncate text-xs text-fg-muted">{person.job_title}</span> : null}
                        </span>
                        <label htmlFor={inputId} className="sr-only">
                            Horas de {person.name}
                        </label>
                        <input
                            id={inputId}
                            inputMode="decimal"
                            placeholder="0"
                            value={hours[person.id] ?? ''}
                            aria-invalid={error ? true : undefined}
                            aria-describedby={error ? errorId : undefined}
                            onChange={(event) => onChange(person.id, event.target.value)}
                            className={cx(controlClasses(Boolean(error)), 'tabular text-center font-bold')}
                        />
                    </div>
                );
            })}
            {error ? <GroupError id={errorId}>{error}</GroupError> : null}
        </div>
    );
}

/** "Quién aprueba": approvers as person cards with a checkbox. */
export function ApproverPicker({
    id,
    approvers,
    selected,
    onToggle,
    error,
}: {
    id: string;
    approvers: PersonRef[];
    selected: number[];
    onToggle: (personId: number, checked: boolean) => void;
    error?: string;
}) {
    const errorId = `${id}-error`;
    return (
        <fieldset id={id} aria-describedby={error ? errorId : undefined} className="flex min-w-0 flex-col gap-2">
            <legend className="mb-2 text-[13px] font-semibold">Aprobadores de proyectos</legend>
            {approvers.map((approver) => (
                <label
                    key={approver.id}
                    className={cx(
                        'flex min-h-14 cursor-pointer items-center gap-3 rounded-control border px-3 py-1.5 has-focus-visible:outline-2 has-focus-visible:outline-offset-2 has-focus-visible:outline-fg',
                        error ? 'border-status-failure' : 'border-line-strong hover:border-fg-muted',
                    )}
                >
                    <input
                        type="checkbox"
                        checked={selected.includes(approver.id)}
                        onChange={(event) => onToggle(approver.id, event.target.checked)}
                        className="size-[18px] shrink-0 accent-brand-button"
                    />
                    <Avatar name={approver.name} src={approver.avatar_url ?? null} size="sm" tone="strong" />
                    <span className="flex min-w-0 flex-col">
                        <b className="truncate text-sm">{approver.name}</b>
                        {approver.job_title ? <span className="truncate text-xs text-fg-muted">{approver.job_title}</span> : null}
                    </span>
                </label>
            ))}
            {error ? <GroupError id={errorId}>{error}</GroupError> : null}
        </fieldset>
    );
}

const OUTCOME_ICONS: Record<OutcomeLine['key'], { icon: IconName; tone: string }> = {
    request: { icon: 'inbox', tone: 'bg-subtle text-fg-soft' },
    approved: { icon: 'check', tone: 'bg-status-active-bg text-status-active' },
    approval: { icon: 'hourglass', tone: 'bg-status-maintenance-bg text-status-maintenance' },
    project: { icon: 'code', tone: 'bg-status-scheduled-bg text-status-scheduled' },
    team: { icon: 'employees', tone: 'bg-subtle text-fg-soft' },
    later: { icon: 'calendar', tone: 'bg-subtle text-fg-soft' },
};

/** "Qué pasa al crear / al enviar": numbered list with an icon per step. */
export function OutcomeList({ lines }: { lines: OutcomeLine[] }) {
    return (
        <ol className="flex flex-col gap-3.5">
            {lines.map((line) => {
                const { icon, tone } = OUTCOME_ICONS[line.key];
                return (
                    <li key={line.key} className="flex items-start gap-3">
                        <span aria-hidden="true" className={cx('flex size-8 shrink-0 items-center justify-center rounded-chip', tone)}>
                            <Icon name={icon} size={16} />
                        </span>
                        <span className="flex min-w-0 flex-col gap-0.5 text-sm leading-snug">
                            <b>{line.title}</b>
                            <span className="text-[13px] leading-normal text-fg-soft">
                                {line.detail}
                                {line.status ? (
                                    <>
                                        {line.detail ? ' ' : null}
                                        <StatusChip status={line.status} size="sm" className="align-middle" />
                                    </>
                                ) : null}
                                {line.detailEnd ? ` ${line.detailEnd}` : null}
                            </span>
                        </span>
                    </li>
                );
            })}
        </ol>
    );
}
