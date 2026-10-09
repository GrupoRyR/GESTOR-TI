import { useForm } from '@inertiajs/react';
import { useId, useState, type FormEvent } from 'react';
import { toTimelineItem } from '@/Components/ai/requests/requestView';
import { Avatar } from '@/Components/ui/Avatar';
import { Button } from '@/Components/ui/Button';
import { CompanyMark } from '@/Components/ui/CompanyMark';
import { ConfirmDialog } from '@/Components/ui/Dialog';
import { Icon } from '@/Components/ui/Icon';
import { SizeTag } from '@/Components/ui/SizeTag';
import { STATUS_ICONS, StatusChip } from '@/Components/ui/StatusChip';
import { Timeline } from '@/Components/ui/Timeline';
import { cx } from '@/Components/ui/utils';
import { URGENCY_LABELS } from '@/Components/ai/requests/EvaluationPanel';
import { formatCOP, formatShortDate, formatTime } from '@/lib/format';
import { route } from '@/lib/route';
import type { PersonRef } from '@/types/ai';
import type { ApprovalDetail } from '@/types/pages/ai-triage';
import type { Company } from '@/types/shared';
import { firstName, paybackText } from './triage';

const number = (value: number) => value.toLocaleString('es-CO', { maximumFractionDigits: 1 });

/** "72 h · $ 11.500.000 · entrega 28 nov" (no cost without `ai.costs.view`). */
export function estimateLine(hours: number, cost: number | null, due: string, separator = ' · '): string {
    return [`${number(hours)} h`, cost !== null ? formatCOP(cost) : null, `entrega ${formatShortDate(due)}`].filter(Boolean).join(separator);
}

/** Other approvers, for "Basta con una aprobación: la tuya o la de …". */
export function otherApprovers(approvers: PersonRef[], viewerId: number): string {
    const names = approvers.filter((person) => person.id !== viewerId).map((person) => person.name);
    if (names.length === 0) return '';
    return names.length === 1 ? names[0]! : `${names.slice(0, -1).join(', ')} o ${names[names.length - 1]}`;
}

export interface ApprovalSummaryCardProps {
    detail: ApprovalDetail;
    company: Company | undefined;
    canViewCosts: boolean;
    /** h1 on the phone page, h2 inside the desktop list page. */
    headingLevel: 1 | 2;
}

/** What is being approved: problem, estimate, hours per person, hours saved, return and evaluation. */
export function ApprovalSummaryCard({ detail, company, canViewCosts, headingLevel }: ApprovalSummaryCardProps) {
    const { request, estimate, evaluation } = detail;
    const Heading = headingLevel === 1 ? 'h1' : 'h2';
    const cost = canViewCosts ? estimate.total_cost : null;

    return (
        <section aria-label={request.key} className="flex flex-col gap-4 rounded-card border border-line bg-surface p-4 md:p-5">
            <div className="flex flex-wrap items-center gap-2">
                {company ? <CompanyMark company={company} size={22} /> : null}
                <b className="tabular text-sm">{request.key}</b>
                <StatusChip status={request.status} size="sm" />
            </div>
            <div className="flex flex-col gap-1.5">
                <Heading className="font-display text-[22px] leading-tight font-bold tracking-[-0.02em] md:text-[26px]">{request.title}</Heading>
                <p className="max-w-[64ch] text-[15px] leading-normal text-fg-soft">{request.problem}</p>
                <p className="text-[13px] text-fg-muted">
                    {request.area.name}
                    {company ? `, ${company.short_name}` : ''} · pidió {request.requester.name}
                    {request.requester.job_title ? `, ${request.requester.job_title}` : ''}
                </p>
            </div>

            <dl className={cx('grid gap-px overflow-hidden rounded-control border border-line bg-line', 'grid-cols-2')}>
                <div className="flex flex-col gap-0.5 bg-surface p-3">
                    <dt className="text-xs text-fg-muted">Horas de TI</dt>
                    <dd className="tabular font-display text-xl font-bold">{number(estimate.hours_total)} h</dd>
                </div>
                {cost !== null ? (
                    <div className="flex flex-col gap-0.5 bg-surface p-3">
                        <dt className="text-xs text-fg-muted">Costo</dt>
                        <dd className="tabular font-display text-xl font-bold">{formatCOP(cost)}</dd>
                    </div>
                ) : null}
                <div className={cx('flex flex-col gap-0.5 bg-surface p-3', cost !== null && 'col-span-2')}>
                    <dt className="flex items-center gap-1.5 text-xs text-fg-muted">
                        <Icon name="calendar" size={13} />
                        Entrega estimada
                    </dt>
                    <dd className="flex flex-wrap items-baseline gap-x-3">
                        <b className="font-display text-xl">{formatShortDate(estimate.due_date)}</b>
                        {canViewCosts && estimate.external_costs !== null ? (
                            <span className="tabular text-xs text-fg-muted">Costos externos: {formatCOP(estimate.external_costs)}</span>
                        ) : null}
                    </dd>
                </div>
            </dl>

            <section aria-label="Horas por persona" className="flex flex-col gap-2">
                <h3 className="text-sm font-bold">Horas por persona</h3>
                <ul className="flex flex-col gap-2">
                    {estimate.hours_by_person.map((row) => (
                        <li key={row.person.id} className="flex items-center gap-2.5 text-sm">
                            <Avatar name={row.person.name} size="sm" tone="strong" />
                            <span className="flex min-w-0 flex-1 flex-col">
                                <b className="truncate">{row.person.name}</b>
                                {row.person.job_title ? <span className="truncate text-xs text-fg-muted">{row.person.job_title}</span> : null}
                            </span>
                            <b className="tabular">{number(row.hours)} h</b>
                        </li>
                    ))}
                </ul>
            </section>

            {detail.hours_saved_per_week !== null ? (
                <div className="flex items-center justify-between gap-3 border-t border-line pt-3 text-sm">
                    <span className="flex flex-col">
                        <b>Horas que ahorra</b>
                        {request.hours_per_week !== null && request.people !== null ? (
                            <span className="text-xs text-fg-muted">
                                {number(request.hours_per_week)} h/sem × {request.people} {request.people === 1 ? 'persona' : 'personas'} de {request.area.name}
                            </span>
                        ) : null}
                    </span>
                    <b className="tabular font-display text-lg">{number(detail.hours_saved_per_week)} h/sem</b>
                </div>
            ) : null}

            {canViewCosts ? (
                <p className="flex items-start gap-2 rounded-control bg-ground p-3 text-[13px] leading-snug text-fg-soft">
                    <Icon name="trend" size={16} className="mt-px" />
                    <span>
                        <b className="text-fg">Retorno: </b>
                        {paybackText(detail.payback_months, request.area.name)}
                    </span>
                </p>
            ) : null}

            <div className="flex flex-wrap items-center gap-2.5 border-t border-line pt-3 text-sm">
                <Avatar name={evaluation.by.name} size="sm" tone="strong" />
                <span className="flex min-w-0 flex-1 flex-col">
                    <b>Evaluó {evaluation.by.name}</b>
                    <span className="text-xs text-fg-muted">
                        {formatShortDate(evaluation.at)}
                        {evaluation.urgency ? ` · urgencia ${URGENCY_LABELS[evaluation.urgency].toLowerCase()}` : ''}
                    </span>
                </span>
                <SizeTag size={evaluation.size} />
                <span className="flex items-baseline gap-1">
                    <b className="tabular font-display text-xl">{evaluation.priority}</b>
                    <span className="text-xs text-fg-muted">prioridad</span>
                </span>
            </div>
        </section>
    );
}

export function ApprovalHistory({ detail }: { detail: ApprovalDetail }) {
    return (
        <section aria-labelledby="historial-t" className="flex flex-col gap-3 rounded-card border border-line bg-surface p-4 md:p-5">
            <h2 id="historial-t" className="text-base font-bold">
                Historial de esta solicitud
            </h2>
            <Timeline items={detail.history.map((event) => toTimelineItem(event, STATUS_ICONS))} label={`Historial de ${detail.request.key}`} />
        </section>
    );
}

export interface DecisionFormProps {
    requestKey: string;
    approvers: PersonRef[];
    viewerId: number;
}

/** Comment + Aprobar / Rechazar. The comment is required to reject; rejecting asks once more. */
export function DecisionForm({ requestKey, approvers, viewerId }: DecisionFormProps) {
    const form = useForm<{ comment: string }>({ comment: '' });
    const [confirmReject, setConfirmReject] = useState(false);
    const fieldId = useId();
    const others = otherApprovers(approvers, viewerId);

    const approve = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        form.transform((data) => ({ comment: data.comment.trim() || null }));
        form.post(route('ai.approvals.approve', { request: requestKey }), { preserveScroll: true });
    };

    const askReject = () => {
        if (!form.data.comment.trim()) {
            form.setError('comment', 'Escribe por qué la rechazas: le llega a quien la pidió.');
            document.getElementById(fieldId)?.focus();
            return;
        }
        setConfirmReject(true);
    };

    const reject = () => {
        form.transform((data) => ({ comment: data.comment.trim() }));
        form.post(route('ai.approvals.reject', { request: requestKey }), { preserveScroll: true, onFinish: () => setConfirmReject(false) });
    };

    return (
        <form onSubmit={approve} className="flex flex-col gap-3 rounded-card border border-line bg-surface p-4 md:p-5">
            <div className="flex flex-col gap-1.5">
                <label htmlFor={fieldId} className="text-sm font-semibold">
                    Comentario <span className="font-normal text-fg-muted">· obligatorio para rechazar</span>
                </label>
                <textarea
                    id={fieldId}
                    rows={3}
                    value={form.data.comment}
                    placeholder="Escribe por qué apruebas o rechazas"
                    aria-invalid={form.errors.comment ? true : undefined}
                    aria-describedby={form.errors.comment ? `${fieldId}-error` : undefined}
                    onChange={(event) => {
                        form.setData('comment', event.target.value);
                        if (form.errors.comment) form.clearErrors('comment');
                    }}
                    className={cx(
                        'w-full resize-y rounded-control border bg-surface px-3.5 py-2.5 text-base text-fg placeholder:text-fg-muted md:text-sm',
                        form.errors.comment ? 'border-status-failure' : 'border-line-strong',
                    )}
                />
                {form.errors.comment ? (
                    <p id={`${fieldId}-error`} className="flex items-center gap-1.5 text-[13px] font-semibold text-status-failure">
                        <Icon name="alert-triangle" size={14} />
                        {form.errors.comment}
                    </p>
                ) : null}
            </div>
            <div className="grid grid-cols-2 gap-2">
                <Button type="submit" variant="success" size="lg" icon="check" loading={form.processing && !confirmReject}>
                    Aprobar
                </Button>
                <Button variant="danger" size="lg" icon="x" onClick={askReject}>
                    Rechazar
                </Button>
            </div>
            <p className="text-xs leading-snug text-fg-muted">
                Tu decisión queda registrada con fecha, hora y comentario.
                {others ? ` Basta con una aprobación: la tuya o la de ${others}.` : ''}
            </p>
            <ConfirmDialog
                open={confirmReject}
                onClose={() => setConfirmReject(false)}
                onConfirm={reject}
                processing={form.processing}
                title={`¿Rechazar ${requestKey}?`}
                description={`No se hace. Quien la pidió y TI reciben tu comentario: “${form.data.comment.trim()}”`}
                confirmLabel="Rechazar"
            />
        </form>
    );
}

export interface DoneViewProps {
    detail: ApprovalDetail;
    result: 'approved' | 'rejected';
    at: string;
    comment: string | null;
    viewerName: string;
    canViewCosts: boolean;
    wip: { in_development: number; limit: number };
}

/** After deciding (B-ApprovalDone): what happens next and how the Telegram chat confirms it. */
export function DoneView({ detail, result, at, comment, viewerName, canViewCosts, wip }: DoneViewProps) {
    const { request, estimate, approval } = detail;
    const approved = result === 'approved';
    const requester = firstName(request.requester.name);
    const team = estimate.hours_by_person.map((row) => firstName(row.person.name));
    const leader = team[0] ?? 'TI';
    const line = estimateLine(estimate.hours_total, canViewCosts ? estimate.total_cost : null, estimate.due_date);
    const time = formatTime(at);
    const notify = [requester, ...team].join(', ').replace(/, ([^,]*)$/, ' y $1');

    const steps = approved
        ? [
              { title: `Ya les avisamos a ${requester} y a TI`, detail: `${notify} lo reciben por correo y Telegram${comment ? ', con tu comentario' : ''}.` },
              { title: `${leader} arma el plan`, detail: `Tareas, responsables y fechas, contra las ${number(estimate.hours_total)} h aprobadas.` },
              {
                  title: wip.in_development >= wip.limit ? 'Empieza cuando haya cupo' : 'Empieza pronto',
                  detail: `Hoy hay ${wip.in_development} de ${wip.limit} proyectos en desarrollo. Si pasa del 20 % del costo, vuelve a ti.`,
              },
          ]
        : [
              { title: `Ya les avisamos a ${requester} y a TI`, detail: `${notify} reciben tu comentario como motivo.` },
              { title: 'La solicitud queda Rechazada', detail: `${requester} la ve cerrada en Mis solicitudes; puede pedirla de nuevo si algo cambia.` },
          ];

    return (
        <div className="flex flex-col gap-5">
            <div className="flex flex-col items-start gap-3">
                <span
                    aria-hidden="true"
                    className={cx(
                        'flex size-14 items-center justify-center rounded-icon',
                        approved ? 'bg-status-active-bg text-status-active' : 'bg-status-failure-bg text-status-failure',
                    )}
                >
                    <Icon name={approved ? 'check-circle' : 'x-circle'} size={28} />
                </span>
                <StatusChip status={approved ? 'approved' : 'rejected'} size="sm" />
                <h1 className="font-display text-[28px] leading-tight font-bold tracking-[-0.02em] md:text-[34px]">
                    {approved ? 'Aprobaste' : 'Rechazaste'} {request.key}
                </h1>
                <p className="text-[15px] leading-normal text-fg-soft">
                    {request.title}. {estimateLine(estimate.hours_total, canViewCosts ? estimate.total_cost : null, estimate.due_date, ', ')}. Hoy, {time}
                </p>
                {comment ? <p className="rounded-control border-l-[3px] border-line-strong bg-surface px-3 py-2 text-sm text-fg-soft">“{comment}”</p> : null}
            </div>

            <section aria-labelledby="sigue-t" className="flex flex-col gap-3 rounded-card border border-line bg-surface p-4 md:p-5">
                <h2 id="sigue-t" className="text-base font-bold">
                    Qué sigue
                </h2>
                <ol className="flex flex-col gap-3">
                    {steps.map((step, index) => (
                        <li key={step.title} className="flex gap-3">
                            <span aria-hidden="true" className="tabular flex size-7 shrink-0 items-center justify-center rounded-full bg-subtle text-[13px] font-bold">
                                {index + 1}
                            </span>
                            <span className="flex flex-col gap-0.5 text-sm leading-snug">
                                <b>{step.title}</b>
                                <span className="text-fg-soft">{step.detail}</span>
                            </span>
                        </li>
                    ))}
                </ol>
            </section>

            <Button href={route('ai.approvals.index')} size="lg" fullWidth>
                Ver en el Gestor
            </Button>

            <section aria-label="Así se ve en Telegram" className="flex flex-col gap-2">
                <h2 className="text-[13px] font-semibold text-fg-muted">Así se ve en Telegram</h2>
                <div className="overflow-hidden rounded-card border border-line bg-surface">
                    <div className="flex items-center gap-2.5 border-b border-line px-3.5 py-2.5">
                        <span aria-hidden="true" className="flex size-9 items-center justify-center rounded-full bg-brand-button text-brand-button-ink">
                            <Icon name="telegram" size={16} />
                        </span>
                        <span className="flex flex-col text-sm leading-tight">
                            <b>Gestor TI</b>
                            <span className="text-xs text-fg-muted">bot · Telegram</span>
                        </span>
                    </div>
                    <div className="flex flex-col gap-2 bg-subtle p-3.5 text-sm leading-snug">
                        <div className="max-w-[88%] self-start rounded-[14px] rounded-bl-[4px] bg-surface px-3.5 py-2.5">
                            {approval.requested_by.name} te pide aprobar{' '}
                            <b>
                                {request.key} · {line}.
                            </b>
                            <span
                                className={cx(
                                    'mt-2 flex items-center gap-1.5 rounded-[10px] px-2.5 py-1.5 text-[13px] font-bold',
                                    approved ? 'bg-status-active-bg text-status-active' : 'bg-status-failure-bg text-status-failure',
                                )}
                            >
                                <Icon name={approved ? 'check' : 'x'} size={14} strokeWidth={2.6} />
                                {approved ? 'Aprobada' : 'Rechazada'} por ti · {time}
                            </span>
                        </div>
                        <div className="max-w-[88%] self-start rounded-[14px] rounded-bl-[4px] bg-surface px-3.5 py-2.5">
                            {approved
                                ? `Listo, ${firstName(viewerName)}. Quedó aprobada ${request.key}${comment ? ' con tu comentario' : ''}. Te aviso si el costo pasa del 80 %.`
                                : `Listo, ${firstName(viewerName)}. Quedó rechazada ${request.key} y ${requester} recibe tu comentario.`}
                            <span className="mt-1 block text-right text-[11px] text-fg-muted">{time}</span>
                        </div>
                    </div>
                </div>
            </section>
        </div>
    );
}
