import { useForm } from '@inertiajs/react';
import { useEffect, useId, type FormEvent } from 'react';
import { estimateTotals, firstName } from '@/Components/ai/triage/triage';
import { Avatar } from '@/Components/ui/Avatar';
import { Button } from '@/Components/ui/Button';
import { Checkbox } from '@/Components/ui/Checkbox';
import { Dialog } from '@/Components/ui/Dialog';
import { Icon } from '@/Components/ui/Icon';
import { Switch } from '@/Components/ui/Switch';
import { TextField } from '@/Components/ui/TextField';
import { formatCOP } from '@/lib/format';
import { route } from '@/lib/route';
import type { Estimate, PersonRef, RequestSummary } from '@/types/ai';
import type { SendToApprovalPayload, TeamMember } from '@/types/pages/ai-triage';

export interface SendToApprovalDialogProps {
    open: boolean;
    onClose: () => void;
    request: RequestSummary;
    team: TeamMember[];
    approvers: PersonRef[];
    /** Previous estimate to start from (re-sending). */
    estimate?: Estimate | null;
    /** Viewer has `ai.costs.view`: external costs and the total show. */
    canViewCosts: boolean;
    viewerId: number;
}

/** Form state: numbers as typed, so an empty box stays empty. */
interface FormState {
    hours: Record<number, string>;
    external_costs: string;
    due_date: string;
    approver_ids: number[];
    require_all: boolean;
}

/** Hours as typed: "28", "1,5" or "1.5". */
const toHours = (value: string) => {
    const parsed = Number.parseFloat(value.replace(',', '.'));
    return Number.isFinite(parsed) ? parsed : 0;
};

/** Pesos as typed: "$ 380.000" → 380000. */
const toPesos = (value: string) => Number(value.replace(/\D/g, '')) || 0;

const joinNames = (names: string[]) => (names.length <= 1 ? (names[0] ?? '') : `${names.slice(0, -1).join(', ')} y ${names[names.length - 1]}`);

/**
 * "Mandar a aprobación": hours per person, external costs, due date and who
 * approves. Costs and the total only render for `ai.costs.view`.
 */
export function SendToApprovalDialog({ open, onClose, request, team, approvers, estimate, canViewCosts, viewerId }: SendToApprovalDialogProps) {
    const form = useForm<FormState>({ hours: {}, external_costs: '', due_date: '', approver_ids: [], require_all: false });
    const hoursTitleId = useId();

    useEffect(() => {
        if (!open) return;
        form.clearErrors();
        form.setData({
            hours: Object.fromEntries(
                team.map((member) => {
                    const previous = estimate?.hours_by_person.find((row) => row.person.id === member.person.id);
                    return [member.person.id, previous ? String(previous.hours) : ''];
                }),
            ),
            external_costs: estimate?.external_costs ? String(estimate.external_costs) : '0',
            due_date: estimate?.due_date ?? '',
            approver_ids: approvers.map((approver) => approver.id),
            require_all: false,
        });
    }, [open, request.key]);

    const rows = team.map((member) => ({ hours: toHours(form.data.hours[member.person.id] ?? ''), rate: member.hourly_rate }));
    const totals = estimateTotals(rows, canViewCosts ? toPesos(form.data.external_costs) : null);
    const viewerApproves = approvers.some((approver) => approver.id === viewerId);
    const others = approvers.filter((approver) => approver.id !== viewerId && form.data.approver_ids.includes(approver.id));
    const otherNames = joinNames(others.map((other) => firstName(other.name)));

    const toggleApprover = (id: number, checked: boolean) => {
        form.setData('approver_ids', checked ? [...form.data.approver_ids, id] : form.data.approver_ids.filter((value) => value !== id));
        form.clearErrors('approver_ids');
    };

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        const errors: Record<string, string> = {};
        if (totals.hours <= 0) errors.hours = 'Escribe las horas de al menos una persona.';
        if (!form.data.due_date) errors.due_date = 'Elige la fecha estimada de entrega.';
        if (form.data.approver_ids.length === 0) errors.approver_ids = 'Elige al menos un aprobador.';
        if (Object.keys(errors).length > 0) {
            form.setError(errors);
            return;
        }
        form.transform(
            (data): SendToApprovalPayload => ({
                hours: team
                    .map((member) => ({ person_id: member.person.id, hours: toHours(data.hours[member.person.id] ?? '') }))
                    .filter((row) => row.hours > 0),
                external_costs: canViewCosts ? toPesos(data.external_costs) : 0,
                due_date: data.due_date,
                approver_ids: data.approver_ids,
                require_all: data.require_all,
            }),
        );
        form.post(route('ai.requests.approval.store', { request: request.key }), { preserveScroll: true, onSuccess: onClose });
    };

    const meta = [request.key, request.title, request.size ? `talla ${request.size}` : null, request.priority_final !== null ? `prioridad ${request.priority_final}` : null]
        .filter(Boolean)
        .join(' · ');

    return (
        <Dialog
            open={open}
            onClose={onClose}
            onSubmit={submit}
            size="lg"
            title="Mandar a aprobación"
            description={meta}
            actions={
                <>
                    <Button variant="secondary" onClick={onClose}>
                        Cancelar
                    </Button>
                    <Button type="submit" icon="hourglass" loading={form.processing}>
                        Mandar a aprobación
                    </Button>
                </>
            }
        >
            <section aria-labelledby={hoursTitleId} className="flex flex-col gap-2">
                <div className="flex items-baseline justify-between gap-3">
                    <h3 id={hoursTitleId} className="text-sm font-bold">
                        Horas por persona
                    </h3>
                    <span className="tabular text-[13px] text-fg-muted" aria-live="polite">
                        Total {totals.hours.toLocaleString('es-CO')} h
                    </span>
                </div>
                <ul className="flex flex-col divide-y divide-line rounded-control border border-line">
                    {team.map((member) => {
                        const inputId = `${hoursTitleId}-${member.person.id}`;
                        return (
                            <li key={member.person.id} className="flex items-center gap-3 px-3 py-2">
                                <Avatar name={member.person.name} size="sm" tone="strong" />
                                <span className="flex min-w-0 flex-1 flex-col text-sm">
                                    <b className="truncate">{member.person.name}</b>
                                    {member.person.job_title ? <span className="truncate text-xs text-fg-muted">{member.person.job_title}</span> : null}
                                </span>
                                <label htmlFor={inputId} className="sr-only">
                                    Horas de {member.person.name}
                                </label>
                                <input
                                    id={inputId}
                                    inputMode="decimal"
                                    value={form.data.hours[member.person.id] ?? ''}
                                    placeholder="0"
                                    onChange={(event) => {
                                        form.setData('hours', { ...form.data.hours, [member.person.id]: event.target.value });
                                        form.clearErrors('hours' as keyof FormState);
                                    }}
                                    className="tabular h-11 w-20 rounded-control border border-line-strong bg-surface px-3 text-right text-base text-fg md:text-sm"
                                />
                                <span aria-hidden="true" className="w-3 text-sm text-fg-muted">
                                    h
                                </span>
                            </li>
                        );
                    })}
                </ul>
                {form.errors.hours ? (
                    <p className="flex items-center gap-1.5 text-[13px] font-semibold text-status-failure">
                        <Icon name="alert-triangle" size={14} />
                        {form.errors.hours}
                    </p>
                ) : null}
            </section>

            <div className="grid gap-4 sm:grid-cols-2">
                {canViewCosts ? (
                    <TextField
                        label="Costos externos previstos"
                        inputMode="numeric"
                        hint="Licencias, proveedores. En pesos."
                        value={form.data.external_costs}
                        onChange={(event) => form.setData('external_costs', event.target.value)}
                    />
                ) : null}
                <TextField
                    label="Fecha estimada de entrega"
                    type="date"
                    value={form.data.due_date}
                    error={form.errors.due_date}
                    onChange={(event) => {
                        form.setData('due_date', event.target.value);
                        form.clearErrors('due_date');
                    }}
                />
            </div>

            {canViewCosts ? (
                <div className="flex items-center justify-between gap-3 rounded-control bg-ground p-3.5">
                    <span className="flex flex-col">
                        <b className="text-sm">Costo total</b>
                        <span className="flex items-center gap-1.5 text-xs text-fg-muted">
                            <Icon name="eye" size={13} />
                            Lo ves porque tienes Ver costos
                        </span>
                    </span>
                    <span className="tabular font-display text-xl font-bold" aria-live="polite">
                        {totals.cost !== null ? formatCOP(totals.cost) : '—'}
                    </span>
                </div>
            ) : null}

            <fieldset className="flex flex-col">
                <legend className="mb-1 text-sm font-bold">Quién aprueba</legend>
                {approvers.map((approver) => (
                    <Checkbox
                        key={approver.id}
                        label={approver.name}
                        description={approver.job_title ?? undefined}
                        checked={form.data.approver_ids.includes(approver.id)}
                        onChange={(event) => toggleApprover(approver.id, event.target.checked)}
                    />
                ))}
                {form.errors.approver_ids ? (
                    <p className="flex items-center gap-1.5 text-[13px] font-semibold text-status-failure">
                        <Icon name="alert-triangle" size={14} />
                        {form.errors.approver_ids}
                    </p>
                ) : (
                    <p className="text-[13px] text-fg-muted">
                        Basta con que uno apruebe.
                        {otherNames ? ` A ${otherNames} le llega por Telegram con botones para aprobar o rechazar.` : ''}
                    </p>
                )}
            </fieldset>

            {viewerApproves && others.length > 0 ? (
                <Switch
                    checked={form.data.require_all}
                    onChange={(checked) => form.setData('require_all', checked)}
                    label="Si apruebas tú, puedes pedir la del otro"
                    description={`Apagado: con tu aprobación basta. Encendido: también se espera la de ${otherNames}.`}
                    className="rounded-control border border-line px-3.5 py-1.5"
                />
            ) : null}
        </Dialog>
    );
}
