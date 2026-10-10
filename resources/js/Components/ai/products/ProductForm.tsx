import { Link, useForm } from '@inertiajs/react';
import { useEffect, useId, useState, type FormEvent, type ReactNode } from 'react';
import { Button, buttonClasses } from '@/Components/ui/Button';
import { CompanyMark } from '@/Components/ui/CompanyMark';
import { Icon } from '@/Components/ui/Icon';
import { Select } from '@/Components/ui/Select';
import { StatusChip } from '@/Components/ui/StatusChip';
import { TextArea } from '@/Components/ui/TextArea';
import { TextField } from '@/Components/ui/TextField';
import { cx } from '@/Components/ui/utils';
import { route } from '@/lib/route';
import { slugify } from '@/lib/slug';
import type { ProductKind, ProductState } from '@/types/ai';
import type { LinkableRequest, ProductFormData, ProductsCreateProps, ProductsEditProps } from '@/types/pages/ai-knowledge';
import type { Company, FormErrors } from '@/types/shared';
import { ProductStateChip } from './ProductParts';

const KIND_LABELS: Record<ProductKind, string> = { web: 'Web', robot: 'Robot', integration: 'Integración', mobile: 'Celular' };
const KIND_ORDER: ProductKind[] = ['web', 'robot', 'integration', 'mobile'];
/** Board order of the state options: from the first build to retirement. */
const STATE_OPTIONS: ProductState[] = ['development', 'pilot', 'production', 'retired'];

export type ProductFormProps = ({ mode: 'create' } & ProductsCreateProps) | ({ mode: 'edit' } & ProductsEditProps);

function Section({ title, children }: { title: string; children: ReactNode }) {
    const id = useId();
    return (
        <section aria-labelledby={id} className="flex min-w-0 flex-col gap-4 rounded-card border border-line bg-surface px-4 py-4 md:px-[22px] md:py-5">
            <h2 id={id} className="font-display text-lg leading-tight font-bold">
                {title}
            </h2>
            {children}
        </section>
    );
}

/** Radio shown as a bordered option (state and app kind). */
function OptionRadio({ name, checked, onChange, children }: { name: string; checked: boolean; onChange: () => void; children: ReactNode }) {
    return (
        <label
            className={cx(
                'flex min-h-11 cursor-pointer items-center gap-2.5 rounded-control border bg-surface px-3 text-sm font-semibold',
                'has-focus-visible:outline-2 has-focus-visible:outline-offset-2 has-focus-visible:outline-fg',
                checked ? 'border-2 border-fg px-[11px]' : 'border-line-strong hover:border-fg-muted',
            )}
        >
            <input type="radio" name={name} checked={checked} onChange={onChange} className="size-4 shrink-0 accent-brand-button" />
            {children}
        </label>
    );
}

function joinNames(names: string[]): string {
    if (names.length <= 1) return names[0] ?? '';
    return `${names.slice(0, -1).join(', ')} y ${names[names.length - 1]}`;
}

/** "Solicitudes vinculadas": linked rows with "Quitar", plus a search to link another open request. */
function LinkedRequests({
    requests,
    keys,
    onChange,
    error,
}: {
    requests: LinkableRequest[];
    keys: string[];
    onChange: (keys: string[]) => void;
    error?: string;
}) {
    const [query, setQuery] = useState('');
    const [notice, setNotice] = useState('');
    const labelId = useId();
    const linked = keys.map((key) => requests.find((request) => request.key === key) ?? { key, title: '', status: null });
    const q = query.trim().toLowerCase();
    const matches = q === '' ? [] : requests.filter((request) => !keys.includes(request.key) && `${request.key} ${request.title}`.toLowerCase().includes(q)).slice(0, 5);

    const link = (request: LinkableRequest) => {
        onChange([...keys, request.key]);
        setQuery('');
        setNotice(`${request.key} quedó vinculada.`);
    };
    const linkTyped = () => {
        const exact = matches.find((request) => request.key.toLowerCase() === q);
        const pick = exact ?? (matches.length === 1 ? matches[0] : undefined);
        if (pick) link(pick);
        else setNotice(matches.length === 0 ? 'Ninguna solicitud abierta coincide.' : 'Elige una de la lista.');
    };

    return (
        <div className="flex flex-col gap-2">
            <span id={labelId} className="text-[13px] font-semibold">
                Solicitudes vinculadas
            </span>
            {linked.length > 0 ? (
                <ul aria-labelledby={labelId} className="flex flex-col overflow-hidden rounded-[12px] border border-line">
                    {linked.map((request, index) => (
                        <li
                            key={request.key}
                            className={cx(
                                'grid min-h-[52px] grid-cols-[minmax(0,1fr)_44px] items-center gap-x-2.5 py-2 pr-1 pl-3.5 sm:grid-cols-[64px_minmax(0,1fr)_auto_44px]',
                                index > 0 && 'border-t border-line',
                            )}
                        >
                            <span className="flex min-w-0 flex-col gap-1 sm:contents">
                                <b className="tabular text-[13px]">{request.key}</b>
                                <span className="min-w-0 text-sm sm:truncate">{request.title}</span>
                                {request.status ? <StatusChip status={request.status} size="sm" className="self-start sm:self-auto" /> : null}
                            </span>
                            <button
                                type="button"
                                aria-label={`Quitar ${request.key}`}
                                onClick={() => {
                                    onChange(keys.filter((key) => key !== request.key));
                                    setNotice(`${request.key} ya no está vinculada.`);
                                }}
                                className="flex size-11 items-center justify-center rounded-control text-fg-soft hover:bg-subtle hover:text-fg"
                            >
                                <Icon name="x" size={16} />
                            </button>
                        </li>
                    ))}
                </ul>
            ) : (
                <p className="rounded-[12px] border border-dashed border-line-strong px-3.5 py-3 text-[13px] text-fg-muted">Ninguna solicitud vinculada todavía.</p>
            )}
            <div className="flex gap-2">
                <label className="flex h-11 min-w-0 flex-1 items-center gap-2 rounded-control border border-line-strong bg-surface px-3 text-sm has-focus-visible:outline-2 has-focus-visible:outline-fg">
                    <Icon name="search" size={16} className="shrink-0 text-fg-muted" />
                    <input
                        type="search"
                        value={query}
                        onChange={(event) => {
                            setQuery(event.target.value);
                            setNotice('');
                        }}
                        onKeyDown={(event) => {
                            if (event.key === 'Enter') {
                                event.preventDefault();
                                linkTyped();
                            }
                        }}
                        aria-label="Buscar solicitud para vincular"
                        placeholder="Buscar solicitud por número o título"
                        className="min-w-0 flex-1 bg-transparent text-fg outline-none placeholder:text-fg-muted"
                    />
                </label>
                <Button variant="secondary" onClick={linkTyped} disabled={q === ''}>
                    Vincular
                </Button>
            </div>
            {matches.length > 0 ? (
                <ul aria-label="Solicitudes que coinciden" className="flex flex-col overflow-hidden rounded-[12px] border border-line">
                    {matches.map((request, index) => (
                        <li key={request.key} className={cx(index > 0 && 'border-t border-line')}>
                            <button
                                type="button"
                                onClick={() => link(request)}
                                className="flex min-h-11 w-full items-center gap-2.5 px-3.5 py-2 text-left text-sm hover:bg-ground"
                            >
                                <Icon name="plus" size={14} className="shrink-0 text-fg-muted" />
                                <b className="tabular shrink-0 text-[13px]">{request.key}</b>
                                <span className="min-w-0 flex-1 truncate">{request.title}</span>
                                <span className="sr-only">Vincular</span>
                            </button>
                        </li>
                    ))}
                </ul>
            ) : null}
            <p role="status" className="text-[13px] text-fg-muted empty:hidden">
                {notice}
            </p>
            {error ? (
                <p className="flex items-start gap-1.5 text-[13px] font-semibold text-status-failure">
                    <Icon name="alert-triangle" size={14} className="mt-0.5 shrink-0" />
                    {error}
                </p>
            ) : null}
        </div>
    );
}

/** Shared Create/Edit form of a product (board C-ProductForm). */
export function ProductForm(props: ProductFormProps & { companies: Company[] }) {
    const { areas, owners, hosts, requests, companies, errors: serverErrors = {} } = props;
    const editing = props.mode === 'edit';
    const form = useForm<ProductFormData>(props.form);
    // On create the slug follows the name until the person types one.
    const [slugTouched, setSlugTouched] = useState(editing || props.form.slug !== '');

    useEffect(() => {
        if (Object.keys(serverErrors).length > 0) form.setError(serverErrors as Partial<Record<keyof ProductFormData, string>>);
        // Only the errors the page arrived with; later ones come from the submit.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    const errors = form.errors as FormErrors;
    const { data } = form;
    const errorCount = Object.keys(errors).length;

    const submit = (event: FormEvent) => {
        event.preventDefault();
        if (props.mode === 'edit') form.put(route('ai.products.update', { product: props.product.slug }), { preserveScroll: true });
        else form.post(route('ai.products.store'), { preserveScroll: true });
    };

    const cancelHref = props.mode === 'edit' ? route('ai.products.show', { product: props.product.slug }) : route('ai.products.index');

    // Company select: each company or all of them (stored as ids).
    const BOTH = 'all';
    const companyValue = data.company_ids.length === 0 ? '' : data.company_ids.length > 1 ? BOTH : String(data.company_ids[0]);
    const companyOptions = [
        ...companies.map((company) => ({ value: String(company.id), label: company.short_name })),
        ...(companies.length > 1 ? [{ value: BOTH, label: joinNames(companies.map((company) => company.short_name)) }] : []),
    ];
    const areaOptions = areas
        .filter((area) => data.company_ids.length === 0 || data.company_ids.includes(area.company_id))
        .map((area) => {
            const company = data.company_ids.length !== 1 ? companies.find((item) => item.id === area.company_id)?.short_name : undefined;
            return { value: String(area.id), label: company ? `${area.name} · ${company}` : area.name };
        });
    const ownerOptions = owners.map((person) => ({ value: String(person.id), label: person.job_title ? `${person.name} · ${person.job_title}` : person.name }));
    const hostOptions = (hosts.includes(data.host) || data.host === '' ? hosts : [data.host, ...hosts]).map((host) => ({ value: host, label: host }));

    const company = props.mode === 'edit' ? companies.find((item) => item.id === props.product.company_id) : undefined;
    const title = props.mode === 'edit' ? `Editar producto · ${props.product.name}` : 'Nuevo producto';

    const actions = (
        <>
            <Link href={cancelHref} className={buttonClasses({ variant: 'secondary' })}>
                Cancelar
            </Link>
            <Button type="submit" loading={form.processing}>
                {editing ? 'Guardar cambios' : 'Crear producto'}
            </Button>
        </>
    );

    return (
        <form onSubmit={submit} noValidate aria-label={title} className="flex min-w-0 flex-col gap-4">
            <div className="flex flex-wrap items-center justify-between gap-x-6 gap-y-3">
                <div className="flex min-w-0 items-center gap-3.5">
                    {company ? (
                        <CompanyMark company={company} size={44} />
                    ) : (
                        <span aria-hidden="true" className="flex size-11 shrink-0 items-center justify-center rounded-icon bg-subtle text-fg-soft">
                            <Icon name="plus" size={20} />
                        </span>
                    )}
                    <h1 className="min-w-0 font-display text-2xl leading-tight font-bold tracking-[-0.02em] md:text-[28px]">{title}</h1>
                </div>
                <div className="flex gap-2 max-md:hidden">{actions}</div>
            </div>

            {errorCount > 0 ? (
                <p role="alert" className="flex items-center gap-2 text-sm font-semibold text-status-failure">
                    <Icon name="alert-triangle" size={16} />
                    {errorCount === 1 ? 'Falta corregir 1 campo.' : `Falta corregir ${errorCount} campos.`}
                </p>
            ) : null}

            <div className="grid gap-4 xl:grid-cols-2 xl:items-start">
                <div className="flex min-w-0 flex-col gap-4">
                    <Section title="Datos">
                        <div className="grid grid-cols-1 gap-3.5 sm:grid-cols-6">
                            <TextField
                                label="Nombre"
                                value={data.name}
                                error={errors.name}
                                containerClassName="sm:col-span-3"
                                autoComplete="off"
                                onChange={(event) => {
                                    const name = event.target.value;
                                    form.setData((current) => ({ ...current, name, slug: slugTouched ? current.slug : slugify(name) }));
                                }}
                            />
                            <TextField
                                label="Slug"
                                value={data.slug}
                                error={errors.slug}
                                hint={slugTouched || editing ? 'Solo minúsculas y guiones.' : 'Se arma solo a partir del nombre. Solo minúsculas y guiones.'}
                                containerClassName="sm:col-span-3"
                                className="font-mono"
                                autoComplete="off"
                                spellCheck={false}
                                onChange={(event) => {
                                    setSlugTouched(true);
                                    form.setData('slug', event.target.value);
                                }}
                            />
                            <Select
                                label="Empresa"
                                value={companyValue}
                                options={companyOptions}
                                placeholder={companyValue === '' ? 'Elige la empresa' : undefined}
                                error={errors.company_ids}
                                containerClassName="sm:col-span-2"
                                onChange={(event) => {
                                    const value = event.target.value;
                                    const ids = value === '' ? [] : value === BOTH ? companies.map((item) => item.id) : [Number(value)];
                                    const area = areas.find((item) => item.id === data.area_id);
                                    form.setData({ ...data, company_ids: ids, area_id: area && ids.includes(area.company_id) ? data.area_id : null });
                                }}
                            />
                            <Select
                                label="Área"
                                value={data.area_id === null ? '' : String(data.area_id)}
                                options={areaOptions}
                                placeholder="Elige el área"
                                error={errors.area_id}
                                containerClassName="sm:col-span-2"
                                onChange={(event) => form.setData('area_id', event.target.value === '' ? null : Number(event.target.value))}
                            />
                            <Select
                                label="Dueño"
                                value={data.owner_id === null ? '' : String(data.owner_id)}
                                options={ownerOptions}
                                placeholder="[DUEÑO]"
                                error={errors.owner_id}
                                hint={data.owner_id === null && !errors.owner_id ? 'Falta definir quién responde por la app.' : undefined}
                                className={data.owner_id === null && !errors.owner_id ? 'border-status-maintenance font-semibold text-status-maintenance' : undefined}
                                containerClassName="sm:col-span-2"
                                onChange={(event) => form.setData('owner_id', event.target.value === '' ? null : Number(event.target.value))}
                            />
                            <TextArea
                                label="Descripción"
                                rows={2}
                                value={data.description}
                                error={errors.description}
                                containerClassName="sm:col-span-6"
                                onChange={(event) => form.setData('description', event.target.value)}
                            />
                        </div>
                    </Section>

                    <Section title="Relaciones">
                        <LinkedRequests requests={requests} keys={data.request_keys} error={errors.request_keys} onChange={(keys) => form.setData('request_keys', keys)} />
                    </Section>
                </div>

                <div className="flex min-w-0 flex-col gap-4">
                    <Section title="Versión y estado">
                        <div className="grid grid-cols-1 items-start gap-3.5 sm:grid-cols-[180px_minmax(0,1fr)]">
                            <TextField
                                label="Versión actual"
                                value={data.version}
                                error={errors.version}
                                hint="Formato v1.2.3"
                                className="font-mono"
                                autoComplete="off"
                                spellCheck={false}
                                onChange={(event) => form.setData('version', event.target.value)}
                            />
                            <fieldset aria-describedby={errors.state ? 'product-state-error' : undefined} className="flex min-w-0 flex-col">
                                <legend className="mb-1.5 text-[13px] font-semibold">Estado</legend>
                                <div className="grid grid-cols-2 gap-2">
                                    {STATE_OPTIONS.map((state) => (
                                        <OptionRadio key={state} name="product-state" checked={data.state === state} onChange={() => form.setData('state', state)}>
                                            <ProductStateChip state={state} />
                                        </OptionRadio>
                                    ))}
                                </div>
                                {errors.state ? (
                                    <p id="product-state-error" className="mt-1.5 text-[13px] font-semibold text-status-failure">
                                        {errors.state}
                                    </p>
                                ) : null}
                            </fieldset>
                        </div>
                    </Section>

                    <Section title="Dónde corre">
                        <fieldset className="flex min-w-0 flex-col">
                            <legend className="mb-1.5 text-[13px] font-semibold">Tipo de app</legend>
                            <div className="grid grid-cols-2 gap-2 sm:grid-cols-4">
                                {KIND_ORDER.map((kind) => (
                                    <OptionRadio key={kind} name="product-kind" checked={data.kind === kind} onChange={() => form.setData('kind', kind)}>
                                        {KIND_LABELS[kind]}
                                    </OptionRadio>
                                ))}
                            </div>
                        </fieldset>
                        <div className="grid grid-cols-1 gap-3.5 sm:grid-cols-2">
                            <TextField label="Stack" value={data.stack} error={errors.stack} autoComplete="off" onChange={(event) => form.setData('stack', event.target.value)} />
                            <Select
                                label="Dónde corre"
                                value={data.host}
                                options={hostOptions}
                                placeholder={data.host === '' ? 'Elige dónde corre' : undefined}
                                error={errors.host}
                                onChange={(event) => form.setData('host', event.target.value)}
                            />
                            <TextField
                                label="URL"
                                type="url"
                                inputMode="url"
                                value={data.url}
                                error={errors.url}
                                optional
                                autoComplete="off"
                                spellCheck={false}
                                className={data.url.startsWith('[') ? 'font-semibold text-status-maintenance' : undefined}
                                onChange={(event) => form.setData('url', event.target.value)}
                            />
                            <div className="flex min-w-0 flex-col gap-1.5">
                                <span id="product-repo-label" className="text-[13px] font-semibold text-fg-soft">
                                    Repositorio de GitHub
                                </span>
                                <span
                                    role="textbox"
                                    aria-labelledby="product-repo-label"
                                    aria-disabled="true"
                                    aria-readonly="true"
                                    className="flex h-11 items-center gap-2 rounded-control border border-dashed border-line-strong bg-subtle px-3.5 text-sm text-fg-muted"
                                >
                                    <Icon name="code" size={16} className="shrink-0" />
                                    Se vincula desde la ficha, en Actividad en GitHub
                                </span>
                            </div>
                        </div>
                    </Section>

                    <p className="flex items-start gap-2 px-1 text-[13px] leading-snug text-fg-muted">
                        <Icon name="history" size={14} className="mt-0.5 shrink-0" />
                        Cada cambio queda en la auditoría con quién y cuándo.
                    </p>
                </div>
            </div>

            <div className="flex flex-wrap justify-end gap-2 md:hidden [&>*]:flex-1">{actions}</div>
        </form>
    );
}
