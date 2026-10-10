import { Link, router } from '@inertiajs/react';
import { useId, useState, type FormEvent, type ReactNode } from 'react';
import { ProductStateChip } from '@/Components/ai/products/ProductParts';
import { Highlight } from '@/Components/ai/search/HighlightedText';
import { SEARCH_TYPES, searchTypeParam, type SearchType } from '@/Components/ai/search/highlight';
import { Checkbox } from '@/Components/ui/Checkbox';
import { CompanyMark } from '@/Components/ui/CompanyMark';
import { EmptyState } from '@/Components/ui/EmptyState';
import { Icon } from '@/Components/ui/Icon';
import { StatusChip } from '@/Components/ui/StatusChip';
import { GestorLayout } from '@/Layouts/GestorLayout';
import { formatRelative, formatShortDate } from '@/lib/format';
import { route } from '@/lib/route';
import type { SearchIndexProps, SearchQuery } from '@/types/pages/ai-knowledge';
import type { Company, PageProps } from '@/types/shared';

const TYPE_LABELS: Record<SearchType, string> = { docs: 'Documentos', requests: 'Solicitudes', products: 'Productos' };

function toQuery(query: SearchQuery): Record<string, string | number> {
    const params: Record<string, string | number> = { q: query.q };
    const type = searchTypeParam(query.types);
    if (type) params.type = type;
    if (query.company_id !== null) params.company_id = query.company_id;
    if (query.area_id !== null) params.area_id = query.area_id;
    return params;
}

function Group({ id, title, count, children }: { id: string; title: string; count: number; children: ReactNode }) {
    return (
        <section aria-labelledby={id} className="flex flex-col overflow-hidden rounded-card border border-line bg-surface">
            <h2 id={id} className="flex items-center gap-2 px-5 py-3.5 font-display text-lg font-bold">
                {title}
                <span className="tabular rounded-full bg-subtle px-2 font-sans text-xs leading-5 font-bold text-fg-soft">{count}</span>
            </h2>
            {children}
        </section>
    );
}

/** B-Search: one search over docs, requests and products, grouped by type, with the matched words emphasized. */
export default function SearchIndex(props: PageProps<SearchIndexProps>) {
    const { query, results, type_counts, company_counts, areas, took_ms, companies, current_company_id, now } = props;
    const [q, setQ] = useState(query.q);
    const ids = { docs: useId(), requests: useId(), products: useId(), area: useId() };
    const allCompanies = current_company_id === null;
    const companyOf = (id: number): Company | undefined => companies.find((company) => company.id === id);
    const clock = new Date(now);

    const go = (change: Partial<SearchQuery>) => {
        router.get(route('ai.search'), toQuery({ ...query, q: q.trim(), ...change }), { preserveState: true, preserveScroll: true });
    };

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        go({ q: q.trim() });
    };

    const toggleType = (type: SearchType, checked: boolean) => {
        const types = checked ? [...query.types, type] : query.types.filter((item) => item !== type);
        go({ types: types.length === 0 ? [...SEARCH_TYPES] : types });
    };

    const total = results ? results.docs.length + results.requests.length + results.products.length : 0;
    const docWhen = (iso: string) => {
        const relative = formatRelative(iso, clock);
        return relative.startsWith('hoy') ? 'hoy' : relative.startsWith('ayer') ? 'ayer' : formatShortDate(iso);
    };

    return (
        <GestorLayout shared={props} title="Buscar · Gestor IA" tab="docs" mode="it">
            <div className="flex flex-col gap-4">
                <form role="search" onSubmit={submit} className="flex flex-wrap items-center gap-x-4 gap-y-2">
                    <label htmlFor="q" className="sr-only">
                        Buscar en documentación, solicitudes y productos
                    </label>
                    <span className="flex h-14 w-full max-w-[720px] items-center gap-3 rounded-card border border-line-strong bg-surface px-4 has-focus-visible:outline-2 has-focus-visible:outline-offset-2 has-focus-visible:outline-fg">
                        <Icon name="search" size={20} className="shrink-0 text-fg-muted" />
                        <input
                            id="q"
                            type="search"
                            value={q}
                            onChange={(event) => setQ(event.target.value)}
                            placeholder="Busca en documentos, solicitudes y productos"
                            className="min-w-0 flex-1 bg-transparent text-base font-semibold outline-none placeholder:font-normal placeholder:text-fg-muted [&::-webkit-search-cancel-button]:hidden"
                        />
                        {q !== '' ? (
                            <button
                                type="button"
                                aria-label="Borrar búsqueda"
                                onClick={() => setQ('')}
                                className="flex size-11 shrink-0 items-center justify-center rounded-control text-fg-muted hover:bg-subtle hover:text-fg"
                            >
                                <Icon name="x" size={18} />
                            </button>
                        ) : null}
                    </span>
                    {results ? (
                        <span aria-live="polite" className="tabular text-[13px] text-fg-muted">
                            {total} {total === 1 ? 'resultado' : 'resultados'} en {(took_ms / 1000).toLocaleString('es-CO', { maximumFractionDigits: 1 })} s
                        </span>
                    ) : null}
                </form>

                <div className="grid gap-4 lg:grid-cols-[236px_minmax(0,1fr)] lg:items-start">
                    <aside aria-label="Filtros" className="flex flex-col gap-4 rounded-card border border-line bg-surface p-4">
                        <fieldset className="flex flex-col gap-1">
                            <legend className="mb-1 text-[13px] font-bold">Tipo</legend>
                            {SEARCH_TYPES.map((type) => (
                                <div key={type} className="flex items-center justify-between gap-2">
                                    <Checkbox label={TYPE_LABELS[type]} checked={query.types.includes(type)} onChange={(event) => toggleType(type, event.target.checked)} />
                                    <span className="tabular text-xs text-fg-muted">{type_counts[type]}</span>
                                </div>
                            ))}
                        </fieldset>
                        {allCompanies && companies.length > 1 ? (
                            <fieldset className="flex flex-col gap-1">
                                <legend className="mb-1 text-[13px] font-bold">Empresa</legend>
                                {[null, ...companies.map((company) => company.id)].map((id) => (
                                    <label key={id ?? 'all'} className="flex min-h-11 cursor-pointer items-center gap-2.5 text-sm has-focus-visible:outline-2 has-focus-visible:outline-fg">
                                        <input
                                            type="radio"
                                            name="empresa"
                                            checked={query.company_id === id}
                                            onChange={() => go({ company_id: id })}
                                            className="size-[18px] accent-[var(--color-fg)]"
                                        />
                                        <span className="flex-1">{id === null ? 'Todas' : companyOf(id)?.short_name}</span>
                                        {id !== null ? <span className="tabular text-xs text-fg-muted">{company_counts[id] ?? 0}</span> : null}
                                    </label>
                                ))}
                            </fieldset>
                        ) : null}
                        <label htmlFor={ids.area} className="flex flex-col gap-1.5 text-[13px] font-bold">
                            Área
                            <select
                                id={ids.area}
                                value={query.area_id ?? ''}
                                onChange={(event) => go({ area_id: event.target.value === '' ? null : Number(event.target.value) })}
                                className="h-11 rounded-control border border-line-strong bg-surface px-3 text-sm font-normal"
                            >
                                <option value="">Todas las áreas</option>
                                {areas.map((area) => (
                                    <option key={area.id} value={area.id}>
                                        {allCompanies && companies.length > 1 ? `${area.name} · ${companyOf(area.company_id)?.short_name ?? ''}` : area.name}
                                    </option>
                                ))}
                            </select>
                        </label>
                        <p className="text-xs leading-snug text-fg-muted">
                            Busca en el texto de los documentos, en el título y la descripción de las solicitudes y en las fichas de producto. Solo ves lo que tu rol
                            permite.
                        </p>
                    </aside>

                    <div className="flex min-w-0 flex-col gap-4">
                        {!results ? (
                            <EmptyState
                                icon="search"
                                title="¿Qué buscas?"
                                description="Escribe una palabra o una frase: un cliente, un proceso, un código como GIA-139. Busca en manuales, solicitudes y fichas de producto."
                            />
                        ) : total === 0 ? (
                            <EmptyState
                                icon="search"
                                title={`Nada coincide con “${query.q}”`}
                                description="Prueba con menos palabras, revisa la ortografía o quita los filtros de tipo, empresa y área."
                            />
                        ) : (
                            <>
                                {results.docs.length > 0 ? (
                                    <Group id={ids.docs} title="Documentos" count={results.docs.length}>
                                        {results.docs.map((hit) => (
                                            <Link
                                                key={hit.doc.id}
                                                href={route('ai.docs.edit', { doc: hit.doc.id })}
                                                className="flex flex-col gap-1 border-t border-line px-5 py-3.5 text-fg no-underline transition-colors duration-150 hover:bg-ground"
                                            >
                                                <span className="flex flex-wrap items-baseline justify-between gap-x-3">
                                                    <b className="text-sm">
                                                        {hit.doc.title} · {hit.doc.owner.kind === 'project' ? hit.doc.owner.key : hit.doc.owner.name}
                                                    </b>
                                                    <span className="text-xs text-fg-muted">
                                                        versión {hit.doc.version} · {docWhen(hit.doc.updated_at)}
                                                    </span>
                                                </span>
                                                <Highlight text={hit.snippet.text} highlights={hit.snippet.highlights} className="text-[13px] leading-relaxed text-fg-soft" />
                                            </Link>
                                        ))}
                                    </Group>
                                ) : null}

                                {results.requests.length > 0 ? (
                                    <Group id={ids.requests} title="Solicitudes" count={results.requests.length}>
                                        {results.requests.map((hit) => {
                                            const company = companyOf(hit.request.company_id);
                                            return (
                                                <Link
                                                    key={hit.request.key}
                                                    href={route('ai.requests.show', { request: hit.request.key })}
                                                    className="grid grid-cols-[auto_minmax(0,1fr)] items-center gap-x-3 gap-y-1.5 border-t border-line px-5 py-3 text-fg no-underline transition-colors duration-150 hover:bg-ground md:grid-cols-[28px_72px_minmax(0,1fr)_100px_190px] md:min-h-[52px]"
                                                >
                                                    <span className="hidden md:block">{company ? <CompanyMark company={company} size={24} /> : null}</span>
                                                    <b className="tabular text-[13px]">{hit.request.key}</b>
                                                    <Highlight text={hit.title.text} highlights={hit.title.highlights} className="col-span-2 text-sm md:col-span-1" />
                                                    <span className="text-[13px] text-fg-muted">{hit.request.area.name}</span>
                                                    <span className="md:justify-self-start">
                                                        <StatusChip status={hit.request.status} size="sm" />
                                                    </span>
                                                </Link>
                                            );
                                        })}
                                    </Group>
                                ) : null}

                                {results.products.length > 0 ? (
                                    <Group id={ids.products} title="Productos" count={results.products.length}>
                                        {results.products.map((hit) => {
                                            const company = companyOf(hit.product.company_id);
                                            return (
                                                <Link
                                                    key={hit.product.slug}
                                                    href={route('ai.products.show', { product: hit.product.slug })}
                                                    className="grid grid-cols-[28px_minmax(0,1fr)] items-center gap-x-3 gap-y-2 border-t border-line px-5 py-3.5 text-fg no-underline transition-colors duration-150 hover:bg-ground sm:grid-cols-[28px_minmax(0,1fr)_auto]"
                                                >
                                                    <span>{company ? <CompanyMark company={company} size={24} /> : null}</span>
                                                    <span className="flex min-w-0 flex-col gap-0.5">
                                                        <Highlight text={hit.name.text} highlights={hit.name.highlights} className="text-sm font-bold" />
                                                        <Highlight text={hit.snippet.text} highlights={hit.snippet.highlights} className="text-[13px] text-fg-soft" />
                                                    </span>
                                                    <span className="col-start-2 sm:col-start-auto">
                                                        <ProductStateChip state={hit.product.state} />
                                                    </span>
                                                </Link>
                                            );
                                        })}
                                    </Group>
                                ) : null}
                            </>
                        )}
                    </div>
                </div>
            </div>
        </GestorLayout>
    );
}
