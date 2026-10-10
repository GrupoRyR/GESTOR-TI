import { useForm } from '@inertiajs/react';
import { useEffect, type FormEvent } from 'react';
import { Button } from '@/Components/ui/Button';
import { Checkbox } from '@/Components/ui/Checkbox';
import { Drawer } from '@/Components/ui/Drawer';
import { Icon } from '@/Components/ui/Icon';
import { TextArea } from '@/Components/ui/TextArea';
import { TextField } from '@/Components/ui/TextField';
import { cx } from '@/Components/ui/utils';
import { route } from '@/lib/route';
import type { IncidentImpact } from '@/types/ai';
import type { IncidentFormData, IncidentFormSetup, IncidentStorePayload } from '@/types/pages/ai-knowledge';
import type { FormErrors } from '@/types/shared';

const IMPACTS: { value: IncidentImpact; label: string; bars: number }[] = [
    { value: 'low', label: 'Baja', bars: 1 },
    { value: 'medium', label: 'Media', bars: 2 },
    { value: 'high', label: 'Alta', bars: 3 },
    { value: 'critical', label: 'Crítica', bars: 4 },
];

/** Four rising bars, `filled` of them dark: impact is read from the shape and the text, never color. */
function ImpactBars({ filled }: { filled: number }) {
    return (
        <span aria-hidden="true" className="flex h-[19px] items-end gap-[3px]">
            {[7, 11, 15, 19].map((height, index) => (
                <span key={height} style={{ height }} className={cx('w-[5px] rounded-[1px]', index < filled ? 'bg-fg' : 'bg-line-strong')} />
            ))}
        </span>
    );
}

function FieldError({ id, children }: { id: string; children?: string }) {
    if (!children) return null;
    return (
        <p id={id} className="flex items-start gap-1.5 text-[13px] font-semibold text-status-failure">
            <Icon name="alert-triangle" size={14} className="mt-0.5 shrink-0" />
            {children}
        </p>
    );
}

function toPayload(state: IncidentFormData): IncidentStorePayload {
    const resolved = state.status === 'resolved';
    return {
        title: state.title,
        started_at: `${state.started_on}T${state.started_time}`,
        impact: state.impact,
        description: state.description,
        status: state.status,
        ended_at: resolved && state.ended_on !== '' ? `${state.ended_on}T${state.ended_time || '00:00'}` : null,
        resolution: resolved ? state.resolution : null,
        notify_ids: state.notify_ids,
        notify_owner: state.notify_owner,
    };
}

export interface IncidentDrawerProps {
    open: boolean;
    onClose: () => void;
    productSlug: string;
    /** "Toma Pedidos SAP · v2.4.0 · Segurex". */
    productLine: string;
    setup: IncidentFormSetup;
    /** Monitoring off: incidents are only registered by hand. */
    monitoringOff: boolean;
    /** Errors the page arrived with (failed store). */
    serverErrors: FormErrors;
}

/** "Registrar incidente" drawer on the product page (board C-IncidentForm). */
export function IncidentDrawer({ open, onClose, productSlug, productLine, setup, monitoringOff, serverErrors }: IncidentDrawerProps) {
    const form = useForm<IncidentFormData>(setup.defaults);

    useEffect(() => {
        if (Object.keys(serverErrors).length > 0) form.setError(serverErrors as Partial<Record<keyof IncidentFormData, string>>);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    const errors = form.errors as FormErrors;
    const { data } = form;
    const resolved = data.status === 'resolved';

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.transform((state) => toPayload(state) as unknown as IncidentFormData);
        form.post(route('ai.products.incidents.store', { product: productSlug }), {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                onClose();
            },
        });
    };

    const toggleNotify = (id: number, checked: boolean) =>
        form.setData('notify_ids', checked ? [...data.notify_ids, id] : data.notify_ids.filter((item) => item !== id));

    return (
        <Drawer
            open={open}
            onClose={onClose}
            title="Registrar incidente"
            width={480}
            footer={
                <div className="flex w-full flex-wrap items-center justify-between gap-3">
                    <span className="text-xs leading-snug text-fg-muted">
                        Lo registra {setup.reporter.name}
                        <br />
                        Origen: manual
                    </span>
                    <span className="flex gap-2">
                        <Button variant="secondary" onClick={onClose}>
                            Cancelar
                        </Button>
                        <Button type="submit" form="incident-form" loading={form.processing}>
                            Registrar incidente
                        </Button>
                    </span>
                </div>
            }
        >
            <form id="incident-form" onSubmit={submit} noValidate aria-label="Registrar incidente" className="flex flex-col gap-4">
                <p className="-mt-2 text-[13px] text-fg-soft">{productLine}</p>

                {monitoringOff ? (
                    <p className="flex items-start gap-2.5 rounded-control border border-dashed border-line-strong bg-ground p-3 text-[13px] leading-snug text-fg-soft">
                        <Icon name="info" size={16} className="mt-px shrink-0" />
                        Monitoreo aún no activo: los incidentes se registran a mano.
                    </p>
                ) : null}

                <TextField label="Título" value={data.title} error={errors.title} autoComplete="off" onChange={(event) => form.setData('title', event.target.value)} />

                <div className="grid grid-cols-2 gap-3">
                    <TextField
                        label="Inició · fecha"
                        type="date"
                        value={data.started_on}
                        error={errors.started_at}
                        onChange={(event) => form.setData('started_on', event.target.value)}
                    />
                    <TextField label="Inició · hora" type="time" value={data.started_time} onChange={(event) => form.setData('started_time', event.target.value)} />
                </div>

                <fieldset aria-describedby={errors.impact ? 'incident-impact-error' : undefined} className="flex min-w-0 flex-col gap-1.5">
                    <legend className="mb-1.5 text-[13px] font-semibold">Impacto</legend>
                    <div className="grid grid-cols-4 gap-2">
                        {IMPACTS.map((impact) => {
                            const checked = data.impact === impact.value;
                            return (
                                <label
                                    key={impact.value}
                                    className={cx(
                                        'flex min-h-[68px] cursor-pointer flex-col items-center justify-center gap-1.5 rounded-control border bg-surface px-1 text-[13px] font-semibold',
                                        'has-focus-visible:outline-2 has-focus-visible:outline-offset-2 has-focus-visible:outline-fg',
                                        checked ? 'border-2 border-fg' : 'border-line-strong hover:border-fg-muted',
                                    )}
                                >
                                    <input
                                        type="radio"
                                        name="incident-impact"
                                        checked={checked}
                                        onChange={() => form.setData('impact', impact.value)}
                                        className="sr-only"
                                    />
                                    <ImpactBars filled={impact.bars} />
                                    {impact.label}
                                </label>
                            );
                        })}
                    </div>
                    <FieldError id="incident-impact-error">{errors.impact}</FieldError>
                </fieldset>

                <TextArea label="Qué pasa" rows={3} value={data.description} error={errors.description} onChange={(event) => form.setData('description', event.target.value)} />

                <fieldset className="flex min-w-0 flex-col gap-2">
                    <legend className="mb-1.5 text-[13px] font-semibold">Estado</legend>
                    <div className="grid grid-cols-2 gap-2">
                        {(
                            [
                                { value: 'open', label: 'Abierto', icon: 'alert-triangle' },
                                { value: 'resolved', label: 'Resuelto', icon: 'check-circle' },
                            ] as const
                        ).map((option) => {
                            const checked = data.status === option.value;
                            return (
                                <label
                                    key={option.value}
                                    className={cx(
                                        'flex min-h-11 cursor-pointer items-center gap-2.5 rounded-control border bg-surface px-3 text-sm font-semibold',
                                        'has-focus-visible:outline-2 has-focus-visible:outline-offset-2 has-focus-visible:outline-fg',
                                        checked ? 'border-2 border-fg px-[11px]' : 'border-line-strong hover:border-fg-muted',
                                    )}
                                >
                                    <input
                                        type="radio"
                                        name="incident-status"
                                        checked={checked}
                                        onChange={() => form.setData('status', option.value)}
                                        className="size-4 shrink-0 accent-brand-button"
                                    />
                                    <Icon name={option.icon} size={16} className="text-fg-soft" />
                                    {option.label}
                                </label>
                            );
                        })}
                    </div>
                    {resolved ? (
                        <div className="flex flex-col gap-3 rounded-control bg-ground p-3">
                            <div className="grid grid-cols-2 gap-3">
                                <TextField
                                    label="Terminó · fecha"
                                    type="date"
                                    required
                                    value={data.ended_on}
                                    error={errors.ended_at}
                                    onChange={(event) => form.setData('ended_on', event.target.value)}
                                />
                                <TextField label="Terminó · hora" type="time" required value={data.ended_time} onChange={(event) => form.setData('ended_time', event.target.value)} />
                            </div>
                            <TextArea
                                label="Causa y solución"
                                rows={3}
                                required
                                value={data.resolution}
                                error={errors.resolution}
                                hint="Qué lo causó y qué se hizo para arreglarlo."
                                onChange={(event) => form.setData('resolution', event.target.value)}
                            />
                        </div>
                    ) : (
                        <div aria-label="Campos que aparecen al marcar Resuelto" className="flex flex-col gap-1 rounded-control border border-dashed border-line-strong p-3 text-[13px] text-fg-muted">
                            <span>Si marcas Resuelto, aparecen y son obligatorios:</span>
                            <span className="font-semibold text-fg-soft">Terminó · fecha y hora</span>
                            <span className="font-semibold text-fg-soft">Causa y solución</span>
                        </div>
                    )}
                </fieldset>

                <fieldset className="flex min-w-0 flex-col">
                    <legend className="mb-0.5 text-[13px] font-semibold">Avisar a</legend>
                    {setup.notify_people.map((person) => (
                        <Checkbox
                            key={person.id}
                            label={person.name}
                            checked={data.notify_ids.includes(person.id)}
                            onChange={(event) => toggleNotify(person.id, event.target.checked)}
                        />
                    ))}
                    {setup.owner_label !== null ? (
                        <Checkbox
                            label="Dueño"
                            description={<span className={setup.owner_label.startsWith('[') ? 'font-semibold text-status-maintenance' : undefined}>{setup.owner_label}</span>}
                            checked={data.notify_owner}
                            onChange={(event) => form.setData('notify_owner', event.target.checked)}
                        />
                    ) : null}
                    <FieldError id="incident-notify-error">{errors.notify_ids}</FieldError>
                </fieldset>
            </form>
        </Drawer>
    );
}
