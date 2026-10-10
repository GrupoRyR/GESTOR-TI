import { Head, router, useForm } from '@inertiajs/react';
import { useEffect, useRef, useState, type FormEvent, type MouseEvent, type ReactNode, type RefObject } from 'react';
import { ConfirmDialog } from '@/Components/admin/AdminDialog';
import { buttonContrast, formatRatio } from '@/Components/admin/brandContrast';
import {
    adminConsentUrl,
    firstStepWithErrors,
    parseDomains,
    slugify,
    validateStep,
    type WizardData,
    type WizardErrors,
    type WizardStep,
} from '@/Components/admin/companyWizard';
import { BrandPreview, WizardStepper } from '@/Components/admin/CompanyWizardParts';
import { Alert } from '@/Components/ui/Alert';
import { brandScopeStyle } from '@/Components/ui/brandScope';
import { Button, buttonClasses } from '@/Components/ui/Button';
import { ColorField } from '@/Components/ui/ColorField';
import { FileDrop } from '@/Components/ui/FileDrop';
import { Icon } from '@/Components/ui/Icon';
import { Switch } from '@/Components/ui/Switch';
import { TextField } from '@/Components/ui/TextField';
import { cx } from '@/Components/ui/utils';
import { brandCompany } from '@/Layouts/AppLayout';
import { route } from '@/lib/route';
import type { AdminCompaniesCreateProps } from '@/types/pages/admin';
import type { PageProps } from '@/types/shared';

interface WizardForm {
    name: string;
    short_name: string;
    slug: string;
    domains: string;
    tenant_id: string;
    colors: { primary: string; accent: string; dark: string };
    logo: File | null;
    logo_dark: File | null;
    sender_email: string;
    active: boolean;
}

const NEXT_LABEL: Record<WizardStep, string> = {
    1: 'Seguir con Microsoft',
    2: 'Seguir con la marca',
    3: 'Seguir con el correo emisor',
    4: 'Revisar',
    5: 'Crear empresa',
};

function toWizardData(form: WizardForm): WizardData {
    return {
        name: form.name,
        short_name: form.short_name,
        slug: form.slug,
        domains: form.domains,
        tenant_id: form.tenant_id,
        has_logo: form.logo !== null,
        primary: form.colors.primary,
        accent: form.colors.accent,
        dark: form.colors.dark,
        sender_email: form.sender_email,
    };
}

function stepsWithErrors(errors: WizardErrors): WizardStep[] {
    const steps = new Set<WizardStep>();
    for (const key of Object.keys(errors)) {
        const step = firstStepWithErrors({ [key]: errors[key] ?? '' });
        if (step) steps.add(step);
    }
    return [...steps];
}

/** Agregar empresa: five steps held in client state, one multipart POST at the end. */
export default function AdminCompaniesCreate(props: PageProps<AdminCompaniesCreateProps>) {
    const { admin_consent_url_template, errors: serverErrors = {}, initial_step, draft } = props;
    const company = brandCompany(props);

    const form = useForm<WizardForm>({
        name: draft?.name ?? '',
        short_name: draft?.short_name ?? '',
        slug: draft?.slug ?? '',
        domains: draft?.domains?.join(', ') ?? '',
        tenant_id: draft?.tenant_id ?? '',
        colors: draft?.colors ? { ...draft.colors } : { primary: '#17191C', accent: '#5E6168', dark: '#17191C' },
        logo: null,
        logo_dark: null,
        sender_email: draft?.sender_email ?? '',
        active: true,
    });
    const { data, setData } = form;

    const startStep: WizardStep = initial_step ?? firstStepWithErrors(serverErrors) ?? 1;
    const [step, setStep] = useState<WizardStep>(startStep);
    const [reached, setReached] = useState<WizardStep>(Object.keys(serverErrors).length > 0 ? 5 : startStep);
    const [clientErrors, setClientErrors] = useState<WizardErrors>({});
    const [slugTouched, setSlugTouched] = useState(Boolean(draft?.slug));
    const [exitOpen, setExitOpen] = useState(false);
    const headingRef = useRef<HTMLHeadingElement>(null);
    const firstRender = useRef(true);

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
    }, [step]);

    const errors: WizardErrors = { ...(form.errors as WizardErrors), ...clientErrors };
    const invalidSteps = stepsWithErrors(errors);
    const shortName = data.short_name.trim() || 'la empresa';
    const domains = parseDomains(data.domains);
    const consentUrl = adminConsentUrl(admin_consent_url_template, data.tenant_id);
    const contrast = buttonContrast(data.colors);

    const update = <K extends keyof WizardForm>(key: K, value: WizardForm[K]) => {
        setData((previous) => ({ ...previous, [key]: value }));
        const errorKey = String(key);
        if (errors[errorKey] !== undefined) {
            setClientErrors((previous) => {
                const next = { ...previous };
                delete next[errorKey];
                return next;
            });
            form.clearErrors(key);
        }
    };

    const setName = (key: 'name' | 'short_name', value: string) => {
        update(key, value);
        if (!slugTouched) {
            const source = key === 'short_name' ? value : data.short_name || value;
            setData('slug', slugify(source));
        }
    };

    const setColor = (key: keyof WizardForm['colors']) => (hex: string) => {
        setData('colors', { ...data.colors, [key]: hex });
        setClientErrors((previous) => {
            const next = { ...previous };
            delete next[`colors.${key}`];
            return next;
        });
    };

    const goTo = (next: WizardStep) => {
        setStep(next);
        setReached((previous) => (next > previous ? next : previous));
    };

    const advance = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        const found = validateStep(step, toWizardData(data));
        setClientErrors(found);
        if (Object.keys(found).length > 0) {
            const firstInvalid = firstStepWithErrors(found);
            if (step === 5 && firstInvalid && firstInvalid < 5) goTo(firstInvalid);
            return;
        }
        if (step < 5) {
            goTo((step + 1) as WizardStep);
            return;
        }
        form.transform((values) => ({ ...values, domains: parseDomains(values.domains) }));
        form.post(route('admin.companies.store'), {
            forceFormData: true,
            onError: (received) => goTo(firstStepWithErrors(received) ?? 5),
        });
    };

    const exit = (event: MouseEvent) => {
        if (!form.isDirty) return;
        event.preventDefault();
        setExitOpen(true);
    };

    const stepErrorCount = Object.keys(errors).filter((key) => firstStepWithErrors({ [key]: 'x' }) === step).length;

    return (
        <div
            className="brand-scope flex min-h-dvh flex-col bg-ground text-fg"
            style={company ? brandScopeStyle(company.colors) : undefined}
        >
            <Head title="Agregar empresa · Administración" />
            <header className="sticky top-0 z-20 flex h-[68px] shrink-0 items-center gap-4 border-b border-line bg-surface px-4 md:px-8">
                <b className="text-base whitespace-nowrap">Agregar empresa</b>
                <span className="tabular text-sm whitespace-nowrap text-fg-muted">Paso {step} de 5</span>
                <Button variant="quiet" href={route('admin.companies.index')} onClick={exit} className="ml-auto">
                    Salir sin guardar
                </Button>
            </header>

            <div className="mx-auto grid w-full max-w-[1440px] flex-1 grid-cols-1 gap-6 px-4 py-5 md:px-8 md:py-8 lg:grid-cols-[240px_minmax(0,1fr)] lg:gap-8 xl:grid-cols-[260px_minmax(0,1fr)_360px] 2xl:grid-cols-[280px_minmax(0,1fr)_420px] 2xl:px-12">
                <WizardStepper current={step} reached={reached} invalid={invalidSteps} onSelect={goTo} />

                <form onSubmit={advance} noValidate className="flex min-w-0 flex-col gap-6">
                    {step === 1 && (
                        <StepIntro
                            headingRef={headingRef}
                            title="¿Qué empresa vas a agregar?"
                            text="Nombre como aparece en los documentos, un nombre corto para el menú y los dominios del correo de su gente."
                        />
                    )}
                    {step === 2 && (
                        <StepIntro
                            headingRef={headingRef}
                            title={`Conecta la cuenta de Microsoft de ${shortName}`}
                            text="Con el ID de inquilino, su gente entra con la cuenta de Microsoft de siempre y el Gestor trae sus colaboradores."
                        />
                    )}
                    {step === 3 && (
                        <StepIntro
                            headingRef={headingRef}
                            title={`¿Cómo se ve ${shortName}?`}
                            text="Sube el logo y escribe sus colores. El Gestor ajusta solo el tono del botón para que se lea bien."
                        />
                    )}
                    {step === 4 && (
                        <StepIntro
                            headingRef={headingRef}
                            title="¿Desde qué buzón salen sus correos?"
                            text={`Todo correo de un registro de ${shortName} sale de este buzón. Debe ser de uno de sus dominios.`}
                        />
                    )}
                    {step === 5 && (
                        <StepIntro
                            headingRef={headingRef}
                            title={`Revisa y activa ${shortName}`}
                            text="Si algo no está bien, vuelve al paso y corrígelo. Nada se guarda hasta que la crees."
                        />
                    )}

                    {stepErrorCount > 0 ? (
                        <Alert tone="danger" title={stepErrorCount === 1 ? 'Revisa el campo marcado' : `Revisa los ${stepErrorCount} campos marcados`} />
                    ) : null}

                    {step === 1 && (
                        <div className="grid grid-cols-1 gap-3.5 md:grid-cols-2">
                            <TextField
                                label="Nombre"
                                size="lg"
                                value={data.name}
                                onChange={(event) => setName('name', event.target.value)}
                                error={errors.name}
                                hint="Como aparece en facturas y contratos."
                                containerClassName="md:col-span-2"
                                autoComplete="organization"
                            />
                            <TextField
                                label="Nombre corto"
                                size="lg"
                                value={data.short_name}
                                onChange={(event) => setName('short_name', event.target.value)}
                                error={errors.short_name}
                                hint="Se ve en el menú y en el selector de empresa."
                            />
                            <TextField
                                label="Identificador"
                                size="lg"
                                value={data.slug}
                                onChange={(event) => {
                                    setSlugTouched(true);
                                    update('slug', event.target.value);
                                }}
                                error={errors.slug}
                                hint="Minúsculas y guiones. Se arma solo con el nombre corto."
                                autoCapitalize="off"
                                spellCheck={false}
                            />
                            <TextField
                                label="Dominios de correo"
                                size="lg"
                                value={data.domains}
                                onChange={(event) => update('domains', event.target.value)}
                                error={errors.domains}
                                hint="Ejemplo: andinapruebas.com. Si son varios, sepáralos con comas."
                                containerClassName="md:col-span-2"
                                autoCapitalize="off"
                                spellCheck={false}
                            />
                        </div>
                    )}

                    {step === 2 && (
                        <div className="flex flex-col gap-4">
                            <TextField
                                label="ID de inquilino de Microsoft"
                                size="lg"
                                value={data.tenant_id}
                                onChange={(event) => update('tenant_id', event.target.value)}
                                error={errors.tenant_id}
                                hint="Microsoft Entra › Información general › ID de inquilino."
                                placeholder="00000000-0000-0000-0000-000000000000"
                                className="tabular"
                                autoCapitalize="off"
                                spellCheck={false}
                            />
                            <div className="flex flex-col gap-3 rounded-control border border-line bg-surface p-4">
                                <p className="text-sm leading-normal text-fg-soft">
                                    Un administrador de Microsoft de {shortName} debe dar el consentimiento, como se hizo con Derivados. Mientras no
                                    lo apruebe, nadie de {shortName} puede entrar ni se traen sus colaboradores.
                                </p>
                                {consentUrl ? (
                                    <a
                                        href={consentUrl}
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        className={buttonClasses({ variant: 'dark', className: 'self-start' })}
                                    >
                                        Pedir consentimiento de administrador
                                        <Icon name="external" size={16} />
                                        <span className="sr-only">(abre Microsoft en otra pestaña)</span>
                                    </a>
                                ) : (
                                    <div className="flex flex-col items-start gap-1.5">
                                        <Button variant="dark" disabled icon="external" iconPosition="end">
                                            Pedir consentimiento de administrador
                                        </Button>
                                        <span className="text-[13px] text-fg-muted">Primero pega un ID de inquilino válido.</span>
                                    </div>
                                )}
                                <p className="text-[13px] leading-snug text-fg-muted">
                                    Puedes copiar el enlace y enviárselo. Al aprobar, Microsoft lo devuelve al Gestor.
                                </p>
                            </div>
                        </div>
                    )}

                    {step === 3 && (
                        <div className="flex flex-col gap-4">
                            <div className="grid grid-cols-1 gap-3.5 sm:grid-cols-2">
                                <FileDrop
                                    label="Logo para fondo claro"
                                    accept="image/svg+xml,image/png"
                                    onFile={(file) => update('logo', file)}
                                    error={errors.logo}
                                    hint="PNG o SVG."
                                />
                                <FileDrop
                                    label="Logo para fondo oscuro (opcional)"
                                    accept="image/svg+xml,image/png"
                                    surface="dark"
                                    onFile={(file) => update('logo_dark', file)}
                                    error={errors.logo_dark}
                                    hint="Si no hay, se usa el claro."
                                />
                            </div>
                            <div className="grid grid-cols-1 gap-3.5 sm:grid-cols-3">
                                <ColorField label="Color principal" value={data.colors.primary} onChange={setColor('primary')} error={errors['colors.primary']} />
                                <ColorField label="Color de acento" value={data.colors.accent} onChange={setColor('accent')} error={errors['colors.accent']} />
                                <ColorField label="Color oscuro" value={data.colors.dark} onChange={setColor('dark')} error={errors['colors.dark']} />
                            </div>
                            <p aria-live="polite" className="flex items-start gap-3 rounded-control border border-line bg-surface p-3.5 text-sm leading-[1.45]">
                                <span className={cx('mt-0.5', contrast.passes ? 'text-status-active' : 'text-status-maintenance-dot')}>
                                    <Icon name={contrast.passes ? 'check' : 'alert-triangle'} size={18} strokeWidth={2.4} />
                                </span>
                                {contrast.passes ? (
                                    <span>
                                        Blanco sobre {contrast.primary} da {formatRatio(contrast.ratio)}. El botón usa el color principal.
                                    </span>
                                ) : (
                                    <span>
                                        Blanco sobre {contrast.primary} da {formatRatio(contrast.ratio)}, no alcanza. El botón usará{' '}
                                        <b>{contrast.button}</b> ({formatRatio(contrast.buttonRatio)}).
                                    </span>
                                )}
                            </p>
                            <BrandPreview name={data.short_name} colors={data.colors} className="xl:hidden" />
                        </div>
                    )}

                    {step === 4 && (
                        <TextField
                            label="Correo emisor"
                            size="lg"
                            type="email"
                            value={data.sender_email}
                            onChange={(event) => update('sender_email', event.target.value)}
                            error={errors.sender_email}
                            hint={domains.length > 0 ? `Debe terminar en @${domains.join(' o @')}. Ejemplo: ${data.slug || 'empresa'}.info@${domains[0]}` : undefined}
                            placeholder={domains[0] ? `${data.slug || 'empresa'}.info@${domains[0]}` : undefined}
                            autoComplete="off"
                            containerClassName="max-w-[560px]"
                        />
                    )}

                    {step === 5 && (
                        <div className="flex flex-col gap-4">
                            <dl className="divide-y divide-line overflow-hidden rounded-card border border-line bg-surface">
                                <ReviewRow label="Empresa" step={1} onEdit={goTo} invalid={invalidSteps.includes(1)}>
                                    {data.name || '—'} ({data.short_name || '—'}) · {data.slug || '—'}
                                    <br />
                                    <span className="text-fg-muted">{domains.map((domain) => `@${domain}`).join(', ') || 'Sin dominios'}</span>
                                </ReviewRow>
                                <ReviewRow label="Microsoft" step={2} onEdit={goTo} invalid={invalidSteps.includes(2)}>
                                    <span className="tabular break-all">{data.tenant_id || 'Sin ID de inquilino'}</span>
                                </ReviewRow>
                                <ReviewRow label="Marca" step={3} onEdit={goTo} invalid={invalidSteps.includes(3)}>
                                    <span className="flex flex-wrap items-center gap-2">
                                        {(['primary', 'accent', 'dark'] as const).map((key) => (
                                            <span key={key} className="tabular inline-flex items-center gap-1.5">
                                                <span aria-hidden="true" className="size-4 rounded-[5px] border border-line" style={{ background: data.colors[key] }} />
                                                {data.colors[key]}
                                            </span>
                                        ))}
                                    </span>
                                    <span className="text-fg-muted">
                                        {data.logo ? data.logo.name : 'Falta el logo'}
                                        {data.logo_dark ? ` · ${data.logo_dark.name}` : ' · sin logo oscuro'}
                                    </span>
                                </ReviewRow>
                                <ReviewRow label="Correo emisor" step={4} onEdit={goTo} invalid={invalidSteps.includes(4)}>
                                    {data.sender_email || '—'}
                                </ReviewRow>
                            </dl>
                            <div className="rounded-card border border-line bg-surface px-4 py-2">
                                <Switch
                                    checked={data.active}
                                    onChange={(checked) => setData('active', checked)}
                                    label="Activar al terminar"
                                    description={
                                        data.active
                                            ? `Su gente puede entrar apenas el administrador de Microsoft de ${shortName} dé el consentimiento.`
                                            : 'Queda lista pero cerrada; la activas después desde Empresas.'
                                    }
                                />
                            </div>
                        </div>
                    )}

                    <div className="mt-auto flex flex-col-reverse gap-2 border-t border-line pt-5 sm:flex-row sm:justify-between lg:border-t-0 lg:pt-0">
                        {step > 1 ? (
                            <Button variant="secondary" size="lg" onClick={() => goTo((step - 1) as WizardStep)}>
                                Atrás
                            </Button>
                        ) : (
                            <span />
                        )}
                        <Button type="submit" size="lg" loading={form.processing} icon={step === 5 ? 'check' : undefined}>
                            {step === 5 && data.active ? 'Crear y activar' : NEXT_LABEL[step]}
                        </Button>
                    </div>
                </form>

                <BrandPreview name={data.short_name} colors={data.colors} className="hidden xl:flex" />
            </div>

            <ConfirmDialog
                open={exitOpen}
                onClose={() => setExitOpen(false)}
                onConfirm={() => router.visit(route('admin.companies.index'))}
                title="¿Salir sin guardar?"
                description="Lo que llevas de esta empresa se pierde. Nada se ha creado todavía."
                confirmLabel="Salir y descartar"
                cancelLabel="Seguir llenando"
            />
        </div>
    );
}

function StepIntro({ title, text, headingRef }: { title: string; text: string; headingRef: RefObject<HTMLHeadingElement | null> }) {
    return (
        <div className="flex flex-col gap-1.5">
            <h1 ref={headingRef} tabIndex={-1} className="font-display text-[24px] leading-tight font-bold tracking-[-0.02em] outline-none md:text-[30px]">
                {title}
            </h1>
            <p className="max-w-[640px] text-[15px] leading-normal text-fg-soft">{text}</p>
        </div>
    );
}

function ReviewRow({
    label,
    step,
    onEdit,
    invalid,
    children,
}: {
    label: string;
    step: WizardStep;
    onEdit: (step: WizardStep) => void;
    invalid: boolean;
    children: ReactNode;
}) {
    return (
        <div className={cx('grid grid-cols-[minmax(0,1fr)_auto] items-start gap-x-4 gap-y-1 px-4 py-3 sm:grid-cols-[140px_minmax(0,1fr)_auto]', invalid && 'bg-status-failure-bg')}>
            <dt className={cx('text-[13px] font-bold', invalid ? 'text-status-failure' : 'text-fg-muted')}>
                {label}
                {invalid ? <span className="sr-only"> (tiene errores)</span> : null}
            </dt>
            <dd className="col-span-2 row-start-2 flex min-w-0 flex-col gap-1 text-sm sm:col-span-1 sm:row-start-1 sm:col-start-2">{children}</dd>
            <dd className="col-start-2 row-start-1 sm:col-start-3">
                <button
                    type="button"
                    onClick={() => onEdit(step)}
                    className="-my-2 inline-flex h-11 items-center rounded-control px-2 text-sm font-bold text-fg underline underline-offset-[3px]"
                >
                    Editar<span className="sr-only"> {label.toLowerCase()}</span>
                </button>
            </dd>
        </div>
    );
}
