import { Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { CostsLockNote } from '@/Components/ai/products/ProductParts';
import { FilterSelect } from '@/Components/ai/triage/InboxParts';
import { BarListWithTable, MiniBar, ValueBlock } from '@/Components/ai/value/ValueParts';
import {
    hoursText,
    leadTimeDelta,
    monthLong,
    monthShort,
    monthsText,
    paybackScaleMax,
    periodRangeText,
    VALUE_PERIODS,
    type ValuePeriod,
} from '@/Components/ai/value/value';
import { BarList } from '@/Components/ui/BarList';
import { Button, buttonClasses } from '@/Components/ui/Button';
import { CompanyMark } from '@/Components/ui/CompanyMark';
import { EmptyState } from '@/Components/ui/EmptyState';
import { Icon } from '@/Components/ui/Icon';
import { LineChart } from '@/Components/ui/LineChart';
import { SegmentedControl } from '@/Components/ui/SegmentedControl';
import { StatTile } from '@/Components/ui/StatTile';
import { GestorLayout } from '@/Layouts/GestorLayout';
import { can } from '@/lib/can';
import { formatCOP, formatRelative } from '@/lib/format';
import { route } from '@/lib/route';
import type { ValueMetrics } from '@/types/ai';
import type { ValueFilters, ValueIndexProps, ValueProduct } from '@/types/pages/ai-value-vault';
import type { PageProps } from '@/types/shared';

const ALL = 'all';

const decimal = (value: number) => value.toLocaleString('es-CO', { minimumFractionDigits: 1, maximumFractionDigits: 1 });

function toQuery(filters: ValueFilters): Record<string, string | number> {
    const query: Record<string, string | number> = {};
    if (filters.period !== '6m') query.period = filters.period;
    if (filters.company_id !== null) query.company_id = filters.company_id;
    if (filters.area_id !== null) query.area_id = filters.area_id;
    return query;
}

function Payback({ metrics }: { metrics: ValueMetrics }) {
    const [view, setView] = useState<'chart' | 'table'>('table');
    const roi = metrics.roi ?? [];
    const max = paybackScaleMax(roi.map((row) => row.payback_months));

    return (
        <ValueBlock
            title="Retorno por producto"
            description={metrics.hour_value !== null ? `Ahorro = horas al mes × ${formatCOP(metrics.hour_value)} por hora, menos el costo mensual de la app` : undefined}
            actions={
                <SegmentedControl<'chart' | 'table'>
                    label="Vista del retorno"
                    size="sm"
                    value={view}
                    onChange={setView}
                    options={[
                        { value: 'chart', label: 'Gráfico' },
                        { value: 'table', label: 'Tabla' },
                    ]}
                />
            }
        >
            {view === 'table' ? (
                <div className="-mx-4 overflow-x-auto md:-mx-5">
                    <table className="w-full min-w-[560px] border-collapse text-sm">
                        <caption className="sr-only">Retorno por producto: costo total, ahorro al mes y meses para pagarse</caption>
                        <thead>
                            <tr className="border-y border-line bg-subtle text-left text-xs text-fg-muted">
                                <th scope="col" className="py-2.5 pl-4 font-bold md:pl-5">
                                    Producto
                                </th>
                                <th scope="col" className="py-2.5 text-right font-bold">
                                    Costo total
                                </th>
                                <th scope="col" className="py-2.5 text-right font-bold">
                                    Ahorro al mes
                                </th>
                                <th scope="col" className="w-[220px] py-2.5 pr-4 pl-6 font-bold md:pr-5">
                                    Se paga en
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {roi.map((row) => (
                                <tr key={row.product} className="border-b border-line last:border-0">
                                    <th scope="row" className="py-3 pl-4 text-left font-normal md:pl-5">
                                        <span className="flex flex-col">
                                            <b>{row.product}</b>
                                            <span className="text-xs text-fg-muted">{hoursText(row.hours_per_month)} al mes</span>
                                        </span>
                                    </th>
                                    <td className="tabular py-3 text-right">{formatCOP(row.total_cost)}</td>
                                    <td className="tabular py-3 text-right">{formatCOP(row.saving_per_month)}</td>
                                    <td className="py-3 pr-4 pl-6 md:pr-5">
                                        <span className="flex items-center gap-3">
                                            <MiniBar value={row.payback_months} max={max} />
                                            <span className="tabular w-[76px] shrink-0 text-right text-[13px] font-bold">{monthsText(row.payback_months)}</span>
                                        </span>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            ) : (
                <BarList
                    label="Meses para pagarse, por producto"
                    items={[...roi].sort((a, b) => a.payback_months - b.payback_months).map((row) => ({ key: row.product, label: row.product, value: row.payback_months }))}
                    formatValue={monthsText}
                    max={max}
                    sort={false}
                    labelWidth={190}
                />
            )}
            <div className="flex flex-wrap justify-between gap-x-4 gap-y-1 text-xs text-fg-muted">
                <span>Escala de la barra: 0 a {max} meses</span>
            </div>
        </ValueBlock>
    );
}

function TopProducts({ products, companies }: { products: ValueProduct[]; companies: PageProps['companies'] }) {
    const max = Math.max(1, ...products.map((product) => product.hours_per_month));
    return (
        <ValueBlock title="Productos que más ahorran" className="md:hidden">
            <ul className="-mx-4 flex flex-col">
                {products.map((product) => {
                    const company = companies.find((item) => item.id === product.company_id);
                    return (
                        <li key={product.slug}>
                            <Link
                                href={route('ai.products.show', { product: product.slug })}
                                className="grid grid-cols-[24px_minmax(0,1fr)_auto] items-center gap-3 border-t border-line px-4 py-3 text-fg no-underline"
                            >
                                {company ? <CompanyMark company={company} size={24} /> : <span />}
                                <span className="flex min-w-0 flex-col gap-1.5">
                                    <b className="truncate text-sm">{product.name}</b>
                                    <MiniBar value={product.hours_per_month} max={max} />
                                </span>
                                <span className="flex flex-col items-end">
                                    <b className="tabular text-sm">{hoursText(product.hours_per_month)}</b>
                                    {product.payback_months !== null ? (
                                        <span className="text-xs text-fg-muted">se paga en {monthsText(product.payback_months)}</span>
                                    ) : null}
                                </span>
                            </Link>
                        </li>
                    );
                })}
            </ul>
        </ValueBlock>
    );
}

/** B-Value: how much time IT gives back to each area and when the apps pay for themselves. */
export default function ValueIndex(props: PageProps<ValueIndexProps>) {
    const { metrics, filters, areas, products, area_notes, roi_note, awaiting_rating, cost_note, data_note, now, companies, current_company_id, auth } = props;
    const allCompanies = current_company_id === null;
    const canViewCosts = can(auth.user, 'ai.costs.view');

    const apply = (change: Partial<ValueFilters>) => {
        router.get(route('ai.value.index'), toQuery({ ...filters, ...change }), { preserveState: true, preserveScroll: true });
    };

    const series = metrics?.hours_series ?? [];
    const last = series[series.length - 1];
    const first = series[0];
    const totalRequests = metrics ? metrics.requests_by_status.reduce((sum, row) => sum + row.count, 0) : 0;

    return (
        <GestorLayout shared={props} title="Valor de las apps · Gestor IA" tab="value" mode="it">
            <div className="flex flex-col gap-4">
                <div className="flex flex-wrap items-end justify-between gap-3">
                    <div className="flex max-w-[680px] flex-col gap-1">
                        <h1 className="font-display text-[26px] leading-tight font-bold tracking-[-0.02em] md:text-[30px]">Valor de las apps</h1>
                        <p className="text-sm text-fg-soft">Cuánto tiempo le devuelve TI a cada área y cuándo se paga lo invertido.</p>
                    </div>
                    {metrics ? (
                        <a href={route('ai.value.export', toQuery(filters))} className={buttonClasses({ variant: 'secondary' })}>
                            <Icon name="download" size={16} />
                            Descargar para la junta
                        </a>
                    ) : null}
                </div>

                <div role="group" aria-label="Filtros del tablero" className="flex flex-wrap items-center gap-2">
                    <FilterSelect
                        label="Periodo"
                        value={filters.period}
                        options={VALUE_PERIODS}
                        onChange={(value) => apply({ period: value as ValuePeriod })}
                    />
                    {allCompanies ? (
                        <FilterSelect
                            label="Empresa"
                            value={filters.company_id !== null ? String(filters.company_id) : ALL}
                            options={[{ value: ALL, label: 'todas' }, ...companies.map((company) => ({ value: String(company.id), label: company.short_name }))]}
                            onChange={(value) => apply({ company_id: value === ALL ? null : Number(value) })}
                        />
                    ) : null}
                    <FilterSelect
                        label="Área"
                        value={filters.area_id !== null ? String(filters.area_id) : ALL}
                        options={[{ value: ALL, label: 'todas' }, ...areas.map((area) => ({ value: String(area.id), label: area.name }))]}
                        onChange={(value) => apply({ area_id: value === ALL ? null : Number(value) })}
                    />
                    {metrics ? (
                        <span className="text-[13px] text-fg-muted">
                            {periodRangeText(metrics.period.from, metrics.period.to)} · actualizado {formatRelative(metrics.updated_at, new Date(now))}
                        </span>
                    ) : null}
                </div>

                {!metrics ? (
                    <EmptyState
                        icon="trend"
                        title="Aún no hay calificaciones"
                        description={
                            awaiting_rating ? (
                                <>
                                    Los datos aparecen cuando las primeras entregas se califiquen. Hay 1 entrega esperando: {awaiting_rating.request_key},{' '}
                                    {awaiting_rating.title.charAt(0).toLowerCase() + awaiting_rating.title.slice(1)}.
                                </>
                            ) : (
                                'Los datos aparecen cuando las primeras entregas se califiquen.'
                            )
                        }
                        actions={
                            awaiting_rating ? (
                                <Button
                                    variant="dark"
                                    href={route('ai.ratings.remind', { request: awaiting_rating.request_key })}
                                    method="post"
                                    preserveScroll
                                >
                                    Recordar a {awaiting_rating.requester.name}
                                </Button>
                            ) : undefined
                        }
                    />
                ) : (
                    <>
                        <section aria-label="Indicadores" className="grid grid-cols-2 gap-3 xl:grid-cols-4">
                            <StatTile
                                label="Horas ahorradas al mes"
                                value={hoursText(metrics.hours_saved_per_month.current)}
                                delta={
                                    metrics.hours_saved_per_month.delta !== 0
                                        ? {
                                              text: `${metrics.hours_saved_per_month.delta > 0 ? '+' : '−'}${hoursText(Math.abs(metrics.hours_saved_per_month.delta))} ${metrics.hours_saved_per_month.delta_label}`,
                                              direction: metrics.hours_saved_per_month.delta > 0 ? 'up' : 'down',
                                              good: metrics.hours_saved_per_month.delta > 0,
                                          }
                                        : undefined
                                }
                            />
                            <StatTile
                                label="Satisfacción promedio"
                                value={`${decimal(metrics.satisfaction.average)} de 5`}
                                note={`${metrics.satisfaction.ratings} calificaciones de ${metrics.satisfaction.apps} apps`}
                            />
                            <StatTile
                                label="De solicitud a producción"
                                value={`${metrics.lead_time_days.current} días`}
                                delta={leadTimeDelta(metrics.lead_time_days.delta, filters.period)}
                            />
                            {canViewCosts && metrics.monthly_cost !== null ? (
                                <StatTile
                                    label="Costo mensual de las apps"
                                    value={formatCOP(metrics.monthly_cost)}
                                    note={cost_note ?? undefined}
                                    restricted
                                />
                            ) : (
                                <div className="flex flex-col gap-2 rounded-card border border-dashed border-line-strong p-4 md:p-5">
                                    <span className="text-[13px] font-semibold text-fg-soft">Costo mensual de las apps</span>
                                    <CostsLockNote>Solo lo ven quienes tienen el permiso de costos.</CostsLockNote>
                                </div>
                            )}
                        </section>

                        <div className="grid gap-4 xl:grid-cols-[minmax(0,7fr)_minmax(0,5fr)]">
                            <ValueBlock title="Horas ahorradas por mes" description="Suma de todas las apps en producción">
                                <LineChart
                                    title={`Horas ahorradas por mes, ${periodRangeText(metrics.period.from, metrics.period.to).toLowerCase()}`}
                                    summary={first && last ? `Pasaron de ${hoursText(first.value)} en ${monthLong(first.month).toLowerCase()} a ${hoursText(last.value)} en ${monthLong(last.month).toLowerCase()}.` : undefined}
                                    series={[{ key: 'hours', label: 'Horas ahorradas', area: true, points: series.map((point, index) => ({ x: index, y: point.value })) }]}
                                    xTicks={series.map((point, index) => ({ x: index, label: monthShort(point.month) }))}
                                    formatX={(x) => monthLong(series[x]?.month ?? '')}
                                    formatY={hoursText}
                                    height={220}
                                />
                                <span className="text-xs text-fg-muted">Pasa el cursor o toca un mes para ver su valor. Con teclado, las flechas recorren los meses.</span>
                            </ValueBlock>

                            <TopProducts products={products} companies={companies} />

                            <ValueBlock title="Horas ahorradas por área" description={`${monthLong(last?.month ?? '').split(' ')[0]}, de mayor a menor`}>
                                <BarListWithTable
                                    label="Horas ahorradas por área"
                                    items={metrics.hours_by_area.map((row) => ({ key: row.area, label: row.area, value: row.hours }))}
                                    formatValue={hoursText}
                                    nameHeader="Área"
                                    valueHeader="Horas al mes"
                                    labelWidth={110}
                                />
                                {area_notes.length > 0 ? (
                                    <ul className="flex flex-col gap-1 border-t border-line pt-3 text-[13px] leading-snug text-fg-soft">
                                        {area_notes.map((note) => (
                                            <li key={note}>{note}</li>
                                        ))}
                                    </ul>
                                ) : null}
                            </ValueBlock>
                        </div>

                        <div className="grid gap-4 xl:grid-cols-[minmax(0,5fr)_minmax(0,7fr)]">
                            <ValueBlock title="Solicitudes por estado" description={`${totalRequests} solicitudes creadas en el periodo`}>
                                <BarListWithTable
                                    label="Solicitudes por estado"
                                    items={metrics.requests_by_status.map((row) => ({ key: row.status, status: row.status, value: row.count }))}
                                    formatValue={(value) => String(value)}
                                    nameHeader="Estado"
                                    valueHeader="Solicitudes"
                                    labelWidth={200}
                                />
                                <span className="text-xs text-fg-muted">Los colores de estado solo se usan aquí porque cada barra es un estado.</span>
                            </ValueBlock>

                            {canViewCosts && metrics.roi ? (
                                <div className="flex min-w-0 flex-col gap-2">
                                    <Payback metrics={metrics} />
                                    {roi_note ? <span className="px-1 text-xs text-fg-muted">{roi_note}</span> : null}
                                </div>
                            ) : (
                                <ValueBlock title="Retorno por producto">
                                    <CostsLockNote>
                                        Cuánto cuesta cada app y en cuántos meses se paga solo lo ven quienes tienen el permiso de costos. Las horas ahorradas sí están
                                        arriba.
                                    </CostsLockNote>
                                </ValueBlock>
                            )}
                        </div>
                    </>
                )}

                {data_note ? (
                    <p className="flex items-start gap-2 text-[13px] text-fg-muted">
                        <Icon name="info" size={14} className="mt-0.5 shrink-0" />
                        {data_note}
                    </p>
                ) : null}
            </div>
        </GestorLayout>
    );
}
