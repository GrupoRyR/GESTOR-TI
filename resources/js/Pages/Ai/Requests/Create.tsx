import { Head, router, useForm } from '@inertiajs/react';
import { useEffect, useRef, useState, type FormEvent } from 'react';
import {
    AttachmentPicker,
    Note,
    ReviewRow,
    SimilarMatch,
    StepHeading,
    WizardFooter,
    WizardHeader,
    type SaveState,
} from '@/Components/ai/requests/WizardParts';
import { teamHoursLine } from '@/Components/ai/requests/requestView';
import { urgencyOptions } from '@/Components/ai/requests/urgency';
import { Alert } from '@/Components/ui/Alert';
import { brandScopeStyle } from '@/Components/ui/brandScope';
import { Button } from '@/Components/ui/Button';
import { ChipSelect } from '@/Components/ui/ChipSelect';
import { ChoiceCardGroup, type ChoiceOption } from '@/Components/ui/ChoiceCard';
import { Icon, type IconName } from '@/Components/ui/Icon';
import { NumberStepper } from '@/Components/ui/NumberStepper';
import { Select } from '@/Components/ui/Select';
import { TextArea } from '@/Components/ui/TextArea';
import { TextField } from '@/Components/ui/TextField';
import { brandCompany } from '@/Layouts/AppLayout';
import { formatDate } from '@/lib/format';
import { route } from '@/lib/route';
import {
    emptyDraft,
    firstStepWithErrors,
    HOURS_MAX,
    HOURS_MIN,
    PEOPLE_MAX,
    PROBLEM_MIN,
    stepOfField,
    teamHours,
    validateStep,
    type RequestDraft,
    type RequestType,
    type WizardStepNumber,
} from '@/lib/wizard';
import type { RequestDraftPayload, RequestsCreateProps } from '@/types/pages/ai-requester';
import type { PageProps } from '@/types/shared';

type WizardForm = RequestDraftPayload & { attachments: File[] };
type Errors = Record<string, string | undefined>;

const TYPE_ICONS: Record<RequestType, IconName> = { new: 'plus', improvement: 'trend', bug: 'alert-triangle', idea: 'question' };

const PROMPTS = ['¿Quién lo sufre?', '¿Cómo se hace hoy?', '¿Qué pasa si sigue así?'];

const OTHER_SYSTEM = '__other__';
const SIMILAR_DEBOUNCE_MS = 700;
const AUTOSAVE_DEBOUNCE_MS = 2000;

const hours = (value: number) => value.toLocaleString('es-CO', { maximumFractionDigits: 1 });

function payloadOf(data: WizardForm): RequestDraftPayload {
    const { attachments: _files, ...rest } = data;
    return rest;
}

function toDraft(data: WizardForm): RequestDraft {
    return { ...data, attachments: data.attachments.map((file) => ({ name: file.name, size: file.size, mime: file.type || 'application/octet-stream' })) };
}

/**
 * "Pedir un desarrollo o una mejora": seven client-side steps, autosaved as a
 * draft, one multipart POST at the end. While the problem is typed the page
 * asks the server for similar requests (partial reload of `similar`).
 */
export default function RequestsCreate(props: PageProps<RequestsCreateProps>) {
    const { product, types, apps, systems_options, area_suggestion, areas, max_attachment_mb, similar, today, draft, initial_step, errors: serverErrors = {} } = props;
    const company = brandCompany(props);

    const initial: WizardForm = {
        ...emptyDraft(),
        area_id: area_suggestion.id,
        ...(product ? { type: 'improvement' as const, product_slug: product.slug } : {}),
        ...draft,
        attachments: [],
    };
    const form = useForm<WizardForm>(initial);
    const saver = useForm<Record<string, never>>({});
    const voter = useForm<Record<string, never>>({});
    const { data, setData } = form;

    const startStep: WizardStepNumber = initial_step ?? firstStepWithErrors(serverErrors) ?? 1;
    const [step, setStep] = useState<WizardStepNumber>(startStep);
    const [clientErrors, setClientErrors] = useState<Errors>({});
    const [dismissed, setDismissed] = useState<string[]>([]);
    const [votedKey, setVotedKey] = useState<string | null>(null);
    const [save, setSave] = useState<SaveState>('idle');
    const [otherSystem, setOtherSystem] = useState('');
    const [otherOn, setOtherOn] = useState(false);
    const [changingArea, setChangingArea] = useState(false);
    const headingRef = useRef<HTMLHeadingElement>(null);
    const firstRender = useRef(true);
    const lastSaved = useRef(JSON.stringify(payloadOf(initial)));
    const lastQuery = useRef('');

    useEffect(() => {
        if (Object.keys(serverErrors).length > 0) form.setError(serverErrors);
        // Only the errors the page arrived with; later ones come from form.post.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    useEffect(() => {
        if (firstRender.current) {
            firstRender.current = false;
            return;
        }
        headingRef.current?.focus();
        window.scrollTo({ top: 0 });
    }, [step, votedKey]);

    // Autosave: the draft without files, 2 s after the last change.
    const payloadJson = JSON.stringify(payloadOf(data));
    useEffect(() => {
        if (payloadJson === lastSaved.current) return;
        const timer = window.setTimeout(() => {
            lastSaved.current = payloadJson;
            setSave('saving');
            saver.transform(() => JSON.parse(payloadJson) as RequestDraftPayload);
            saver.put(route('ai.requests.draft.update'), {
                preserveScroll: true,
                preserveState: true,
                only: ['draft_saved_at'],
                onSuccess: () => setSave('saved'),
                onError: () => setSave('idle'),
            });
        }, AUTOSAVE_DEBOUNCE_MS);
        return () => window.clearTimeout(timer);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [payloadJson]);

    // Similar requests: partial reload of `similar` with the typed problem.
    const query = data.problem.trim();
    useEffect(() => {
        if (query.length < PROBLEM_MIN || query === lastQuery.current) return;
        const timer = window.setTimeout(() => {
            lastQuery.current = query;
            router.reload({ only: ['similar'], data: { q: query } });
        }, SIMILAR_DEBOUNCE_MS);
        return () => window.clearTimeout(timer);
    }, [query]);

    const errors: Errors = { ...(form.errors as Errors), ...clientErrors };
    const stepHasError = (number: WizardStepNumber) => Object.keys(errors).some((key) => errors[key] && stepOfField(key) === number);

    const update = <K extends keyof WizardForm>(key: K, value: WizardForm[K]) => {
        setData((previous) => ({ ...previous, [key]: value }));
        if (errors[key as string] !== undefined) {
            setClientErrors((previous) => ({ ...previous, [key]: undefined }));
            form.clearErrors(key);
        }
    };

    const goTo = (next: WizardStepNumber) => {
        setClientErrors({});
        setStep(next);
    };

    const back = () => {
        if (step > 1) goTo((step - 1) as WizardStepNumber);
    };

    const advance = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        const found = validateStep(step, toDraft(data), today);
        if (Object.keys(found).length > 0) {
            setClientErrors(found);
            return;
        }
        if (step < 7) {
            goTo((step + 1) as WizardStepNumber);
            return;
        }
        // Step 7: every step must pass before sending.
        for (const number of [1, 2, 3, 4, 5, 6] as WizardStepNumber[]) {
            const earlier = validateStep(number, toDraft(data), today);
            if (Object.keys(earlier).length > 0) {
                setStep(number);
                setClientErrors(earlier);
                return;
            }
        }
        form.transform((values) => {
            const { plus_one_key: _vote, ...rest } = values;
            const extra = otherOn && otherSystem.trim() ? [otherSystem.trim()] : [];
            return { ...rest, systems: [...values.systems, ...extra] };
        });
        form.post(route('ai.requests.store'), {
            forceFormData: true,
            onError: (received) => setStep(firstStepWithErrors(received) ?? 7),
        });
    };

    const match = similar.find((item) => !dismissed.includes(item.key) && item.key !== votedKey) ?? null;
    const voted = votedKey ? similar.find((item) => item.key === votedKey) ?? null : null;

    const vote = (key: string) => {
        voter.post(route('ai.requests.vote', { request: key }), {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                setVotedKey(key);
                update('plus_one_key', key);
            },
        });
    };

    const chosenApp = apps.find((app) => app.slug === data.product_slug) ?? (product && product.slug === data.product_slug ? product : null);
    const needsApp = data.type === 'improvement' || data.type === 'bug';
    const typeOptions: ChoiceOption<RequestType>[] = types.map((option) => ({
        value: option.value,
        title: option.title,
        description: option.description,
        icon: TYPE_ICONS[option.value],
        detail:
            (option.value === 'improvement' || option.value === 'bug') && chosenApp ? (
                <span className="text-fg-soft">
                    App: <b className="text-fg">{chosenApp.name}</b> · {chosenApp.version}
                </span>
            ) : undefined,
    }));
    const team = teamHours(data);
    const areaName = areas.find((item) => item.id === data.area_id)?.name ?? area_suggestion.name;
    const systemsChosen = [...data.systems, ...(otherOn && otherSystem.trim() ? [otherSystem.trim()] : [])];
    const typeTitle = types.find((option) => option.value === data.type)?.title ?? '—';
    const urgencyTitle = urgencyOptions(today).find((option) => option.value === data.urgency)?.title ?? '—';
    const serverErrorSteps = [...new Set(Object.keys(form.errors).map(stepOfField))].filter((number) => number !== step);

    return (
        <div className="brand-scope flex min-h-dvh flex-col bg-ground text-fg" style={company ? brandScopeStyle(company.colors) : undefined}>
            <Head title="Pedir un desarrollo o una mejora · Gestor IA" />
            <WizardHeader step={step} closeHref={route('home')} save={save} />

            <form onSubmit={advance} noValidate className="flex flex-1 flex-col">
                <main id="contenido" className="mx-auto flex w-full max-w-[720px] flex-1 flex-col gap-5 px-4 pt-4 pb-8 md:px-6 md:pt-8">
                    {serverErrorSteps.length > 0 ? (
                        <Alert tone="danger" title="No pudimos enviarla todavía">
                            Revisa {serverErrorSteps.length === 1 ? 'el paso' : 'los pasos'} {serverErrorSteps.join(' y ')}: hay datos por corregir.
                        </Alert>
                    ) : null}

                    {step === 1 && (
                        <>
                            {product ? (
                                <Note icon="external" tone="brand">
                                    <b>
                                        Llegaste desde {product.name} · {product.version}
                                    </b>
                                    <br />
                                    Ya marcamos la app por ti. Si es otra cosa, cámbiala.
                                </Note>
                            ) : null}
                            <StepHeading headingRef={headingRef} title="¿Qué necesitas?" text="Escoge la que más se parezca. Después lo cuentas con tus palabras." />
                            <ChoiceCardGroup
                                legend="Tipo de solicitud"
                                hideLegend
                                options={typeOptions}
                                value={data.type}
                                onChange={(value) => update('type', value)}
                                error={errors.type}
                            />
                            {needsApp ? (
                                <Select
                                    label="¿En qué app?"
                                    size="lg"
                                    value={data.product_slug ?? ''}
                                    placeholder="Escoge la app"
                                    options={apps.map((app) => ({ value: app.slug, label: `${app.name} · ${app.version}` }))}
                                    onChange={(event) => update('product_slug', event.target.value || null)}
                                    error={errors.product_slug}
                                />
                            ) : null}
                        </>
                    )}

                    {step === 2 && voted ? (
                        <div className="flex flex-col gap-4">
                            <span aria-hidden="true" className="flex size-14 items-center justify-center rounded-full bg-status-active-bg text-status-active">
                                <Icon name="check-circle" size={28} />
                            </span>
                            <StepHeading
                                headingRef={headingRef}
                                title={`Sumaste tu voto a ${voted.key}`}
                                text={
                                    <>
                                        <b>{voted.title}</b>. Tu voto sube su prioridad y te avisamos de cada avance. No tienes que llenar nada más.
                                    </>
                                }
                            />
                            <div className="flex flex-col gap-2.5 sm:flex-row">
                                <Button size="lg" href={route('ai.requests.show', { request: voted.key })}>
                                    Ver {voted.key}
                                </Button>
                                <Button size="lg" variant="secondary" href={route('home')}>
                                    Volver al inicio
                                </Button>
                            </div>
                            <Button
                                variant="quiet"
                                className="-ml-4 self-start"
                                onClick={() => {
                                    setDismissed((previous) => [...previous, voted.key]);
                                    setVotedKey(null);
                                    update('plus_one_key', null);
                                }}
                            >
                                Mi caso es distinto: seguir con mi solicitud
                            </Button>
                        </div>
                    ) : null}

                    {step === 2 && !voted && (
                        <>
                            <StepHeading headingRef={headingRef} title="¿Qué problema hay?" text="Cuéntalo como se lo contarías a un compañero." />
                            <div className="flex flex-col gap-2">
                                <TextArea
                                    label="El problema"
                                    rows={5}
                                    value={data.problem}
                                    onChange={(event) => update('problem', event.target.value)}
                                    error={errors.problem}
                                    hint={
                                        <span className="tabular">
                                            {data.problem.trim().length} caracteres · con 2 o 3 frases basta
                                        </span>
                                    }
                                />
                                <div role="group" aria-label="Ideas para contarlo" className="flex flex-wrap gap-2">
                                    {PROMPTS.map((prompt) => (
                                        <button
                                            key={prompt}
                                            type="button"
                                            onClick={() => update('problem', `${data.problem.trimEnd()}${data.problem.trim() ? '\n' : ''}${prompt} `)}
                                            className="inline-flex h-9 items-center gap-1.5 rounded-chip border border-line-strong bg-surface px-3 text-[13px] font-semibold text-fg-soft hover:border-fg-muted"
                                        >
                                            <Icon name="plus" size={12} strokeWidth={2.6} />
                                            {prompt}
                                        </button>
                                    ))}
                                </div>
                            </div>
                            {match ? (
                                <SimilarMatch
                                    request={match}
                                    processing={voter.processing}
                                    onVote={() => vote(match.key)}
                                    onDismiss={() => setDismissed((previous) => [...previous, match.key])}
                                />
                            ) : null}
                        </>
                    )}

                    {step === 3 && (
                        <>
                            <StepHeading headingRef={headingRef} title="¿Cuánto tiempo les toma hoy?" text="Piensa en una semana normal." />
                            {data.type === 'idea' ? <p className="-mt-2 text-[13px] text-fg-muted">Como es una idea, puedes dejarlo vacío.</p> : null}
                            <NumberStepper
                                label="Horas por semana"
                                hint="Lo que gasta cada persona"
                                value={data.hours_per_week}
                                onChange={(value) => update('hours_per_week', value)}
                                min={HOURS_MIN}
                                max={HOURS_MAX}
                                step={0.5}
                                unit="h"
                                error={errors.hours_per_week}
                            />
                            <NumberStepper
                                label="Cuántas personas"
                                hint="Las que hacen esa tarea"
                                value={data.people}
                                onChange={(value) => update('people', value === null ? null : Math.round(value))}
                                min={1}
                                max={PEOPLE_MAX}
                                unit="personas"
                                error={errors.people}
                            />
                            <div aria-live="polite" className="flex items-center gap-4 rounded-card bg-fg p-4 text-on-fg">
                                {team !== null ? (
                                    <>
                                        <span className="tabular font-display text-[40px] leading-none font-bold tracking-[-0.02em]">{hours(team)}</span>
                                        <span className="flex flex-col text-sm">
                                            <b>horas a la semana del equipo</b>
                                            <span className="opacity-75">
                                                {hours(data.hours_per_week ?? 0)} horas × {data.people} {data.people === 1 ? 'persona' : 'personas'}
                                            </span>
                                        </span>
                                    </>
                                ) : (
                                    <span className="text-sm">Escribe las horas y las personas para ver el total del equipo.</span>
                                )}
                            </div>
                            <Note icon="info">
                                <b>¿Por qué preguntamos?</b>
                                <br />
                                Así priorizamos: lo que más tiempo ahorra va primero. Si no sabes exacto, un cálculo aproximado sirve.
                            </Note>
                        </>
                    )}

                    {step === 4 && (
                        <>
                            <StepHeading headingRef={headingRef} title="¿Qué esperas que pase?" text="Cuéntanos cómo se vería resuelto y cómo sabrías que quedó bien." />
                            <TextArea
                                label="Resultado esperado"
                                rows={4}
                                placeholder="Ej.: ver los precios de los proveedores en una sola tabla"
                                value={data.expected_result}
                                onChange={(event) => update('expected_result', event.target.value)}
                                error={errors.expected_result}
                            />
                            <TextArea
                                label="¿Cómo sabrías que quedó bien?"
                                optional
                                rows={3}
                                placeholder="Ej.: comparo una referencia en menos de 1 minuto"
                                value={data.success_criteria}
                                onChange={(event) => update('success_criteria', event.target.value)}
                                error={errors.success_criteria}
                                hint="Con esto TI arma los criterios que vas a validar al final."
                            />
                        </>
                    )}

                    {step === 5 && (
                        <>
                            <section aria-label="Lo que ya respondiste en el paso 4" className="flex flex-col gap-2 rounded-card border border-line bg-surface p-3.5">
                                <div className="flex items-center gap-2 text-[13px] font-semibold text-fg-muted">
                                    <Icon name="check-circle" size={16} />
                                    Paso 4 · Qué esperas
                                    <button type="button" onClick={() => goTo(4)} className="-my-2 ml-auto flex min-h-11 items-center px-1 font-bold text-fg underline underline-offset-2">
                                        Editar
                                    </button>
                                </div>
                                <dl className="grid gap-1.5 text-sm">
                                    <dt className="text-xs text-fg-muted">Resultado esperado</dt>
                                    <dd className="line-clamp-2">{data.expected_result || '—'}</dd>
                                    {data.success_criteria.trim() ? (
                                        <>
                                            <dt className="text-xs text-fg-muted">Sabrías que quedó bien si</dt>
                                            <dd className="line-clamp-2">{data.success_criteria}</dd>
                                        </>
                                    ) : null}
                                </dl>
                            </section>
                            <StepHeading
                                headingRef={headingRef}
                                title="¿Qué sistemas o datos usa?"
                                text="Marca todos los que tocan esta tarea. Si no sabes, déjalo y TI te pregunta."
                            />
                            <fieldset className="flex flex-col gap-2.5">
                                <legend className="mb-2.5 text-[13px] font-semibold">
                                    Sistemas · <span className="tabular">{systemsChosen.length}</span> {systemsChosen.length === 1 ? 'marcado' : 'marcados'}
                                </legend>
                                <ChipSelect
                                    multiple
                                    label="Sistemas que usa"
                                    options={[...systems_options.map((name) => ({ value: name, label: name })), { value: OTHER_SYSTEM, label: 'Otra app…' }]}
                                    value={[...data.systems, ...(otherOn ? [OTHER_SYSTEM] : [])]}
                                    onChange={(values) => {
                                        setOtherOn(values.includes(OTHER_SYSTEM));
                                        update(
                                            'systems',
                                            values.filter((value) => value !== OTHER_SYSTEM),
                                        );
                                    }}
                                />
                            </fieldset>
                            {otherOn ? (
                                <TextField label="¿Cuál otra app?" size="lg" value={otherSystem} onChange={(event) => setOtherSystem(event.target.value)} />
                            ) : null}
                            <TextArea
                                label="Algo más sobre los datos"
                                optional
                                rows={3}
                                value={data.systems_notes}
                                onChange={(event) => update('systems_notes', event.target.value)}
                                error={errors.systems_notes}
                            />
                        </>
                    )}

                    {step === 6 && (
                        <>
                            <StepHeading headingRef={headingRef} title="¿Para cuándo lo necesitas?" text="Sé honesto: si todo es urgente, nada lo es." />
                            <ChoiceCardGroup
                                legend="Urgencia"
                                hideLegend
                                layout="grid"
                                options={urgencyOptions(today)}
                                value={data.urgency}
                                onChange={(value) => update('urgency', value)}
                                error={errors.urgency}
                            />
                            <div className="flex items-end gap-2">
                                <TextField
                                    label="Fecha límite real"
                                    optional
                                    type="date"
                                    size="lg"
                                    min={today}
                                    value={data.deadline ?? ''}
                                    onChange={(event) => update('deadline', event.target.value || null)}
                                    error={errors.deadline}
                                    hint="Solo si algo pasa ese día. Si no hay fecha, déjalo vacío."
                                    containerClassName="flex-1"
                                />
                            </div>
                            {data.deadline ? (
                                <Button
                                    variant="quiet"
                                    className="-mt-3 -ml-4 self-start"
                                    onClick={() => {
                                        update('deadline', null);
                                        update('deadline_reason', '');
                                    }}
                                >
                                    Quitar la fecha
                                </Button>
                            ) : null}
                            {data.deadline ? (
                                <TextArea
                                    label="¿Por qué esa fecha?"
                                    required
                                    aria-required="true"
                                    rows={3}
                                    value={data.deadline_reason}
                                    onChange={(event) => update('deadline_reason', event.target.value)}
                                    error={errors.deadline_reason}
                                    hint="Lo pedimos porque pusiste una fecha."
                                />
                            ) : null}
                        </>
                    )}

                    {step === 7 && (
                        <>
                            <StepHeading headingRef={headingRef} title="Adjunta y revisa" text="Una foto de la pantalla o un archivo de ejemplo ayuda mucho. Es opcional." />
                            <AttachmentPicker
                                files={data.attachments}
                                onChange={(files) => update('attachments', files)}
                                maxMb={max_attachment_mb}
                                error={errors.attachments ?? Object.entries(errors).find(([key, value]) => key.startsWith('attachments.') && value)?.[1]}
                            />

                            <h2 className="pt-2 text-lg font-bold">Revisa antes de enviar</h2>
                            <div className="flex flex-col gap-2.5 rounded-card border border-line bg-surface p-3.5">
                                <div className="flex items-start gap-3">
                                    <span className="flex size-10 shrink-0 items-center justify-center rounded-icon bg-subtle">
                                        <Icon name="building" size={18} />
                                    </span>
                                    <span className="flex min-w-0 flex-1 flex-col text-sm">
                                        <b>Área: {areaName}</b>
                                        <span className="text-[13px] text-fg-muted">La sacamos de tu perfil, cámbiala si no es.</span>
                                    </span>
                                    {!changingArea ? (
                                        <Button variant="secondary" onClick={() => setChangingArea(true)} aria-label={`Cambiar el área (${areaName})`}>
                                            Cambiar
                                        </Button>
                                    ) : null}
                                </div>
                                {changingArea || errors.area_id ? (
                                    <Select
                                        label="Tu área"
                                        size="lg"
                                        value={data.area_id === null ? '' : String(data.area_id)}
                                        placeholder="Escoge tu área"
                                        options={areas.map((item) => ({ value: String(item.id), label: item.name }))}
                                        onChange={(event) => update('area_id', event.target.value ? Number(event.target.value) : null)}
                                        error={errors.area_id}
                                    />
                                ) : null}
                            </div>

                            <section aria-label="Resumen de tus respuestas" className="overflow-hidden rounded-card border border-line bg-surface">
                                <p className="flex items-center gap-2 border-b border-line bg-subtle px-4 py-2.5 text-[13px] text-fg-soft">
                                    <Icon name="message" size={14} />
                                    Te va a llegar copia por correo.
                                </p>
                                <dl>
                                    <ReviewRow label="Qué necesitas" onEdit={() => goTo(1)} invalid={stepHasError(1)}>
                                        {typeTitle}
                                        {needsApp && chosenApp ? ` · ${chosenApp.name} ${chosenApp.version}` : ''}
                                    </ReviewRow>
                                    <ReviewRow label="El problema" onEdit={() => goTo(2)} invalid={stepHasError(2)}>
                                        <span className="line-clamp-4 whitespace-pre-line">{data.problem || '—'}</span>
                                    </ReviewRow>
                                    <ReviewRow label="Tiempo hoy" onEdit={() => goTo(3)} invalid={stepHasError(3)}>
                                        <span className="tabular">{teamHoursLine(data.hours_per_week, data.people)?.replace('h/sem', 'h por semana') ?? 'Sin calcular'}</span>
                                    </ReviewRow>
                                    <ReviewRow label="Qué esperas" onEdit={() => goTo(4)} invalid={stepHasError(4)}>
                                        {data.expected_result || '—'}
                                        {data.success_criteria.trim() ? <span className="block text-fg-muted">Quedó bien si {data.success_criteria}</span> : null}
                                    </ReviewRow>
                                    <ReviewRow label="Sistemas" onEdit={() => goTo(5)} invalid={stepHasError(5)}>
                                        {systemsChosen.length > 0 ? systemsChosen.join(', ') : 'No marcaste ninguno'}
                                    </ReviewRow>
                                    <ReviewRow label="Urgencia" onEdit={() => goTo(6)} invalid={stepHasError(6)}>
                                        {urgencyTitle}
                                        {data.deadline ? ` · fecha límite ${formatDate(`${data.deadline}T12:00:00-05:00`)}` : ''}
                                        {data.deadline && data.deadline_reason.trim() ? (
                                            <span className="block text-fg-muted">{data.deadline_reason}</span>
                                        ) : null}
                                    </ReviewRow>
                                </dl>
                            </section>
                        </>
                    )}
                </main>

                {!(step === 2 && voted) ? <WizardFooter step={step} onBack={back} processing={form.processing} /> : null}
            </form>
        </div>
    );
}
