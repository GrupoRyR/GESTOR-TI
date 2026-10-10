import { Link, router, useForm } from '@inertiajs/react';
import { useEffect, useState, type FormEvent, type ReactNode } from 'react';
import { KnowledgeTypeIcon } from '@/Components/assistant/KnowledgeBadges';
import { Button, buttonClasses } from '@/Components/ui/Button';
import { ConfirmDialog } from '@/Components/ui/Dialog';
import { Icon } from '@/Components/ui/Icon';
import { Select } from '@/Components/ui/Select';
import { TextArea } from '@/Components/ui/TextArea';
import { TextField } from '@/Components/ui/TextField';
import { cx } from '@/Components/ui/utils';
import { KNOWLEDGE_TYPE_ORDER, KNOWLEDGE_TYPES } from '@/lib/knowledge';
import { bodyFromForm, defaultReviewDate, formValuesFromBody, KNOWLEDGE_FIELDS } from '@/lib/knowledgeFields';
import { formatShortDate } from '@/lib/format';
import { route } from '@/lib/route';
import type { KnowledgeCard, KnowledgeType, KnowledgeVisibility } from '@/types/assistant';
import type { KnowledgeCardPayload, KnowledgeCreateProps, KnowledgeEditProps, KnowledgeOrigin } from '@/types/pages/assistant-knowledge';
import type { Company, FormErrors } from '@/types/shared';
import { KnowledgeTypesHelp, NoPasswordsNote } from './KnowledgeParts';
import { RelatedPicker } from './RelatedPicker';
import { StepsEditor } from './StepsEditor';

/** Client form state: flat text values of every type, so switching type keeps what was typed. */
interface KnowledgeFormState {
    type: KnowledgeType;
    title: string;
    description: string;
    values: Record<string, string>;
    steps: string[];
    company_ids: number[];
    area_id: number | null;
    owner_id: number | null;
    visibility: KnowledgeVisibility;
    review_at: string;
    related_card_keys: string[];
    related_product_slugs: string[];
    unanswered_id: number | null;
    draft_id: number | null;
}

type FormOptions = Omit<KnowledgeCreateProps, 'prefill'>;

export type KnowledgeFormProps = FormOptions & {
    companies: Company[];
    /** Company preselected on a blank card (the one the person works in), null = none. */
    currentCompanyId: number | null;
} & ({ mode: 'create'; prefill: KnowledgeCreateProps['prefill'] } | { mode: 'edit'; card: KnowledgeEditProps['card'] });

function joinNames(names: string[]): string {
    if (names.length <= 1) return names[0] ?? '';
    return `${names.slice(0, -1).join(', ')} y ${names[names.length - 1]}`;
}

function originNote(origin: KnowledgeOrigin): string {
    if (origin.kind === 'unanswered') {
        const times = origin.question.times_asked === 1 ? '1 vez' : `${origin.question.times_asked} veces`;
        return `Viene de una pregunta sin respuesta: “${origin.question.text}” (${times})`;
    }
    const channel = origin.channel === 'telegram' ? 'Telegram' : 'la app';
    return `Viene de un borrador de /aprender de ${origin.author.name} por ${channel}: “${origin.message}”`;
}

function initialState(props: KnowledgeFormProps): KnowledgeFormState {
    const blankReview = defaultReviewDate(props.today);
    if (props.mode === 'edit') {
        const card: KnowledgeCard = props.card;
        const { values, steps } = formValuesFromBody(card);
        return {
            type: card.type,
            title: card.title,
            description: card.description ?? '',
            values,
            steps,
            company_ids: [...card.company_ids],
            area_id: card.area?.id ?? null,
            owner_id: card.owner?.id ?? null,
            visibility: card.visibility,
            review_at: card.review_at,
            related_card_keys: card.related_cards.map((ref) => ref.key),
            related_product_slugs: card.related_products.map((product) => product.slug),
            unanswered_id: null,
            draft_id: null,
        };
    }
    const prefill = props.prefill;
    const preset = prefill?.values ?? {};
    const type = preset.type ?? 'report';
    const { values, steps } = formValuesFromBody(preset.fields ? ({ type, fields: preset.fields } as KnowledgeCard) : null);
    return {
        type,
        title: preset.title ?? '',
        description: preset.description ?? '',
        values,
        steps,
        company_ids: preset.company_ids ?? (props.currentCompanyId !== null ? [props.currentCompanyId] : []),
        area_id: preset.area_id ?? null,
        owner_id: preset.owner_id ?? null,
        visibility: preset.visibility ?? 'company',
        review_at: preset.review_at ?? blankReview,
        related_card_keys: preset.related_card_keys ?? [],
        related_product_slugs: preset.related_product_slugs ?? [],
        unanswered_id: prefill?.origin.kind === 'unanswered' ? prefill.origin.question.id : null,
        draft_id: prefill?.origin.kind === 'draft' ? prefill.origin.draft_id : null,
    };
}

function toPayload(data: KnowledgeFormState): KnowledgeCardPayload {
    const body = bodyFromForm(data.type, data.values, data.steps);
    return {
        type: data.type,
        title: data.title.trim(),
        description: data.description.trim() === '' ? null : data.description.trim(),
        fields: body.fields,
        company_ids: data.company_ids,
        area_id: data.area_id,
        owner_id: data.owner_id,
        visibility: data.visibility,
        review_at: data.review_at,
        related_card_keys: data.related_card_keys,
        related_product_slugs: data.related_product_slugs,
        unanswered_id: data.unanswered_id,
        draft_id: data.draft_id,
    };
}

function Section({ title, className, children }: { title?: string; className?: string; children: ReactNode }) {
    return (
        <section className={cx('flex min-w-0 flex-col gap-3.5 rounded-card border border-line bg-surface px-4 py-4 md:px-[22px] md:py-5', className)}>
            {title ? <h2 className="font-display text-lg leading-tight font-bold">{title}</h2> : null}
            {children}
        </section>
    );
}

const TYPE_HEADING: Record<KnowledgeType, string> = {
    system: 'Datos del sistema',
    report: 'Datos del informe',
    data_flow: 'Datos del flujo',
    procedure: 'Datos del procedimiento',
    glossary: 'Datos del término',
};

/** Shared Create/Edit form of a knowledge card (board C-KnowledgeForm). */
export function KnowledgeForm(props: KnowledgeFormProps) {
    const { area_options, owner_options, related_options, knowledge_managers, type_examples, type_counts, today, companies, errors: serverErrors = {} } = props;
    const editing = props.mode === 'edit';
    const form = useForm<KnowledgeFormState>(initialState(props));
    const [helpOpen, setHelpOpen] = useState(false);
    const [deleteOpen, setDeleteOpen] = useState(false);
    const [deleting, setDeleting] = useState(false);

    useEffect(() => {
        if (Object.keys(serverErrors).length > 0) form.setError(serverErrors as Partial<Record<keyof KnowledgeFormState, string>>);
        // Only the errors the page arrived with; later ones come from the submit.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    const errors = form.errors as FormErrors;
    const { data } = form;
    const defaultReview = defaultReviewDate(today);
    const cancelHref = editing ? route('assistant.knowledge.show', { card: props.card.key }) : route('assistant.knowledge.index');
    const fieldError = (key: string): string | undefined =>
        errors[`fields.${key}`] ?? Object.entries(errors).find(([name]) => name.startsWith(`fields.${key}.`))?.[1];

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.transform((state) => toPayload(state) as unknown as KnowledgeFormState);
        if (props.mode === 'edit') form.put(route('assistant.knowledge.update', { card: props.card.key }), { preserveScroll: true });
        else form.post(route('assistant.knowledge.store'), { preserveScroll: true });
    };

    const destroy = () => {
        if (props.mode !== 'edit') return;
        router.delete(route('assistant.knowledge.destroy', { card: props.card.key }), {
            onStart: () => setDeleting(true),
            onFinish: () => {
                setDeleting(false);
                setDeleteOpen(false);
            },
        });
    };

    const setValue = (key: string, value: string) => form.setData('values', { ...data.values, [key]: value });

    // Company select: each company or all of them (stored as ids).
    const BOTH = 'all';
    const companyValue = data.company_ids.length === 0 ? '' : data.company_ids.length > 1 ? BOTH : String(data.company_ids[0]);
    const companyOptions = [
        ...companies.map((company) => ({ value: String(company.id), label: company.short_name })),
        ...(companies.length > 1 ? [{ value: BOTH, label: joinNames(companies.map((company) => company.short_name)) }] : []),
    ];
    const areaOptions = area_options
        .filter((area) => data.company_ids.length === 0 || data.company_ids.includes(area.company_id))
        .map((area) => {
            const company = data.company_ids.length !== 1 ? companies.find((item) => item.id === area.company_id)?.short_name : undefined;
            return { value: String(area.id), label: company ? `${area.name} · ${company}` : area.name };
        });
    const managers = joinNames(knowledge_managers.map((person) => person.name));

    const title = editing ? `Editar ${props.card.key}` : 'Nueva ficha';
    const origin = props.mode === 'create' && props.prefill ? originNote(props.prefill.origin) : null;

    const actions = (
        <>
            <Link href={cancelHref} className={buttonClasses({ variant: 'secondary' })}>
                Cancelar
            </Link>
            <Button type="submit" loading={form.processing}>
                Guardar
            </Button>
        </>
    );

    const typeInfo = KNOWLEDGE_TYPES[data.type];

    return (
        <form onSubmit={submit} noValidate className="flex min-w-0 flex-col gap-3.5">
            <div className="flex flex-wrap items-end justify-between gap-x-6 gap-y-3">
                <div className="flex min-w-0 flex-col gap-1">
                    <h1 className="font-display text-2xl leading-tight font-bold tracking-[-0.02em] md:text-[30px]">{title}</h1>
                    {editing ? <p className="text-sm text-fg-muted">{props.card.title}</p> : null}
                    {origin ? <p className="text-sm text-fg-muted">{origin}</p> : null}
                </div>
                <div className="flex gap-2 max-md:hidden">{actions}</div>
            </div>

            <NoPasswordsNote />

            {Object.keys(errors).length > 0 ? (
                <p role="alert" className="flex items-center gap-2 text-sm font-semibold text-status-failure">
                    <Icon name="alert-triangle" size={16} />
                    {Object.keys(errors).length === 1 ? 'Falta corregir 1 campo.' : `Falta corregir ${Object.keys(errors).length} campos.`}
                </p>
            ) : null}

            {editing ? (
                <div className="flex flex-wrap items-center gap-3 rounded-card border border-line bg-surface px-4 py-3 md:px-[22px]">
                    <KnowledgeTypeIcon type={data.type} size="lg" />
                    <span className="flex min-w-0 flex-col">
                        <b className="text-[15px]">Tipo: {typeInfo.label}</b>
                        <span className="text-[13px] text-fg-muted">El tipo no cambia después de crear la ficha. Si es otro, crea una ficha nueva.</span>
                    </span>
                    <Button variant="quiet" className="ml-auto" onClick={() => setHelpOpen(true)}>
                        Ver tipos de ficha
                    </Button>
                </div>
            ) : (
                <fieldset className="flex min-w-0 flex-col gap-2.5">
                    <legend className="sr-only">Tipo de ficha</legend>
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <span aria-hidden="true" className="text-[13px] font-semibold">
                            Tipo de ficha
                        </span>
                        <Button variant="quiet" icon="question" onClick={() => setHelpOpen(true)}>
                            Ver tipos de ficha
                        </Button>
                    </div>
                    <div className="grid grid-cols-2 gap-2.5 lg:grid-cols-3 xl:grid-cols-5">
                        {KNOWLEDGE_TYPE_ORDER.map((type) => {
                            const checked = data.type === type;
                            return (
                                <label
                                    key={type}
                                    className={cx(
                                        'relative flex min-h-14 cursor-pointer items-center gap-2.5 rounded-control bg-surface p-3 transition-colors duration-150 sm:min-h-[72px] sm:items-start sm:gap-3 sm:p-3.5',
                                        'has-focus-visible:outline-2 has-focus-visible:outline-offset-2 has-focus-visible:outline-fg',
                                        checked ? 'border-2 border-fg p-[11px] sm:p-[13px]' : 'border border-line-strong hover:border-fg-muted',
                                    )}
                                >
                                    <input
                                        type="radio"
                                        name="tipo"
                                        value={type}
                                        checked={checked}
                                        onChange={() => form.setData('type', type)}
                                        className="sr-only"
                                    />
                                    <KnowledgeTypeIcon type={type} size="md" className="max-sm:hidden" />
                                    <span className="flex min-w-0 flex-1 flex-col gap-0.5 pr-5 sm:pr-4">
                                        <b className="text-sm">{KNOWLEDGE_TYPES[type].label}</b>
                                        <span className="text-xs leading-snug text-fg-muted max-sm:hidden">{KNOWLEDGE_TYPES[type].hint}</span>
                                    </span>
                                    {checked ? (
                                        <span aria-hidden="true" className="absolute top-2 right-2 flex size-5 items-center justify-center rounded-full bg-fg text-on-fg">
                                            <Icon name="check" size={12} strokeWidth={3} />
                                        </span>
                                    ) : null}
                                </label>
                            );
                        })}
                    </div>
                </fieldset>
            )}

            <div className="grid gap-4 xl:grid-cols-[minmax(0,1.45fr)_minmax(0,1fr)] xl:items-start">
                <Section title={TYPE_HEADING[data.type]}>
                    <TextField
                        label="Título"
                        value={data.title}
                        onChange={(event) => form.setData('title', event.target.value)}
                        error={errors.title}
                        placeholder="Ej.: Informe de ventas diario"
                        required
                    />
                    {KNOWLEDGE_FIELDS[data.type].map((field) => {
                        const error = fieldError(field.key);
                        if (field.steps) {
                            return (
                                <StepsEditor
                                    key={field.key}
                                    label={field.label}
                                    steps={data.steps}
                                    onChange={(steps) => form.setData('steps', steps)}
                                    placeholder={field.placeholder}
                                    hint={field.hint}
                                    error={error}
                                    required={field.required}
                                />
                            );
                        }
                        const common = {
                            label: field.label,
                            value: data.values[field.key] ?? '',
                            placeholder: field.placeholder,
                            hint: error ? undefined : field.hint,
                            error,
                            optional: !field.required,
                            required: field.required,
                        };
                        return field.multiline ? (
                            <TextArea key={field.key} {...common} rows={2} onChange={(event) => setValue(field.key, event.target.value)} />
                        ) : (
                            <TextField key={field.key} {...common} onChange={(event) => setValue(field.key, event.target.value)} />
                        );
                    })}
                    <TextArea
                        label="Descripción"
                        optional
                        rows={3}
                        value={data.description}
                        onChange={(event) => form.setData('description', event.target.value)}
                        error={errors.description}
                        hint="Contexto libre que el asistente lee junto con los campos."
                    />
                </Section>

                <div className="flex min-w-0 flex-col gap-4">
                    <Section>
                        <fieldset aria-describedby={errors.visibility ? 'visibility-error' : undefined} className="flex min-w-0 flex-col gap-2.5">
                            <legend className="mb-2.5 font-display text-lg leading-tight font-bold">Quién la ve</legend>
                            {(
                                [
                                    {
                                        value: 'it',
                                        icon: 'lock',
                                        label: 'Solo TI',
                                        text: `Solo quienes administran la base${managers ? ` (hoy ${managers})` : ''}. El asistente no la usa para responder a nadie más.`,
                                    },
                                    {
                                        value: 'company',
                                        icon: 'employees',
                                        label: 'Toda la empresa',
                                        text: 'El asistente la puede citar a cualquier persona con acceso al Asistente, en la app y en Telegram.',
                                    },
                                ] as const
                            ).map((option) => {
                                const checked = data.visibility === option.value;
                                return (
                                    <label
                                        key={option.value}
                                        className={cx(
                                            'flex cursor-pointer items-start gap-3 rounded-control p-3.5 transition-colors duration-150',
                                            'has-focus-visible:outline-2 has-focus-visible:outline-offset-2 has-focus-visible:outline-fg',
                                            checked ? 'border-2 border-fg p-[13px]' : 'border border-line-strong hover:border-fg-muted',
                                        )}
                                    >
                                        <input
                                            type="radio"
                                            name="visibilidad"
                                            value={option.value}
                                            checked={checked}
                                            onChange={() => form.setData('visibility', option.value)}
                                            className="peer sr-only"
                                        />
                                        <span
                                            aria-hidden="true"
                                            className={cx(
                                                'mt-0.5 flex size-5 shrink-0 items-center justify-center rounded-full border-2',
                                                checked ? 'border-fg bg-fg text-on-fg' : 'border-line-strong',
                                            )}
                                        >
                                            {checked ? <span className="size-2 rounded-full bg-on-fg" /> : null}
                                        </span>
                                        <span className="flex min-w-0 flex-col gap-0.5">
                                            <b className="inline-flex items-center gap-1.5 text-sm">
                                                <Icon name={option.icon} size={14} />
                                                {option.label}
                                            </b>
                                            <span className="text-[13px] leading-snug text-fg-muted">{option.text}</span>
                                        </span>
                                    </label>
                                );
                            })}
                            {errors.visibility ? (
                                <p id="visibility-error" className="text-[13px] font-semibold text-status-failure">
                                    {errors.visibility}
                                </p>
                            ) : null}
                        </fieldset>
                    </Section>

                    <Section className="sm:grid sm:grid-cols-2 sm:gap-x-3.5">
                        <Select
                            label="Empresa"
                            value={companyValue}
                            placeholder="Elige la empresa"
                            options={companyOptions}
                            error={errors.company_ids}
                            onChange={(event) => {
                                const value = event.target.value;
                                const ids = value === BOTH ? companies.map((company) => company.id) : value === '' ? [] : [Number(value)];
                                const area = area_options.find((item) => item.id === data.area_id);
                                form.setData({ ...data, company_ids: ids, area_id: area && ids.includes(area.company_id) ? data.area_id : null });
                            }}
                        />
                        <Select
                            label="Área"
                            optional
                            value={data.area_id === null ? '' : String(data.area_id)}
                            placeholder="Sin área"
                            options={areaOptions}
                            error={errors.area_id}
                            onChange={(event) => form.setData('area_id', event.target.value === '' ? null : Number(event.target.value))}
                        />
                        <Select
                            label="Dueño"
                            optional
                            value={data.owner_id === null ? '' : String(data.owner_id)}
                            placeholder="Sin dueño"
                            options={owner_options.map((person) => ({ value: String(person.id), label: person.name }))}
                            error={errors.owner_id}
                            onChange={(event) => form.setData('owner_id', event.target.value === '' ? null : Number(event.target.value))}
                        />
                        <TextField
                            label="Fecha de revisión"
                            type="date"
                            min={today}
                            value={data.review_at}
                            onChange={(event) => form.setData('review_at', event.target.value)}
                            error={errors.review_at}
                            hint={
                                data.review_at === defaultReview ? (
                                    '6 meses desde hoy'
                                ) : (
                                    <button
                                        type="button"
                                        onClick={() => form.setData('review_at', defaultReview)}
                                        className="font-semibold text-fg underline underline-offset-2"
                                    >
                                        Volver a 6 meses ({formatShortDate(defaultReview)} {defaultReview.slice(0, 4)})
                                    </button>
                                )
                            }
                            required
                        />
                        <div className="sm:col-span-2">
                            <RelatedPicker
                                options={related_options}
                                cardKeys={data.related_card_keys}
                                productSlugs={data.related_product_slugs}
                                error={errors.related_card_keys ?? errors.related_product_slugs}
                                onChange={({ cardKeys, productSlugs }) =>
                                    form.setData({ ...data, related_card_keys: cardKeys, related_product_slugs: productSlugs })
                                }
                            />
                        </div>
                    </Section>

                    {editing ? (
                        <section aria-labelledby="danger-zone" className="flex flex-col gap-2.5 rounded-card border border-status-failure-line bg-surface px-4 py-4 md:px-[22px]">
                            <h2 id="danger-zone" className="text-sm font-bold">
                                Eliminar la ficha
                            </h2>
                            <p className="text-[13px] text-fg-soft">El asistente deja de usarla para responder. No se puede deshacer.</p>
                            <Button variant="danger" className="self-start" onClick={() => setDeleteOpen(true)}>
                                Eliminar ficha
                            </Button>
                        </section>
                    ) : null}
                </div>
            </div>

            <div className="flex flex-wrap justify-end gap-2 md:hidden [&>*]:flex-1">{actions}</div>

            <KnowledgeTypesHelp open={helpOpen} onClose={() => setHelpOpen(false)} counts={type_counts} examples={type_examples} />

            {editing ? (
                <ConfirmDialog
                    open={deleteOpen}
                    onClose={() => setDeleteOpen(false)}
                    onConfirm={destroy}
                    processing={deleting}
                    title={`¿Eliminar ${props.card.key} ${props.card.title}?`}
                    description="El asistente dejará de usarla para responder y las respuestas anteriores que la citaron quedarán sin el enlace. No se puede deshacer."
                    confirmLabel="Eliminar ficha"
                />
            ) : null}
        </form>
    );
}
