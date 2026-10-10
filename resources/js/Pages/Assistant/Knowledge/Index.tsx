import { Link, router } from '@inertiajs/react';
import { useEffect, useRef, useState, type ReactNode } from 'react';
import { FilterSelect } from '@/Components/admin/AdminParts';
import { AssistantPhoneHeader } from '@/Components/assistant/ChatBubbles';
import { KnowledgeList } from '@/Components/assistant/knowledge/KnowledgeList';
import { KnowledgeTypesHelp } from '@/Components/assistant/knowledge/KnowledgeParts';
import { Button, buttonClasses } from '@/Components/ui/Button';
import { Drawer } from '@/Components/ui/Drawer';
import { EmptyState } from '@/Components/ui/EmptyState';
import { Icon } from '@/Components/ui/Icon';
import { IconButton } from '@/Components/ui/IconButton';
import { Pagination } from '@/Components/ui/Pagination';
import { cx } from '@/Components/ui/utils';
import { AssistantLayout } from '@/Layouts/AssistantLayout';
import { can } from '@/lib/can';
import { KNOWLEDGE_TYPE_ORDER, KNOWLEDGE_TYPES } from '@/lib/knowledge';
import { activeFilterCount, DEFAULT_KNOWLEDGE_FILTERS, isKnowledgeFiltered, knowledgeQuery } from '@/lib/knowledgeQuery';
import { route } from '@/lib/route';
import type { KnowledgeType, KnowledgeVisibility } from '@/types/assistant';
import type { KnowledgeFilters, KnowledgeIndexProps } from '@/types/pages/assistant-knowledge';
import type { PageProps } from '@/types/shared';

const SEARCH_DELAY = 300;

function plural(count: number, one: string, many: string): string {
    return `${count.toLocaleString('es-CO')} ${count === 1 ? one : many}`;
}

/** "Revisión vencida (5)" as a switch (desktop) or a pressed chip (phone). */
function OverdueToggle({ count, on, onChange, className }: { count: number; on: boolean; onChange: (on: boolean) => void; className?: string }) {
    return (
        <button
            type="button"
            role="switch"
            aria-checked={on}
            onClick={() => onChange(!on)}
            className={cx(
                'inline-flex h-11 shrink-0 items-center gap-2.5 rounded-control border px-3 text-sm font-semibold transition-colors duration-150',
                on ? 'border-status-maintenance bg-status-maintenance-bg text-status-maintenance' : 'border-line-strong bg-surface text-fg hover:border-fg-muted',
                className,
            )}
        >
            <span
                aria-hidden="true"
                className={cx('relative h-5 w-8 shrink-0 rounded-full transition-colors duration-150', on ? 'bg-status-maintenance' : 'bg-line-strong')}
            >
                <span className={cx('absolute top-0.5 size-4 rounded-full bg-surface transition-[left] duration-150', on ? 'left-3.5' : 'left-0.5')} />
            </span>
            <Icon name="alert-triangle" size={14} className={on ? undefined : 'text-fg-muted'} />
            <span className="tabular">Revisión vencida ({count})</span>
        </button>
    );
}

/** Base de conocimiento: search, type chips, filters and the paginated list (C-Knowledge, C-KnowledgePhone). */
export default function KnowledgeIndex(props: PageProps<KnowledgeIndexProps>) {
    const { cards, filters, counts, area_options, type_examples, today, unanswered_count, drafts_count } = props;
    const user = props.auth.user;
    const manage = can(user, 'assistant.knowledge.manage');
    const companies = props.companies;

    const [search, setSearch] = useState(filters.q ?? '');
    const [helpOpen, setHelpOpen] = useState(false);
    const [filtersOpen, setFiltersOpen] = useState(false);
    const lastSent = useRef(filters.q ?? '');

    const apply = (next: KnowledgeFilters) => {
        router.get(route('assistant.knowledge.index'), knowledgeQuery(next), { preserveState: true, preserveScroll: true, replace: true });
    };

    useEffect(() => {
        if (search === lastSent.current) return;
        const timer = window.setTimeout(() => {
            lastSent.current = search;
            apply({ ...filters, q: search || null });
        }, SEARCH_DELAY);
        return () => window.clearTimeout(timer);
        // `filters` changes after each visit; only typing should schedule a search.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [search]);

    const clearFilters = () => {
        setSearch('');
        lastSent.current = '';
        apply(DEFAULT_KNOWLEDGE_FILTERS);
    };

    const tabCounts = manage ? { drafts: drafts_count, unanswered: unanswered_count } : undefined;
    const createHref = route('assistant.knowledge.create');
    const importHref = route('assistant.import.create');
    const filtered = isKnowledgeFiltered(filters);
    const noCardsYet = counts.total === 0;
    const extraFilters = activeFilterCount(filters);

    const companyOptions = [{ value: '', label: 'todas' }, ...companies.map((company) => ({ value: String(company.id), label: company.short_name }))];
    const areas = filters.company_id === null ? area_options : area_options.filter((area) => area.company_id === filters.company_id);
    const areaOptions = [
        { value: '', label: 'todas' },
        ...areas.map((area) => {
            const company = companies.length > 1 ? companies.find((item) => item.id === area.company_id)?.short_name : undefined;
            return { value: String(area.id), label: company ? `${area.name} · ${company}` : area.name };
        }),
    ];
    const visibilityOptions = [
        { value: '', label: 'todas' },
        { value: 'company', label: 'Toda la empresa' },
        { value: 'it', label: 'Solo TI' },
    ];

    const summary = counts.total === 0
        ? 'El asistente responde solo con lo que está en esta base.'
        : manage
        ? `${plural(counts.total, 'ficha', 'fichas')} · ${counts.by_visibility.company} para toda la empresa y ${counts.by_visibility.it} solo para TI · el asistente responde solo con lo que está aquí`
        : `${plural(counts.total, 'ficha', 'fichas')} que el asistente usa para responder · solo consulta`;
    const phoneSummary = manage && counts.overdue > 0 ? `${plural(counts.total, 'ficha', 'fichas')} · ${counts.overdue} con revisión vencida` : plural(counts.total, 'ficha', 'fichas');

    const selectFilters = (
        <>
            {companies.length > 1 ? (
                <FilterSelect
                    label="Empresa"
                    className="md:w-[180px]"
                    value={filters.company_id === null ? '' : String(filters.company_id)}
                    onChange={(event) => apply({ ...filters, company_id: event.target.value === '' ? null : Number(event.target.value), area_id: null })}
                    options={companyOptions}
                />
            ) : null}
            <FilterSelect
                label="Área"
                className="md:w-[190px]"
                value={filters.area_id === null ? '' : String(filters.area_id)}
                onChange={(event) => apply({ ...filters, area_id: event.target.value === '' ? null : Number(event.target.value) })}
                options={areaOptions}
            />
            {manage ? (
                <FilterSelect
                    label="Visibilidad"
                    className="md:w-[200px]"
                    value={filters.visibility ?? ''}
                    onChange={(event) => apply({ ...filters, visibility: (event.target.value || null) as KnowledgeVisibility | null })}
                    options={visibilityOptions}
                />
            ) : null}
        </>
    );

    const typeChip = (type: KnowledgeType | null, label: string, count: number): ReactNode => {
        const pressed = filters.type === type;
        return (
            <button
                key={type ?? 'all'}
                type="button"
                aria-pressed={pressed}
                onClick={() => apply({ ...filters, type })}
                className={cx(
                    'inline-flex h-11 shrink-0 items-center gap-2 rounded-chip border px-3 text-sm whitespace-nowrap transition-colors duration-150',
                    pressed ? 'border-fg bg-fg font-bold text-on-fg' : 'border-line-strong bg-surface font-semibold text-fg hover:border-fg-muted',
                )}
            >
                {type ? <Icon name={KNOWLEDGE_TYPES[type].icon} size={16} className="max-md:hidden" /> : null}
                {label}
                <span
                    className={cx(
                        'tabular inline-flex h-5 items-center rounded-chip px-1.5 text-xs font-bold',
                        pressed ? 'bg-on-fg/20 text-on-fg' : 'bg-subtle text-fg-soft',
                    )}
                >
                    {count}
                </span>
            </button>
        );
    };

    const phoneActions = (
        <>
            <IconButton icon="question" label="Tipos de ficha" onClick={() => setHelpOpen(true)} />
            {manage ? <IconButton icon="plus" label="Nueva ficha" href={createHref} /> : null}
        </>
    );

    return (
        <AssistantLayout
            shared={props}
            title="Base de conocimiento · Asistente TI"
            tab="knowledge"
            counts={tabCounts}
            phoneHeader={
                <AssistantPhoneHeader
                    title="Base de conocimiento"
                    subtitle={phoneSummary}
                    back={{ href: route('assistant.index'), label: 'Volver al chat del Asistente' }}
                    actions={phoneActions}
                />
            }
        >
            <div className="flex min-w-0 flex-col gap-3">
                <div className="flex flex-wrap items-end justify-between gap-x-6 gap-y-3 max-md:hidden xl:flex-nowrap">
                    <div className="flex min-w-0 flex-col gap-1">
                        <h1 className="font-display text-[30px] leading-tight font-bold tracking-[-0.02em]">Base de conocimiento</h1>
                        <p className="text-sm text-fg-muted">{summary}</p>
                    </div>
                    <div className="flex shrink-0 flex-wrap gap-2">
                        <Button variant="quiet" icon="question" onClick={() => setHelpOpen(true)}>
                            Tipos de ficha
                        </Button>
                        {manage ? (
                            <>
                                <Link href={importHref} className={buttonClasses({ variant: 'secondary' })}>
                                    <Icon name="upload" size={16} />
                                    Importar desde Excel
                                </Link>
                                <Link href={createHref} className={buttonClasses({ variant: 'primary' })}>
                                    <Icon name="plus" size={16} strokeWidth={2.4} />
                                    Nueva ficha
                                </Link>
                            </>
                        ) : null}
                    </div>
                </div>

                {noCardsYet ? (
                    <EmptyState
                        icon="kb-report"
                        title={manage ? 'Todavía no hay fichas' : 'TI todavía no ha documentado nada aquí'}
                        description={
                            manage
                                ? 'El asistente responde solo con lo que está en la base. Empieza por importar el mapa de sistemas desde Excel o crea la primera ficha a mano.'
                                : 'Cuando TI agregue fichas para toda la empresa, aparecen aquí y el asistente las usa para responder.'
                        }
                        actions={
                            manage ? (
                                <>
                                    <Link href={importHref} className={buttonClasses({ variant: 'primary' })}>
                                        <Icon name="upload" size={16} />
                                        Importar desde Excel
                                    </Link>
                                    <Link href={createHref} className={buttonClasses({ variant: 'secondary' })}>
                                        <Icon name="plus" size={16} strokeWidth={2.4} />
                                        Nueva ficha
                                    </Link>
                                    <Button variant="quiet" onClick={() => setHelpOpen(true)}>
                                        Ver los tipos de ficha
                                    </Button>
                                </>
                            ) : undefined
                        }
                    />
                ) : (
                    <>
                        <div role="group" aria-label="Tipo de ficha" className="-mx-4 flex gap-2 overflow-x-auto px-4 pb-1 [scrollbar-width:none] md:mx-0 md:flex-wrap md:overflow-visible md:px-0 md:pb-0">
                            {typeChip(null, 'Todas', counts.total)}
                            {KNOWLEDGE_TYPE_ORDER.map((type) => typeChip(type, KNOWLEDGE_TYPES[type].label, counts.by_type[type]))}
                        </div>

                        <div className="flex flex-wrap items-center gap-2">
                            <label className="relative flex h-11 w-full items-center rounded-control border border-line-strong bg-surface has-focus-visible:outline-2 has-focus-visible:outline-offset-1 has-focus-visible:outline-fg md:w-[260px]">
                                <span className="pointer-events-none absolute left-3 text-fg-muted">
                                    <Icon name="search" size={16} />
                                </span>
                                <input
                                    type="search"
                                    aria-label="Buscar fichas"
                                    placeholder="Título, clave o palabra"
                                    value={search}
                                    onChange={(event) => setSearch(event.target.value)}
                                    className="h-full w-full min-w-0 bg-transparent pr-3 pl-9 text-[15px] outline-none placeholder:text-fg-muted"
                                />
                            </label>
                            <div className="contents max-md:hidden">{selectFilters}</div>
                            <button
                                type="button"
                                onClick={() => setFiltersOpen(true)}
                                className="inline-flex h-11 items-center gap-2 rounded-control border border-line-strong bg-surface px-3 text-sm font-semibold md:hidden"
                            >
                                <Icon name="filter" size={16} />
                                Filtros{extraFilters > 0 ? ` (${extraFilters})` : ''}
                            </button>
                            {manage ? (
                                <OverdueToggle count={counts.overdue} on={filters.overdue} onChange={(overdue) => apply({ ...filters, overdue })} />
                            ) : null}
                            <span className="ml-auto hidden text-[13px] text-fg-muted min-[1560px]:inline">Ordenado por última edición</span>
                        </div>

                        {cards.data.length === 0 ? (
                            <EmptyState
                                icon="search"
                                title={filters.q ? `Ninguna ficha coincide con “${filters.q}”` : 'Ninguna ficha coincide con estos filtros'}
                                description={
                                    manage
                                        ? 'Prueba con otra palabra o quita los filtros. Si de verdad falta, documéntala: el asistente solo responde con lo que está aquí.'
                                        : 'Prueba con otra palabra o quita los filtros. Si de verdad falta, pídele a TI que la documente.'
                                }
                                actions={
                                    <>
                                        <Button variant="secondary" onClick={clearFilters}>
                                            Quitar filtros
                                        </Button>
                                        {manage ? (
                                            <Link href={createHref} className={buttonClasses({ variant: 'primary' })}>
                                                <Icon name="plus" size={16} strokeWidth={2.4} />
                                                Nueva ficha
                                            </Link>
                                        ) : null}
                                    </>
                                }
                            />
                        ) : (
                            <section aria-label="Fichas de la base de conocimiento" className="-mx-4 overflow-hidden border-y border-line bg-surface md:mx-0 md:rounded-card md:border">
                                <KnowledgeList cards={cards.data} companies={companies} today={today} manage={manage} />
                                <Pagination paginated={cards} />
                            </section>
                        )}
                        {filtered && cards.data.length > 0 ? (
                            <Button variant="quiet" className="self-start" onClick={clearFilters}>
                                Quitar filtros
                            </Button>
                        ) : null}
                    </>
                )}
            </div>

            <Drawer open={filtersOpen} onClose={() => setFiltersOpen(false)} title="Filtros" footer={<Button onClick={() => setFiltersOpen(false)}>Ver fichas</Button>}>
                <div className="flex flex-col items-stretch gap-3 [&>label]:w-full">{selectFilters}</div>
            </Drawer>

            <KnowledgeTypesHelp
                open={helpOpen}
                onClose={() => setHelpOpen(false)}
                counts={counts.by_type}
                examples={type_examples}
                createHref={manage ? createHref : undefined}
            />
        </AssistantLayout>
    );
}
