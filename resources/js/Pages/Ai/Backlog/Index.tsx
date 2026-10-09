import { Link, router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { FilterSelect } from '@/Components/ai/triage/InboxParts';
import { teamHours } from '@/Components/ai/triage/triage';
import { WipChip } from '@/Components/ai/KanbanColumn';
import { Button } from '@/Components/ui/Button';
import { CompanyMark } from '@/Components/ui/CompanyMark';
import { ConfirmDialog } from '@/Components/ui/Dialog';
import { EmptyState } from '@/Components/ui/EmptyState';
import { Icon } from '@/Components/ui/Icon';
import { SizeTag } from '@/Components/ui/SizeTag';
import { SortableList } from '@/Components/ui/SortableList';
import { GestorLayout } from '@/Layouts/GestorLayout';
import { formatRelative } from '@/lib/format';
import { route } from '@/lib/route';
import type { RequestSummary } from '@/types/ai';
import type { BacklogFilters, BacklogIndexProps } from '@/types/pages/ai-triage';
import type { PageProps } from '@/types/shared';

const ALL = 'all';
const number = (value: number) => value.toLocaleString('es-CO', { maximumFractionDigits: 1 });

/** Same tracks for the header and every row (desktop). */
const ROW_GRID = 'lg:grid lg:grid-cols-[76px_minmax(0,1fr)_150px_64px_104px_80px_48px] lg:items-center lg:gap-3';

function Priority({ item }: { item: RequestSummary }) {
    const changed = item.priority_final !== null && item.priority_suggested !== null && item.priority_final !== item.priority_suggested;
    if (item.priority_final === null) return <span className="text-fg-muted">—</span>;
    return (
        <span className="inline-flex items-center gap-1.5">
            {changed ? (
                <span className="tabular text-fg-muted line-through">
                    <span className="sr-only">sugerida </span>
                    {item.priority_suggested}
                </span>
            ) : null}
            <b className="tabular">
                {changed ? <span className="sr-only">final </span> : null}
                {item.priority_final}
            </b>
            {changed ? <span className="rounded-[6px] bg-subtle px-1.5 py-px text-[11px] font-semibold text-fg-soft">manual</span> : null}
        </span>
    );
}

/** "Backlog priorizado" (B-Backlog): ranked list, drag or Subir/Bajar, suggested vs. final priority, manual order banner. */
export default function BacklogIndex(props: PageProps<BacklogIndexProps>) {
    const { items, manual_order, wip, filters, areas, companies, current_company_id } = props;
    const [order, setOrder] = useState(items);
    const [confirmReset, setConfirmReset] = useState(false);
    const allCompanies = current_company_id === null;
    const companyOf = (id: number) => companies.find((company) => company.id === id);

    useEffect(() => setOrder(items), [items]);

    const apply = (next: Partial<BacklogFilters>) => {
        const merged = { ...filters, ...next };
        const query: Record<string, number> = {};
        if (merged.area_id !== null) query.area_id = merged.area_id;
        if (merged.company_id !== null) query.company_id = merged.company_id;
        router.get(route('ai.backlog.index'), query, { preserveState: true, preserveScroll: true, replace: true });
    };

    const reorder = (next: RequestSummary[]) => {
        const previous = order;
        setOrder(next);
        router.put(
            route('ai.backlog.reorder'),
            { order: next.map((item) => item.key) },
            { preserveScroll: true, preserveState: true, onError: () => setOrder(previous) },
        );
    };

    const reset = () => {
        router.delete(route('ai.backlog.manual'), { preserveScroll: true, onFinish: () => setConfirmReset(false) });
    };

    const areaOptions = [
        { value: ALL, label: 'todas' },
        ...areas
            .filter((area) => filters.company_id === null || area.company_id === filters.company_id)
            .map((area) => ({ value: String(area.id), label: allCompanies ? `${area.name} · ${companyOf(area.company_id)?.short_name ?? ''}` : area.name })),
    ];

    return (
        <GestorLayout shared={props} title="Backlog · Gestor IA" tab="backlog" mode="it">
            <div className="flex flex-col gap-4">
                <div className="flex flex-wrap items-end justify-between gap-3">
                    <div className="flex min-w-0 flex-col gap-1">
                        <h1 className="font-display text-[26px] leading-tight font-bold tracking-[-0.02em] md:text-[30px]">Backlog priorizado</h1>
                        <p className="text-sm text-fg-soft">
                            {items.length} {items.length === 1 ? 'solicitud evaluada' : 'solicitudes evaluadas'}, en el orden en que se van a trabajar
                        </p>
                    </div>
                    <span className="flex items-center gap-2 text-[13px] text-fg-soft">
                        <WipChip current={wip.in_development} limit={wip.limit} />
                        {wip.in_development >= wip.limit ? 'en desarrollo: lo siguiente espera cupo' : 'en desarrollo'}
                    </span>
                </div>

                {manual_order ? (
                    <section
                        aria-label="Orden manual"
                        className="flex flex-col gap-3 rounded-card border border-status-local/20 bg-status-local-bg p-3.5 text-status-local sm:flex-row sm:items-center"
                    >
                        <Icon name="move" size={18} className="hidden sm:block" />
                        <p className="min-w-0 flex-1 text-sm leading-snug">
                            <b>
                                Orden manual: {manual_order.by.name.split(' ')[0]} movió {manual_order.moved_key}
                                {manual_order.above_key ? ` arriba de ${manual_order.above_key}` : ''}.
                            </b>{' '}
                            <span className="text-fg-soft">
                                {formatRelative(manual_order.at, new Date(props.now))}
                                {manual_order.note ? ` · “${manual_order.note}”` : ''}
                            </span>
                        </p>
                        <Button variant="secondary" onClick={() => setConfirmReset(true)} className="shrink-0">
                            Volver al orden sugerido
                        </Button>
                    </section>
                ) : null}

                <div className="flex flex-col gap-2 md:flex-row md:items-center">
                    <div className="flex flex-wrap gap-2">
                        <FilterSelect
                            label="Área"
                            value={filters.area_id !== null ? String(filters.area_id) : ALL}
                            options={areaOptions}
                            onChange={(value) => apply({ area_id: value === ALL ? null : Number(value) })}
                        />
                        {allCompanies ? (
                            <FilterSelect
                                label="Empresa"
                                value={filters.company_id !== null ? String(filters.company_id) : ALL}
                                options={[{ value: ALL, label: 'todas' }, ...companies.map((company) => ({ value: String(company.id), label: company.short_name }))]}
                                onChange={(value) => apply({ company_id: value === ALL ? null : Number(value), area_id: null })}
                            />
                        ) : null}
                    </div>
                    <p className="flex items-center gap-1.5 text-[13px] text-fg-muted md:ml-auto">
                        <Icon name="grip" size={14} />
                        <span className="hidden md:inline">Arrastra para cambiar el orden. Con teclado: usa Subir o Bajar en cada fila.</span>
                        <span className="md:hidden">Usa Subir o Bajar para cambiar el orden.</span>
                    </p>
                </div>

                {order.length === 0 ? (
                    <EmptyState
                        icon="list"
                        title="El backlog está vacío"
                        description="Las solicitudes llegan aquí cuando TI las evalúa y quedan aprobadas para hacerse."
                        actions={
                            <Button variant="secondary" href={route('ai.inbox.index')}>
                                Ir a la bandeja
                            </Button>
                        }
                    />
                ) : (
                    <section aria-label="Backlog en orden" className="overflow-hidden rounded-card border border-line bg-surface">
                        <div
                            aria-hidden="true"
                            className={`hidden h-10 bg-subtle pr-[100px] pl-[76px] text-xs font-bold text-fg-muted lg:grid ${ROW_GRID.replace('lg:grid ', '')}`}
                        >
                            <span>Clave</span>
                            <span>Solicitud</span>
                            <span>Área</span>
                            <span>Talla</span>
                            <span>Prioridad</span>
                            <span>Ahorra</span>
                            <span className="text-right">Votos</span>
                        </div>
                        <SortableList
                            label="Backlog priorizado"
                            items={order}
                            getKey={(item) => item.key}
                            getLabel={(item) => item.key}
                            onReorder={(next) => reorder(next)}
                            renderItem={(item) => {
                                const company = companyOf(item.company_id);
                                const saves = teamHours(item.hours_per_week, item.people);
                                const href = route('ai.requests.show', { request: item.key });
                                return (
                                    <div className={`flex flex-col gap-1 text-sm ${ROW_GRID}`}>
                                        <Link href={href} className="tabular font-bold no-underline hover:underline">
                                            {item.key}
                                        </Link>
                                        <Link href={href} className="leading-snug font-semibold text-fg no-underline hover:underline">
                                            {item.title}
                                        </Link>
                                        <span className="flex items-center gap-1.5 text-fg-soft">
                                            {allCompanies && company ? <CompanyMark company={company} size={20} /> : null}
                                            {item.area.name}
                                        </span>
                                        <span className="flex flex-wrap items-center gap-x-3 gap-y-1 lg:contents">
                                            <span>{item.size ? <SizeTag size={item.size} /> : '—'}</span>
                                            <span>
                                                <span className="text-xs text-fg-muted lg:hidden">Prioridad </span>
                                                <Priority item={item} />
                                            </span>
                                            <span className="tabular">
                                                <span className="text-xs text-fg-muted lg:hidden">Ahorra </span>
                                                {saves !== null ? (
                                                    <>
                                                        <b>{number(saves)}</b> <span className="text-fg-muted">h/sem</span>
                                                    </>
                                                ) : (
                                                    '—'
                                                )}
                                            </span>
                                            <span className="tabular lg:text-right">
                                                <span className="text-xs text-fg-muted lg:hidden">Votos </span>
                                                {item.votes}
                                            </span>
                                        </span>
                                    </div>
                                );
                            }}
                        />
                    </section>
                )}

                <p className="text-[13px] text-fg-muted">
                    Prioridad: la sugerida aparece tachada cuando alguien la cambió a mano. Mover una fila deja el orden manual con tu nombre.
                </p>
            </div>

            <ConfirmDialog
                open={confirmReset}
                onClose={() => setConfirmReset(false)}
                onConfirm={reset}
                tone="primary"
                title="¿Volver al orden sugerido?"
                description="El backlog se ordena otra vez por prioridad final. El cambio manual queda en el historial."
                confirmLabel="Volver al orden sugerido"
            />
        </GestorLayout>
    );
}
