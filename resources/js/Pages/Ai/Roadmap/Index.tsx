import { router } from '@inertiajs/react';
import { useState } from 'react';
import { RoadmapCard } from '@/Components/ai/roadmap/RoadmapCard';
import { Button } from '@/Components/ui/Button';
import { Icon } from '@/Components/ui/Icon';
import { cx } from '@/Components/ui/utils';
import { GestorLayout } from '@/Layouts/GestorLayout';
import { can } from '@/lib/can';
import { formatRelative } from '@/lib/format';
import { route } from '@/lib/route';
import type { RoadmapColumn, RoadmapFilters, RoadmapIndexProps } from '@/types/pages/ai-requester';
import type { PageProps } from '@/types/shared';

const COLUMNS: { key: RoadmapColumn; title: string; subtitle: string }[] = [
    { key: 'now', title: 'Ahora', subtitle: 'En construcción o en pruebas' },
    { key: 'next', title: 'Siguiente', subtitle: 'Aprobadas y planificadas' },
    { key: 'later', title: 'Más adelante', subtitle: 'En fila, por prioridad de cada área' },
];

function toQuery(filters: RoadmapFilters): Record<string, number> {
    const query: Record<string, number> = {};
    if (filters.area_id !== null) query.area_id = filters.area_id;
    if (filters.company_id !== null) query.company_id = filters.company_id;
    if (filters.mine) query.mine = 1;
    return query;
}

const selectClasses =
    'h-11 max-w-full appearance-none truncate rounded-chip border border-line-strong bg-surface pr-9 pl-3 text-sm font-semibold text-fg hover:border-fg-muted';

function FilterSelect({ label, value, options, onChange }: { label: string; value: string; options: { value: string; label: string }[]; onChange: (value: string) => void }) {
    return (
        <label className="relative inline-flex min-w-0">
            <span className="sr-only">{label}</span>
            <select value={value} onChange={(event) => onChange(event.target.value)} className={selectClasses}>
                {options.map((option) => (
                    <option key={option.value} value={option.value}>
                        {option.label}
                    </option>
                ))}
            </select>
            <span className="pointer-events-none absolute inset-y-0 right-3 flex items-center">
                <Icon name="chevron-down" size={14} strokeWidth={2.2} />
            </span>
        </label>
    );
}

/** Hoja de ruta: what IT builds now, what comes next and what waits, for the whole company. */
export default function RoadmapIndex(props: PageProps<RoadmapIndexProps>) {
    const { columns, filters, areas, roadmap_companies, later_total, wip_limit, mine_count, updated_at, auth } = props;
    const [column, setColumn] = useState<RoadmapColumn>('now');
    const canAsk = can(auth.user, 'ai.requests.create');
    const companyOf = (id: number) => roadmap_companies.find((company) => company.id === id);

    const apply = (next: Partial<RoadmapFilters>) => {
        router.get(route('ai.roadmap.index'), toQuery({ ...filters, ...next }), { preserveState: true, preserveScroll: true, replace: true });
    };

    const count = (key: RoadmapColumn) => (key === 'later' ? later_total : columns[key].length);
    const countLabel = (key: RoadmapColumn) => (key === 'now' ? `${columns.now.length} de ${wip_limit}` : String(count(key)));

    const areaSelect = (
        <FilterSelect
            label="Área"
            value={filters.area_id === null ? '' : String(filters.area_id)}
            onChange={(value) => apply({ area_id: value ? Number(value) : null })}
            options={[{ value: '', label: 'Área: todas' }, ...areas.map((area) => ({ value: String(area.id), label: area.name }))]}
        />
    );

    const mineToggle = (short: boolean) => (
        <button
            type="button"
            aria-pressed={filters.mine}
            onClick={() => apply({ mine: !filters.mine })}
            className={cx(
                'tabular inline-flex h-11 shrink-0 items-center gap-1.5 rounded-chip border px-3.5 text-sm transition-colors duration-150',
                filters.mine ? 'border-fg bg-fg font-bold text-on-fg' : 'border-line-strong bg-surface font-semibold hover:border-fg-muted',
            )}
        >
            {filters.mine ? <Icon name="check" size={14} strokeWidth={2.6} /> : <Icon name="user" size={14} />}
            {short ? 'Mías' : `Solo las mías · ${mine_count}`}
        </button>
    );

    const renderColumn = (key: RoadmapColumn) => {
        const items = columns[key];
        const hidden = key === 'later' ? later_total - items.length : 0;
        return items.length === 0 ? (
            <p className="rounded-[14px] border border-dashed border-line-strong p-4 text-sm text-fg-muted">
                {filters.mine ? 'Ninguna tuya en este momento.' : 'Nada por ahora.'}
            </p>
        ) : (
            <>
                <ul className="flex flex-col gap-2.5">
                    {items.map((item) => (
                        <li key={item.key}>
                            <RoadmapCard item={item} company={companyOf(item.company_id)} />
                        </li>
                    ))}
                </ul>
                {hidden > 0 ? <p className="px-1 text-[13px] text-fg-muted">Y {hidden} más en fila. Filtra por área para verlas.</p> : null}
            </>
        );
    };

    const wipNote = (
        <p className="flex items-start gap-2 text-[13px] leading-snug text-fg-soft">
            <span className="mt-px shrink-0 text-fg-muted">
                <Icon name="info" size={16} />
            </span>
            Máximo {wip_limit} proyectos en construcción a la vez, para terminar antes de empezar otro.
        </p>
    );

    return (
        <GestorLayout shared={props} title="Hoja de ruta · Gestor IA" requesterNav="roadmap">
            <div className="flex flex-col gap-4">
                <div className="flex flex-wrap items-end justify-between gap-3">
                    <div className="flex min-w-0 flex-col gap-1">
                        <h1 className="font-display text-[26px] leading-tight font-bold tracking-[-0.02em] md:text-[32px]">Hoja de ruta</h1>
                        <p className="text-sm text-fg-soft">
                            Lo que TI está haciendo y lo que viene.<span className="hidden md:inline"> La ve toda la empresa.</span>
                            <span className="hidden text-fg-muted md:inline"> Actualizada {formatRelative(updated_at)}.</span>
                        </p>
                    </div>
                    {canAsk ? (
                        <span className="hidden md:inline-flex">
                            <Button icon="plus" href={route('ai.requests.create')}>
                                Pedir un desarrollo o una mejora
                            </Button>
                        </span>
                    ) : null}
                </div>

                {/* Phone and tablet: one column at a time. */}
                <div className="flex flex-col gap-3 lg:hidden">
                    <div role="group" aria-label="Momento" className="grid grid-cols-3 gap-0.5 rounded-control bg-subtle p-[3px]">
                        {COLUMNS.map((option) => {
                            const pressed = option.key === column;
                            return (
                                <button
                                    key={option.key}
                                    type="button"
                                    aria-pressed={pressed}
                                    onClick={() => setColumn(option.key)}
                                    className={cx(
                                        'tabular h-11 truncate rounded-chip px-1 text-[13px] transition-colors duration-150',
                                        pressed ? 'bg-surface font-bold text-fg shadow-[0_1px_2px_rgb(23_25_28/0.12)]' : 'font-semibold text-fg-soft',
                                    )}
                                >
                                    {option.key === 'later' ? option.title : `${option.title} · ${count(option.key)}`}
                                </button>
                            );
                        })}
                    </div>
                    <div className="flex gap-2">
                        <div className="min-w-0 flex-1 [&_label]:w-full [&_select]:w-full">{areaSelect}</div>
                        {mineToggle(true)}
                    </div>
                    <section aria-labelledby={`col-m-${column}`} className="flex flex-col gap-2.5">
                        <h2 id={`col-m-${column}`} className="sr-only">
                            {COLUMNS.find((option) => option.key === column)?.title}
                        </h2>
                        <p className="text-[13px] text-fg-muted">
                            {COLUMNS.find((option) => option.key === column)?.subtitle} · <span className="tabular">{countLabel(column)}</span>
                        </p>
                        {renderColumn(column)}
                    </section>
                    {column === 'now' ? wipNote : null}
                </div>

                {/* Desktop: the three columns. */}
                <div className="hidden flex-col gap-4 lg:flex">
                    <div className="flex flex-wrap items-center gap-2">
                        {areaSelect}
                        {roadmap_companies.length > 1 ? (
                            <FilterSelect
                                label="Empresa"
                                value={filters.company_id === null ? '' : String(filters.company_id)}
                                onChange={(value) => apply({ company_id: value ? Number(value) : null })}
                                options={[
                                    { value: '', label: 'Empresa: todas' },
                                    ...roadmap_companies.map((company) => ({ value: String(company.id), label: company.short_name })),
                                ]}
                            />
                        ) : null}
                        {mineToggle(false)}
                        <span className="ml-auto max-w-[420px]">{wipNote}</span>
                    </div>
                    <div className="grid grid-cols-3 items-start gap-4">
                        {COLUMNS.map((option) => (
                            <section
                                key={option.key}
                                aria-labelledby={`col-${option.key}`}
                                className={cx('flex flex-col gap-2.5 rounded-card p-3', option.key === 'now' ? 'bg-subtle' : 'bg-subtle/50')}
                            >
                                <div className="flex items-baseline gap-2 px-1 pt-1">
                                    <h2 id={`col-${option.key}`} className="text-base font-bold">
                                        {option.title}
                                    </h2>
                                    <span className="tabular ml-auto text-[13px] font-bold text-fg-soft">{countLabel(option.key)}</span>
                                </div>
                                <p className="-mt-1.5 px-1 text-[13px] text-fg-muted">{option.subtitle}</p>
                                {renderColumn(option.key)}
                            </section>
                        ))}
                    </div>
                </div>
            </div>
        </GestorLayout>
    );
}
