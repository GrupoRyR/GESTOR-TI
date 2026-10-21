import { useForm } from '@inertiajs/react';
import { useEffect, useState, type FormEvent, type MouseEvent } from 'react';
import {
    ApproverPicker,
    EstimateRows,
    FormSection,
    GroupedSelect,
    OutcomeList,
    PriorityBox,
    SizePicker,
    TeamHoursCard,
    UrgencyPicker,
} from '@/Components/ai/proposals/ProposalParts';
import { RISKS, URGENCY_LABELS } from '@/Components/ai/requests/EvaluationPanel';
import { Alert } from '@/Components/ui/Alert';
import { Button } from '@/Components/ui/Button';
import { ChipSelect } from '@/Components/ui/ChipSelect';
import { Icon } from '@/Components/ui/Icon';
import { Select } from '@/Components/ui/Select';
import { StatusChip } from '@/Components/ui/StatusChip';
import { TextArea } from '@/Components/ui/TextArea';
import { TextField } from '@/Components/ui/TextField';
import { GestorLayout } from '@/Layouts/GestorLayout';
import type { RiskLevel } from '@/types/pages/ai-triage';
import {
    buildProposalPayload,
    emptyProposal,
    errorSummary,
    estimateTotal,
    fieldErrors,
    fieldOfError,
    livePriority,
    noticeLine,
    parseDecimal,
    proposalOutcome,
    submitSummary,
    TITLE_MAX,
    validateProposal,
    wipWarning,
    type ProposalForm,
} from '@/lib/proposal';
import { route } from '@/lib/route';
import type { ProposalsCreateProps } from '@/types/pages/ai-proposals';
import type { PageProps } from '@/types/shared';

const FORM_ID = 'propose-form';

/** DOM id of a field (or of the group that holds it). */
const fieldId = (field: string) => `proposal-${field}`;

/** Focus the control of a field, or the first control inside its group. */
function focusField(field: string) {
    const element = document.getElementById(fieldId(field));
    if (!element) return;
    const target = element.matches('input, select, textarea') ? element : element.querySelector<HTMLElement>('input, select, textarea, button');
    target?.focus();
    element.scrollIntoView({ block: 'center' });
}

const number = (value: number) => value.toLocaleString('es-CO', { maximumFractionDigits: 1 });

/**
 * "Proponer proyecto": the wizard questions, the evaluation and the estimate
 * in one form for IT. With `ai.projects.approve` it also creates the project
 * (self-approved); without it the request goes to the approval round.
 * One POST to `ai.proposals.store`; the server redirects.
 */
export default function ProposalsCreate(props: PageProps<ProposalsCreateProps>) {
    const {
        can_self_approve: canSelfApprove,
        approvers,
        notify_on_self_approve: notified,
        team,
        types,
        areas,
        apps,
        wip,
        today,
        next_request_key: requestKey = null,
        next_project_key: projectKey = null,
        errors: serverErrors = {},
        draft,
        auth,
    } = props;

    const form = useForm<ProposalForm>({ ...emptyProposal(team, approvers), ...draft });
    const { data } = form;
    const [attempted, setAttempted] = useState(Object.keys(serverErrors).length > 0);

    useEffect(() => {
        if (Object.keys(serverErrors).length > 0) form.setError(serverErrors as Record<keyof ProposalForm, string>);
        // Only the errors the page arrived with; later ones come from form.post.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    const errors = fieldErrors(form.errors as Record<string, string | undefined>);
    const summary = errorSummary(form.errors as Record<string, string | undefined>);

    /** Drops a field's errors, including server row keys (`hours.0.hours`). */
    const clearField = (field: keyof ProposalForm) => {
        const stale = Object.keys(form.errors).filter((key) => fieldOfError(key) === field);
        if (stale.length > 0) form.clearErrors(...(stale as (keyof ProposalForm)[]));
    };

    const update = <K extends keyof ProposalForm>(field: K, value: ProposalForm[K]) => {
        form.setData((previous) => ({ ...previous, [field]: value }));
        clearField(field);
    };

    // ---- derived values --------------------------------------------------------

    const hoursPerWeek = parseDecimal(data.hours_per_week);
    const people = parseDecimal(data.people);
    const timeInvalid = Boolean(errors.hours_per_week) || (hoursPerWeek !== null && (hoursPerWeek < 0.5 || hoursPerWeek > 60));
    const live = livePriority(data);
    const weekly = !timeInvalid && hoursPerWeek !== null && people !== null ? hoursPerWeek * people : null;
    const totalHours = estimateTotal(data.hours);

    const mainArea = areas.flatMap((group) => group.areas).find((area) => area.id === data.area_id) ?? null;
    const mainGroup = mainArea ? (areas.find((group) => group.company.id === mainArea.company_id) ?? null) : null;
    const benefitOptions = mainGroup ? mainGroup.areas.filter((area) => area.id !== mainArea?.id).map((area) => ({ value: String(area.id), label: area.name })) : [];

    const outcomeInput = {
        canSelfApprove,
        requestKey,
        projectKey: canSelfApprove ? projectKey : null,
        size: data.size,
        urgency: data.urgency,
        priority: data.priority_final ?? live,
        totalHours,
        dueDate: data.due_date,
        projectName: data.project_name,
        startDate: data.start_date,
        approvers: approvers.filter((approver) => data.approver_ids.includes(approver.id)),
        proposerId: auth.user.id,
        team,
        hours: data.hours,
    };
    const outcome = proposalOutcome(outcomeInput);
    const phoneSummary = submitSummary(outcomeInput);
    const notice = canSelfApprove ? noticeLine(notified) : null;
    const wipNote = canSelfApprove ? wipWarning(wip, projectKey) : null;

    const explanation =
        data.size && weekly !== null
            ? `${number(weekly)} h/sem con talla ${data.size} y urgencia ${URGENCY_LABELS[data.urgency ?? 'medium'].toLowerCase()}.`
            : timeInvalid
              ? 'Se calcula cuando las horas estén bien.'
              : 'Elige la talla para calcularla.';

    // ---- submit -----------------------------------------------------------------

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        setAttempted(true);
        const found = validateProposal(data, { canSelfApprove });
        if (Object.keys(found).length > 0) {
            form.clearErrors();
            form.setError(found as Record<keyof ProposalForm, string>);
            const first = errorSummary(found).first;
            if (first) focusField(first.field);
            return;
        }
        form.transform((current) => ({ ...buildProposalPayload(current, { canSelfApprove }) }));
        form.post(route('ai.proposals.store'), {
            preserveScroll: true,
            onError: (received) => {
                const first = errorSummary(received).first;
                if (first) focusField(first.field);
            },
        });
    };

    const goToFirstError = (event: MouseEvent<HTMLAnchorElement>) => {
        event.preventDefault();
        if (summary.first) focusField(summary.first.field);
    };

    const submitLabel = canSelfApprove ? 'Crear proyecto' : 'Enviar a aprobación';
    const cancelHref = route('ai.projects.index');

    const errorAlert =
        attempted && summary.first ? (
            <Alert tone="danger">
                Falta corregir {summary.count} {summary.count === 1 ? 'campo' : 'campos'}:{' '}
                <a href={`#${fieldId(summary.first.field)}`} onClick={goToFirstError} className="font-bold underline underline-offset-2">
                    {summary.first.label}
                </a>
                .
            </Alert>
        ) : null;

    const noticeNote = notice ? (
        <p className="flex items-start gap-2 text-[13px] leading-snug text-fg-soft">
            <Icon name="bell" size={15} className="mt-0.5 shrink-0" />
            {notice}
        </p>
    ) : null;

    const wipAlert = wipNote ? <Alert tone="warning">{wipNote}</Alert> : null;

    return (
        <GestorLayout
            shared={props}
            title="Proponer proyecto · Gestor IA"
            tab="projects"
            mode="it"
            breadcrumb={[{ label: 'Proyectos', href: cancelHref }, { label: 'Proponer proyecto' }]}
        >
            <div className="flex flex-col gap-4">
                <div className="flex flex-wrap items-end justify-between gap-3">
                    <div className="flex min-w-0 flex-col gap-1.5">
                        <h1 className="font-display text-[26px] leading-tight font-bold tracking-[-0.02em] md:text-[30px]">Proponer proyecto</h1>
                        <p className="text-[15px] leading-snug text-fg-soft md:text-sm">
                            {canSelfApprove
                                ? 'Una propuesta tuya, evaluada y aprobada en un solo paso.'
                                : 'Una propuesta tuya, ya evaluada, que va a la ronda de aprobación como cualquier solicitud.'}
                            <span className="hidden md:inline"> Usa las mismas preguntas de “Pedir un desarrollo”.</span>
                        </p>
                    </div>
                    <div className="hidden md:block">
                        <Button variant="secondary" href={cancelHref}>
                            Cancelar
                        </Button>
                    </div>
                </div>

                <div className="grid grid-cols-1 gap-6 lg:grid-cols-[minmax(0,1fr)_340px] lg:items-start">
                    <form id={FORM_ID} aria-label="Proponer proyecto" noValidate onSubmit={submit} className="flex min-w-0 flex-col gap-4">
                        <FormSection id="sec-que" title="Qué se necesita">
                            <div className="grid grid-cols-1 gap-3.5 md:grid-cols-2">
                                <Select
                                    id={fieldId('type')}
                                    label="Tipo de solicitud"
                                    placeholder="Escoge el tipo"
                                    options={types.map((option) => ({ value: option.value, label: option.title }))}
                                    value={data.type ?? ''}
                                    error={errors.type}
                                    onChange={(event) => update('type', event.target.value === '' ? null : (event.target.value as ProposalForm['type']))}
                                />
                                <Select
                                    id={fieldId('product_slug')}
                                    label="App"
                                    optional
                                    placeholder="Ninguna · es algo nuevo"
                                    options={apps.map((app) => ({ value: app.slug, label: `${app.name} · ${app.version}` }))}
                                    value={data.product_slug ?? ''}
                                    error={errors.product_slug}
                                    hint={apps.length > 0 ? 'Obligatoria si es una mejora o algo que no funciona.' : 'Aún no hay apps registradas.'}
                                    onChange={(event) => update('product_slug', event.target.value === '' ? null : event.target.value)}
                                />
                                <TextField
                                    id={fieldId('title')}
                                    label="Título"
                                    containerClassName="md:col-span-2"
                                    value={data.title}
                                    maxLength={TITLE_MAX}
                                    error={errors.title}
                                    hint="Así aparece en la bandeja y en la hoja de ruta. Hasta 120 caracteres."
                                    onChange={(event) => update('title', event.target.value)}
                                />
                                <TextArea
                                    id={fieldId('problem')}
                                    label="El problema"
                                    containerClassName="md:col-span-2"
                                    rows={3}
                                    value={data.problem}
                                    error={errors.problem}
                                    onChange={(event) => update('problem', event.target.value)}
                                />
                                <TextArea
                                    id={fieldId('current_process')}
                                    label="¿Cómo se hace hoy?"
                                    rows={3}
                                    value={data.current_process}
                                    error={errors.current_process}
                                    onChange={(event) => update('current_process', event.target.value)}
                                />
                                <TextArea
                                    id={fieldId('expected_result')}
                                    label="Resultado esperado"
                                    rows={3}
                                    value={data.expected_result}
                                    error={errors.expected_result}
                                    onChange={(event) => update('expected_result', event.target.value)}
                                />
                            </div>
                        </FormSection>

                        <FormSection id="sec-quien" title="Para quién">
                            <div className="grid grid-cols-1 items-start gap-x-5 gap-y-3.5 md:grid-cols-[280px_minmax(0,1fr)]">
                                <GroupedSelect
                                    id={fieldId('area_id')}
                                    label="Área principal"
                                    placeholder="Escoge el área"
                                    groups={areas.map((group) => ({
                                        label: group.company.short_name,
                                        options: group.areas.map((area) => ({ value: String(area.id), label: area.name })),
                                    }))}
                                    value={data.area_id === null ? '' : String(data.area_id)}
                                    error={errors.area_id}
                                    hint={mainGroup ? `${mainGroup.company.short_name} · la empresa sale del área.` : 'La empresa sale del área.'}
                                    onChange={(value) => {
                                        const id = value === '' ? null : Number(value);
                                        clearField('area_id');
                                        const company = areas.find((group) => group.areas.some((area) => area.id === id));
                                        form.setData((current) => ({
                                            ...current,
                                            area_id: id,
                                            benefited_area_ids: current.benefited_area_ids.filter(
                                                (areaId) => areaId !== id && (company?.areas.some((area) => area.id === areaId) ?? false),
                                            ),
                                        }));
                                    }}
                                />
                                <div id={fieldId('benefited_area_ids')} className="flex min-w-0 flex-col gap-1.5">
                                    <span className="text-[13px] font-semibold">
                                        También se benefician <span className="font-normal text-fg-muted">(opcional)</span>
                                    </span>
                                    {benefitOptions.length > 0 ? (
                                        <ChipSelect
                                            label="También se benefician"
                                            multiple
                                            options={benefitOptions}
                                            value={data.benefited_area_ids.map(String)}
                                            onChange={(values) => update('benefited_area_ids', values.map(Number))}
                                        />
                                    ) : null}
                                    <p className="text-[13px] leading-snug text-fg-muted">
                                        {mainGroup
                                            ? `Áreas de ${mainGroup.company.short_name}. Pasan al proyecto como “También se benefician”.`
                                            : 'Escoge primero el área principal.'}
                                    </p>
                                    {errors.benefited_area_ids ? (
                                        <p className="flex items-center gap-1.5 text-[13px] font-semibold text-status-failure">
                                            <Icon name="alert-triangle" size={14} />
                                            {errors.benefited_area_ids}
                                        </p>
                                    ) : null}
                                </div>
                            </div>
                        </FormSection>

                        <div className="grid grid-cols-1 items-stretch gap-4 min-[1400px]:grid-cols-2">
                            <FormSection id="sec-valor" title="Valor">
                                <div className="grid grid-cols-2 gap-3">
                                    <TextField
                                        id={fieldId('hours_per_week')}
                                        label="Horas por semana"
                                        inputMode="decimal"
                                        value={data.hours_per_week}
                                        error={errors.hours_per_week}
                                        hint="Lo que gasta cada persona"
                                        onChange={(event) => update('hours_per_week', event.target.value)}
                                        className="tabular text-center text-lg font-bold"
                                    />
                                    <TextField
                                        id={fieldId('people')}
                                        label="Cuántas personas"
                                        inputMode="numeric"
                                        value={data.people}
                                        error={errors.people}
                                        hint="Las que hacen esa tarea"
                                        onChange={(event) => update('people', event.target.value)}
                                        className="tabular text-center text-lg font-bold"
                                    />
                                </div>
                                <TeamHoursCard hours={hoursPerWeek} people={people} invalid={timeInvalid} />
                                <Select
                                    id={fieldId('risk')}
                                    label="Riesgo que reduce"
                                    options={RISKS}
                                    value={data.risk}
                                    error={errors.risk}
                                    onChange={(event) => update('risk', event.target.value as RiskLevel)}
                                />
                            </FormSection>

                            <FormSection id="sec-eval" title="Evaluación">
                                <UrgencyPicker
                                    id={fieldId('urgency')}
                                    today={today}
                                    value={data.urgency}
                                    error={errors.urgency}
                                    onChange={(urgency) => update('urgency', urgency)}
                                />
                                <SizePicker id={fieldId('size')} value={data.size} error={errors.size} onChange={(size) => update('size', size)} />
                                <PriorityBox
                                    id={fieldId('priority_final')}
                                    live={live}
                                    explanation={explanation}
                                    value={data.priority_final ?? live}
                                    onChange={(value) => update('priority_final', value)}
                                    onReset={data.priority_final !== null && data.priority_final !== live ? () => update('priority_final', null) : undefined}
                                />
                            </FormSection>
                        </div>

                        <div className="grid grid-cols-1 items-stretch gap-4 min-[1400px]:grid-cols-2">
                            <FormSection
                                id="sec-est"
                                title="Estimado"
                                hint={canSelfApprove ? 'Es el presupuesto aprobado del proyecto.' : 'Es lo que van a aprobar.'}
                            >
                                <EstimateRows
                                    id={fieldId('hours')}
                                    team={team}
                                    hours={data.hours}
                                    total={totalHours}
                                    error={errors.hours}
                                    onChange={(personId, value) => update('hours', { ...data.hours, [personId]: value })}
                                />
                                <TextField
                                    id={fieldId('due_date')}
                                    label="Fecha estimada de entrega"
                                    type="date"
                                    min={today}
                                    value={data.due_date}
                                    error={errors.due_date}
                                    onChange={(event) => update('due_date', event.target.value)}
                                />
                            </FormSection>

                            {canSelfApprove ? (
                                <FormSection id="sec-proy" title="Proyecto" hint="Lo ves porque puedes aprobar proyectos." tone="project">
                                    <TextField
                                        id={fieldId('project_name')}
                                        label="Nombre del proyecto"
                                        value={data.project_name}
                                        error={errors.project_name}
                                        placeholder={data.title.trim() || undefined}
                                        onChange={(event) => update('project_name', event.target.value)}
                                    />
                                    <TextField
                                        id={fieldId('start_date')}
                                        label="Empieza"
                                        type="date"
                                        value={data.start_date}
                                        error={errors.start_date}
                                        onChange={(event) => update('start_date', event.target.value)}
                                    />
                                    <p className="text-[13px] leading-snug text-fg-soft">Se crea con su tablero, sus documentos y el presupuesto aprobado.</p>
                                </FormSection>
                            ) : (
                                <FormSection id="sec-aprueba" title="Quién aprueba">
                                    <ApproverPicker
                                        id={fieldId('approver_ids')}
                                        approvers={approvers}
                                        selected={data.approver_ids}
                                        error={errors.approver_ids}
                                        onToggle={(personId, checked) =>
                                            update(
                                                'approver_ids',
                                                checked ? [...data.approver_ids, personId] : data.approver_ids.filter((value) => value !== personId),
                                            )
                                        }
                                    />
                                    <p className="text-[13px] leading-snug text-fg-soft">Basta con que uno apruebe. Les llega por Telegram con botones para aprobar o rechazar.</p>
                                    <p className="text-[13px] leading-snug text-fg-soft">No puedes aprobarla tú: el proyecto se crea cuando uno de ellos la apruebe.</p>
                                </FormSection>
                            )}
                        </div>

                        {/* Phone and tablet: the aside becomes a collapsible summary plus a sticky bar. */}
                        <details className="group rounded-card border border-line bg-surface px-4 py-1 lg:hidden">
                            <summary className="flex min-h-11 cursor-pointer list-none items-center justify-between gap-3 py-1.5 font-display text-lg font-bold tracking-[-0.02em] [&::-webkit-details-marker]:hidden">
                                {canSelfApprove ? 'Qué pasa al crear' : 'Qué pasa al enviar'}
                                <Icon name="chevron-down" size={18} className="transition-transform duration-150 group-open:rotate-180" />
                            </summary>
                            <div className="flex flex-col gap-3 pt-2 pb-4">
                                <OutcomeList lines={outcome} />
                                {noticeNote}
                                {wipAlert}
                            </div>
                        </details>

                        <div className="sticky bottom-[calc(66px+max(6px,env(safe-area-inset-bottom)))] z-10 -mx-4 -mb-10 flex flex-col gap-2.5 border-t border-line bg-surface px-4 pt-3 pb-4 md:bottom-0 md:-mx-5 md:-mb-6 lg:hidden">
                            {errorAlert ?? (
                            <p className="text-[13px] leading-snug text-fg-soft">
                                {phoneSummary.text}
                                {phoneSummary.status ? (
                                    <>
                                        {' '}
                                        <StatusChip status={phoneSummary.status} size="sm" className="align-middle" />
                                    </>
                                ) : null}
                                {phoneSummary.end ? ` ${phoneSummary.end}` : phoneSummary.status ? '.' : null}
                            </p>
                            )}
                            <Button type="submit" size="lg" fullWidth loading={form.processing}>
                                {submitLabel}
                            </Button>
                        </div>
                    </form>

                    <aside aria-labelledby="que-pasa" className="sticky top-5 hidden flex-col gap-3 lg:flex">
                        <div className="flex flex-col gap-4 rounded-card border border-line bg-surface px-[22px] py-5">
                            <h2 id="que-pasa" className="font-display text-lg font-bold tracking-[-0.02em]">
                                {canSelfApprove ? 'Qué pasa al crear' : 'Qué pasa al enviar'}
                            </h2>
                            <OutcomeList lines={outcome} />
                            {noticeNote}
                            {errorAlert}
                            {wipAlert}
                            <div className="flex flex-col gap-2">
                                <Button type="submit" form={FORM_ID} size="lg" fullWidth loading={form.processing}>
                                    {submitLabel}
                                </Button>
                                <Button variant="quiet" href={cancelHref} fullWidth>
                                    Cancelar
                                </Button>
                            </div>
                            <p className="text-xs leading-snug text-fg-muted">
                                Todo se guarda de una vez: si algo falla, no se crea nada. Después te llevamos{' '}
                                {canSelfApprove ? 'al proyecto' : 'a la solicitud'}.
                            </p>
                        </div>
                    </aside>
                </div>
            </div>
        </GestorLayout>
    );
}
