import { useForm } from '@inertiajs/react';
import { useId, useState, type FormEvent, type ReactNode } from 'react';
import { firstName, teamHours, type DueState } from '@/Components/ai/triage/triage';
import { Alert } from '@/Components/ui/Alert';
import { Button } from '@/Components/ui/Button';
import { Icon } from '@/Components/ui/Icon';
import { Select } from '@/Components/ui/Select';
import { SizeSuggestion, SizeSuggestionRow } from '@/Components/suggestions/SizeSuggestion';
import { sizeSuggestionIdOnSave } from '@/Components/suggestions/requestSuggestions';
import { cx } from '@/Components/ui/utils';
import { formatCOP } from '@/lib/format';
import { suggestedPriority, type EffortSize, type Urgency } from '@/lib/priority';
import { route } from '@/lib/route';
import type { RequestDetail } from '@/types/ai';
import type { Evaluation, EvaluationPayload, RiskLevel } from '@/types/pages/ai-triage';
import type { Suggestion } from '@/types/suggestions';
import type { RequestAction } from './actions/RequestActionDialogs';

export const SIZES: EffortSize[] = ['S', 'M', 'L', 'XL'];
const SIZE_LEVEL: Record<EffortSize, number> = { S: 1, M: 2, L: 3, XL: 4 };

export const URGENCY_LABELS: Record<Urgency, string> = { low: 'Baja', medium: 'Media', high: 'Alta', urgent: 'Urgente' };

export const RISKS: { value: RiskLevel; label: string }[] = [
    { value: 'none', label: 'Ninguno' },
    { value: 'low', label: 'Bajo: reprocesos internos' },
    { value: 'medium', label: 'Medio: errores en datos' },
    { value: 'high', label: 'Alto: ventas perdidas o legal' },
];

/** Average weeks in a month, to turn weekly hours into a monthly amount. */
const WEEKS_PER_MONTH = 52 / 12;

const number = (value: number) => value.toLocaleString('es-CO', { maximumFractionDigits: 1 });

export function SizeBars({ size }: { size: EffortSize }) {
    return (
        <span aria-hidden="true" className="flex items-end gap-0.5">
            {[5, 8, 11, 14].map((height, index) => (
                <span key={height} className={cx('w-[3px]', index < SIZE_LEVEL[size] ? 'bg-current' : 'bg-line-strong')} style={{ height }} />
            ))}
        </span>
    );
}

interface SegmentOption<V extends string> {
    value: V;
    label: string;
    extra?: ReactNode;
}

/** Native radios drawn as a segmented control: arrows move the choice, Tab leaves the group. */
function RadioSegments<V extends string>({
    legend,
    options,
    value,
    onChange,
    hint,
}: {
    legend: string;
    options: SegmentOption<V>[];
    value: V | null;
    onChange: (value: V) => void;
    hint?: ReactNode;
}) {
    const name = useId();
    return (
        <fieldset className="flex flex-col gap-1.5">
            <legend className="mb-1.5 text-xs font-semibold text-fg-muted">{legend}</legend>
            <div className="grid gap-0.5 rounded-control bg-subtle p-[3px]" style={{ gridTemplateColumns: `repeat(${options.length}, minmax(0, 1fr))` }}>
                {options.map((option) => (
                    <label
                        key={option.value}
                        className="flex h-11 cursor-pointer items-center justify-center gap-1.5 rounded-chip text-[13px] font-semibold text-fg-soft transition-colors duration-150 has-checked:bg-surface has-checked:font-bold has-checked:text-fg has-checked:shadow-[0_1px_2px_rgb(23_25_28/0.12)] has-focus-visible:outline-2 has-focus-visible:outline-fg"
                    >
                        <input type="radio" name={name} value={option.value} checked={value === option.value} onChange={() => onChange(option.value)} className="sr-only" />
                        {option.label}
                        {option.extra}
                    </label>
                ))}
            </div>
            {hint ? <p className="text-xs text-fg-muted">{hint}</p> : null}
        </fieldset>
    );
}

export interface EvaluationPanelProps {
    request: RequestDetail;
    evaluation: Evaluation;
    areaHourValue: number | null;
    canViewCosts: boolean;
    /** Pending first response (null once answered). */
    firstResponse: DueState | null;
    onAction: (action: RequestAction) => void;
    /** Form id, so the phone action bar can submit it. */
    formId?: string;
    /** Stage D · AI size suggestion under the picker ("Usar M" only sets the picker). */
    sizeSuggestion?: Suggestion<'size'> | null;
    sizeSuggestionActions?: { onDiscard?: () => void; onRegenerate?: () => void; onCancel?: () => void; onRetry?: () => void };
    now?: string;
}

/**
 * "Evaluación": impact, size and urgency give a live suggested priority
 * (lib/priority); IT can override the final one. Saving counts as the
 * first response. Actions below open the dialogs.
 */
export function EvaluationPanel({
    request,
    evaluation,
    areaHourValue,
    canViewCosts,
    firstResponse,
    onAction,
    formId,
    sizeSuggestion = null,
    sizeSuggestionActions = {},
    now,
}: EvaluationPanelProps) {
    const titleId = useId();
    const finalId = useId();
    const name = firstName(request.requester.name);
    const [usedSize, setUsedSize] = useState<{ id: number; size: EffortSize } | null>(null);
    const [touched, setTouched] = useState(evaluation.priority_final !== null && evaluation.priority_final !== evaluation.priority_suggested);
    const form = useForm<Omit<EvaluationPayload, 'priority_suggested'> & { priority_final: number | null }>({
        size: evaluation.size,
        urgency: evaluation.urgency ?? request.urgency,
        risk: evaluation.impact.risk,
        priority_final: evaluation.priority_final,
    });

    const hours = evaluation.impact.hours_per_week;
    const people = evaluation.impact.people;
    const weekly = teamHours(hours, people);
    const live = suggestedPriority({
        hours_per_week: hours ?? 0,
        people: people ?? 0,
        votes: request.votes,
        size: form.data.size,
        urgency: form.data.urgency ?? 'medium',
    });
    const finalValue = touched ? form.data.priority_final : live;

    const money =
        evaluation.impact.money ?? (areaHourValue !== null && weekly !== null ? `${formatCOP(weekly * areaHourValue * WEEKS_PER_MONTH)} al mes` : null);

    const takeSize = (size: EffortSize) => {
        if (!sizeSuggestion) return;
        form.setData('size', size);
        form.clearErrors('size');
        setUsedSize({ id: sizeSuggestion.id, size });
    };

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        if (!form.data.size) {
            form.setError('size', 'Elige la talla.');
            return;
        }
        const sizeSuggestionId = sizeSuggestionIdOnSave(form.data.size, usedSize);
        form.transform((data) => ({ ...data, priority_suggested: live, priority_final: finalValue, size_suggestion_id: sizeSuggestionId }));
        form.put(route('ai.requests.evaluation.update', { request: request.key }), { preserveScroll: true });
    };

    const explanation =
        form.data.size && weekly !== null
            ? `${number(weekly)} h/sem con talla ${form.data.size} y urgencia ${URGENCY_LABELS[form.data.urgency ?? 'medium'].toLowerCase()}.`
            : 'Elige la talla para calcularla.';

    return (
        <form id={formId} onSubmit={submit} aria-labelledby={titleId} className="flex flex-col gap-4 rounded-card border border-line bg-surface p-4 md:p-5">
            <div className="flex items-baseline justify-between gap-3">
                <h2 id={titleId} className="font-display text-xl font-bold tracking-[-0.01em]">
                    Evaluación
                </h2>
                <span aria-live="polite" className="text-xs text-fg-muted">
                    {form.recentlySuccessful ? 'Guardada' : form.isDirty ? 'Sin guardar' : ''}
                </span>
            </div>

            {firstResponse && firstResponse.level !== 'ok' ? (
                <Alert tone={firstResponse.level === 'late' ? 'danger' : 'warning'}>
                    <b>Primera respuesta {firstResponse.label.charAt(0).toLowerCase() + firstResponse.label.slice(1)}.</b> Guardar la evaluación o pedir
                    información a {name} la cumple.
                </Alert>
            ) : null}

            <section aria-label="Impacto" className="flex flex-col gap-2">
                <h3 className="text-xs font-semibold text-fg-muted">Impacto</h3>
                <dl className="flex flex-col divide-y divide-line rounded-control bg-ground px-3.5 text-sm">
                    <div className="flex items-center justify-between gap-3 py-2.5">
                        <dt className="flex flex-col">
                            <span className="text-fg-soft">Horas ahorradas</span>
                            {hours !== null && people !== null ? (
                                <span className="text-xs text-fg-muted">
                                    {number(hours)} h/sem × {people} {people === 1 ? 'persona' : 'personas'}
                                </span>
                            ) : null}
                        </dt>
                        <dd className="tabular font-display text-lg font-bold">{weekly !== null ? `${number(weekly)} h/sem` : 'Sin dato'}</dd>
                    </div>
                    {canViewCosts ? (
                        <div className="flex items-center justify-between gap-3 py-2.5">
                            <dt className="text-fg-soft">Dinero</dt>
                            <dd className="text-right text-[13px]">
                                {money ? (
                                    <b className="tabular text-sm">{money}</b>
                                ) : (
                                    <span className="text-fg-muted">
                                        {request.area.name} no tiene valor de hora ·{' '}
                                        <a href={route('admin.areas.index')} className="font-semibold text-fg underline underline-offset-2">
                                            Agregarlo
                                        </a>
                                    </span>
                                )}
                            </dd>
                        </div>
                    ) : null}
                </dl>
                <Select
                    label="Riesgo que reduce"
                    options={RISKS}
                    value={form.data.risk}
                    onChange={(event) => form.setData('risk', event.target.value as RiskLevel)}
                />
            </section>

            <RadioSegments
                legend="Talla · esfuerzo de TI"
                options={SIZES.map((size) => ({ value: size, label: size, extra: <SizeBars size={size} /> }))}
                value={form.data.size}
                onChange={(size) => {
                    form.setData('size', size);
                    form.clearErrors('size');
                }}
                hint={
                    form.errors.size ? (
                        <span className="flex items-center gap-1.5 font-semibold text-status-failure">
                            <Icon name="alert-triangle" size={13} />
                            {form.errors.size}
                        </span>
                    ) : undefined
                }
            />

            {sizeSuggestion ? (
                usedSize && form.data.size === usedSize.size ? (
                    <p className="-mt-2 flex items-center gap-1.5 text-xs text-fg-muted">
                        <Icon name="sparkle" size={13} />
                        Talla {usedSize.size} de la sugerencia · se guarda con la evaluación
                    </p>
                ) : (
                    <>
                        {sizeSuggestion.state === 'ready' ? (
                            <SizeSuggestionRow suggestion={sizeSuggestion} onUse={takeSize} onDiscard={sizeSuggestionActions.onDiscard} className="-mt-2 md:hidden" />
                        ) : null}
                        <div className={cx('-mt-2', sizeSuggestion.state === 'ready' && 'hidden md:block')}>
                            <SizeSuggestion suggestion={sizeSuggestion} now={now} onUse={takeSize} {...sizeSuggestionActions} />
                        </div>
                    </>
                )
            ) : null}

            <RadioSegments
                legend="Urgencia"
                options={(Object.keys(URGENCY_LABELS) as Urgency[]).map((urgency) => ({ value: urgency, label: URGENCY_LABELS[urgency] }))}
                value={form.data.urgency}
                onChange={(urgency) => form.setData('urgency', urgency)}
                hint={request.urgency ? `${name} la marcó como ${URGENCY_LABELS[request.urgency].toLowerCase()}.` : undefined}
            />

            <div className="flex items-center gap-3.5 rounded-control border border-line p-3.5">
                <span aria-live="polite" className="tabular w-14 shrink-0 text-center font-display text-[34px] leading-none font-bold">
                    <span className="sr-only">Prioridad sugerida: </span>
                    {live ?? '—'}
                </span>
                <span className="flex min-w-0 flex-col gap-0.5 text-[13px] leading-snug">
                    <b className="text-sm">Prioridad sugerida</b>
                    <span className="text-fg-soft">Impacto ÷ esfuerzo, ajustada por urgencia. {explanation}</span>
                </span>
            </div>

            <div className="flex flex-col gap-1.5">
                <label htmlFor={finalId} className="text-sm font-semibold">
                    Prioridad final
                </label>
                <div className="flex items-center gap-2">
                    <input
                        id={finalId}
                        type="number"
                        inputMode="numeric"
                        min={0}
                        max={100}
                        value={finalValue ?? ''}
                        aria-describedby={`${finalId}-hint`}
                        onChange={(event) => {
                            setTouched(true);
                            const value = event.target.value === '' ? null : Math.max(0, Math.min(100, Math.round(Number(event.target.value))));
                            form.setData('priority_final', value);
                        }}
                        className="tabular h-11 w-24 rounded-control border border-line-strong bg-surface px-3 text-base font-bold text-fg"
                    />
                    {touched && live !== null && finalValue !== live ? (
                        <button
                            type="button"
                            onClick={() => {
                                setTouched(false);
                                form.setData('priority_final', null);
                            }}
                            className="h-11 rounded-control px-2 text-[13px] font-semibold text-fg-soft underline underline-offset-2 hover:text-fg"
                        >
                            Usar la sugerida ({live})
                        </button>
                    ) : null}
                </div>
                <p id={`${finalId}-hint`} className="text-xs text-fg-muted">
                    Si la cambias, queda en el historial con tu nombre.
                </p>
            </div>

            <div className="flex flex-col gap-2 border-t border-line pt-4">
                <Button type="submit" fullWidth loading={form.processing}>
                    Guardar evaluación
                </Button>
                <Button variant="secondary" icon="question" fullWidth onClick={() => onAction('ask')}>
                    Pedir información
                </Button>
                <div className="flex flex-col gap-1">
                    <Button variant="secondary" icon="hourglass" fullWidth onClick={() => onAction('approval')}>
                        Mandar a aprobación
                    </Button>
                    <span className="text-center text-xs text-fg-muted">Pide el estimado: horas por persona y fecha</span>
                </div>
                <Button variant="secondary" icon="code" fullWidth onClick={() => onAction('convert')}>
                    Convertir en proyecto
                </Button>
                <Button variant="secondary" icon="x-circle" fullWidth onClick={() => onAction('reject')} className="text-status-failure!">
                    Rechazar con motivo
                </Button>
            </div>
        </form>
    );
}
