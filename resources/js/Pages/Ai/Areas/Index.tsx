import { Link, router } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { BudgetBar } from '@/Components/ui/BudgetBar';
import { Chip } from '@/Components/ui/Chip';
import { EmptyState } from '@/Components/ui/EmptyState';
import { Icon } from '@/Components/ui/Icon';
import { PhaseRail } from '@/Components/ui/PhaseRail';
import { SegmentedControl } from '@/Components/ui/SegmentedControl';
import { Semaphore } from '@/Components/ui/Semaphore';
import { SizeTag } from '@/Components/ui/SizeTag';
import { StatusChip } from '@/Components/ui/StatusChip';
import { cx } from '@/Components/ui/utils';
import { GestorLayout } from '@/Layouts/GestorLayout';
import { can } from '@/lib/can';
import { formatCOP } from '@/lib/format';
import { route } from '@/lib/route';
import type { AreaSummary, ProductState } from '@/types/ai';
import type { AreaDetail, AreasIndexProps } from '@/types/pages/ai-triage';
import type { PageProps } from '@/types/shared';

const PRODUCT_STATES: Record<ProductState, { label: string; tone: 'green' | 'amber' | 'blue' | 'slate' }> = {
    production: { label: 'En producción', tone: 'green' },
    pilot: { label: 'Piloto', tone: 'amber' },
    development: { label: 'En desarrollo', tone: 'blue' },
    retired: { label: 'Retirado', tone: 'slate' },
};

const number = (value: number) => value.toLocaleString('es-CO', { maximumFractionDigits: 1 });

function Counter({ value, label, muted }: { value: number | null; label: string; muted: boolean }) {
    return (
        <span className="flex min-w-0 flex-col">
            <b className={cx('tabular font-display text-xl leading-tight', muted ? 'text-fg-muted' : 'text-fg')}>{value === null ? '—' : number(value)}</b>
            <span className="text-xs leading-tight text-fg-muted">{label}</span>
        </span>
    );
}

function AreaCard({ summary, href, current, companyName }: { summary: AreaSummary; href: string; current: boolean; companyName: string }) {
    const empty = summary.products_in_use === 0 && summary.projects_in_progress === 0 && summary.backlog === 0;
    return (
        <Link
            href={href}
            only={['selected_area']}
            preserveScroll
            preserveState
            aria-current={current ? 'true' : undefined}
            className={cx(
                'flex flex-col gap-3 rounded-card border bg-surface p-4 no-underline transition-colors duration-150',
                current ? 'border-fg shadow-[inset_3px_0_0_var(--color-fg)]' : 'border-line hover:bg-subtle',
            )}
        >
            <span className="flex items-center justify-between gap-3">
                <b className="text-base text-fg">{summary.area.name}</b>
                <Semaphore level={summary.semaphore} />
            </span>
            <span className="grid grid-cols-4 gap-2">
                <Counter value={summary.products_in_use} label="productos en uso" muted={empty} />
                <Counter value={summary.projects_in_progress} label="proyectos en curso" muted={empty} />
                <Counter value={summary.backlog} label="en backlog" muted={empty} />
                <Counter value={summary.hours_saved_per_month} label="h ahorradas al mes" muted={empty} />
            </span>
            <span className="text-[13px] leading-snug text-fg-soft">{summary.reason ?? (empty ? `Nada abierto en ${companyName}` : 'Sin pendientes')}</span>
        </Link>
    );
}

function Block({ title, count, children }: { title: string; count: number; children: ReactNode }) {
    return (
        <section aria-label={title} className="flex flex-col gap-2.5 rounded-control bg-ground p-3.5">
            <h3 className="flex items-center gap-2 text-sm font-bold">
                {title}
                <span className="tabular rounded-full bg-subtle px-1.5 text-xs">{count}</span>
            </h3>
            {children}
        </section>
    );
}

function Detail({ detail, closeHref, canViewCosts }: { detail: AreaDetail; closeHref: string; canViewCosts: boolean }) {
    const { summary } = detail;
    return (
        <section aria-label={`${summary.area.name}, en detalle`} className="flex flex-col gap-4 rounded-card border border-line bg-surface p-4 md:p-5">
            <div className="flex items-start justify-between gap-3">
                <div className="flex flex-col gap-1">
                    <h2 className="flex flex-wrap items-center gap-3 font-display text-[22px] font-bold tracking-[-0.02em]">
                        {summary.area.name}
                        <Semaphore level={summary.semaphore} />
                    </h2>
                    <p className="text-[13px] text-fg-muted">Responsable del área: {summary.owner}</p>
                </div>
                <Link
                    href={closeHref}
                    only={['selected_area']}
                    preserveScroll
                    preserveState
                    className="flex h-11 shrink-0 items-center gap-1.5 rounded-control px-2.5 text-sm font-semibold text-fg-soft no-underline hover:bg-subtle"
                >
                    <Icon name="x" size={16} />
                    Cerrar detalle
                </Link>
            </div>

            <Block title="Productos en producción" count={detail.products.length}>
                {detail.products.length === 0 ? <p className="text-[13px] text-fg-muted">Esta área todavía no usa ninguna app de TI.</p> : null}
                {detail.products.map((product) => (
                    <Link
                        key={product.slug}
                        href={route('ai.products.show', { product: product.slug })}
                        className="flex flex-col gap-1 rounded-control border border-line bg-surface p-3 no-underline hover:bg-subtle"
                    >
                        <span className="flex flex-wrap items-center gap-2">
                            <b className="text-fg">{product.name}</b>
                            <Chip tone={PRODUCT_STATES[product.state].tone} icon="signal" size="sm">
                                {PRODUCT_STATES[product.state].label}
                            </Chip>
                        </span>
                        <span className="text-[13px] text-fg-soft">
                            {product.version} · {product.stack} · {product.host} · dueño {product.owner}
                        </span>
                        {!product.monitoring_active ? <span className="text-xs text-fg-muted">Monitoreo aún no activo</span> : null}
                    </Link>
                ))}
            </Block>

            <Block title="Proyectos en curso" count={detail.projects.length}>
                {detail.projects.length === 0 ? <p className="text-[13px] text-fg-muted">Ningún proyecto en construcción para esta área.</p> : null}
                {detail.projects.map((project) => (
                    <Link
                        key={project.key}
                        href={route('ai.projects.show', { project: project.key })}
                        className="flex flex-col gap-2.5 rounded-control border border-line bg-surface p-3 no-underline hover:bg-subtle"
                    >
                        <span className="flex flex-wrap items-center gap-2">
                            <b className="tabular text-fg">
                                {project.key} · {project.request_key}
                            </b>
                            <StatusChip status={project.status} size="sm" />
                        </span>
                        <b className="text-[15px] leading-snug text-fg">{project.name}</b>
                        <PhaseRail status={project.status} variant="bar" />
                        <BudgetBar
                            label="Horas"
                            value={project.hours.actual}
                            max={project.hours.estimate}
                            valueText={`${number(project.hours.actual)} de ${number(project.hours.estimate)} h`}
                        />
                        {canViewCosts && project.cost ? (
                            <BudgetBar
                                label="Costo"
                                value={project.cost.actual}
                                max={project.cost.estimate}
                                valueText={`${formatCOP(project.cost.actual)} de ${formatCOP(project.cost.estimate)}`}
                            />
                        ) : null}
                        {project.note ? (
                            project.note_level ? (
                                <Semaphore level={project.note_level}>{project.note}</Semaphore>
                            ) : (
                                <span className="text-[13px] text-fg-soft">{project.note}</span>
                            )
                        ) : null}
                    </Link>
                ))}
            </Block>

            <Block title="Solicitudes en backlog" count={detail.backlog.length}>
                {detail.backlog.length === 0 ? <p className="text-[13px] text-fg-muted">Nada en espera.</p> : null}
                {detail.backlog.length > 0 ? (
                    <ol className="flex flex-col gap-1.5">
                        {detail.backlog.map((item) => (
                            <li key={item.key}>
                                <Link
                                    href={route('ai.requests.show', { request: item.key })}
                                    className="flex min-h-11 items-center gap-2.5 rounded-control border border-line bg-surface px-3 py-2 text-sm no-underline hover:bg-subtle"
                                >
                                    <span className="tabular w-5 shrink-0 text-center font-bold text-fg-muted">
                                        <span className="sr-only">Puesto </span>
                                        {item.rank}
                                    </span>
                                    <span className="flex min-w-0 flex-1 flex-col">
                                        <b className="tabular text-fg">{item.key}</b>
                                        <span className="leading-snug text-fg-soft">{item.title}</span>
                                    </span>
                                    {item.size ? <SizeTag size={item.size} /> : null}
                                    {item.priority_final !== null ? <b className="tabular w-7 text-right text-fg">{item.priority_final}</b> : null}
                                </Link>
                            </li>
                        ))}
                    </ol>
                ) : null}
            </Block>

            {detail.inbox_keys.length > 0 ? (
                <p className="text-[13px] text-fg-soft">
                    Y en la bandeja:{' '}
                    {detail.inbox_keys.map((key, index) => (
                        <span key={key}>
                            {index > 0 ? (index === detail.inbox_keys.length - 1 ? ' y ' : ', ') : null}
                            <Link href={route('ai.requests.show', { request: key })} className="font-semibold text-fg underline underline-offset-2">
                                {key}
                            </Link>
                        </span>
                    ))}
                </p>
            ) : null}
        </section>
    );
}

/** "Por área" (B-ByArea): what each area has in use, in construction and waiting, one company at a time. */
export default function AreasIndex(props: PageProps<AreasIndexProps>) {
    const { company_id, area_companies, areas, selected_area, auth } = props;
    const company = area_companies.find((item) => item.id === company_id);
    const companyName = company?.short_name ?? '';
    const canViewCosts = can(auth.user, 'ai.costs.view');
    const hrefFor = (areaId?: number) => route('ai.areas.index', areaId === undefined ? { company_id } : { company_id, area_id: areaId });

    return (
        <GestorLayout shared={props} title="Por área · Gestor IA" tab="areas" mode="it">
            <div className="flex flex-col gap-4">
                <div className="flex flex-wrap items-end justify-between gap-3">
                    <div className="flex min-w-0 flex-col gap-1">
                        <h1 className="font-display text-[26px] leading-tight font-bold tracking-[-0.02em] md:text-[30px]">Por área · {companyName}</h1>
                        <p className="text-sm text-fg-soft">Qué tiene cada área en uso, en construcción y en espera.</p>
                    </div>
                    {area_companies.length > 1 ? (
                        <SegmentedControl
                            label="Empresa"
                            value={String(company_id)}
                            onChange={(value) => router.get(route('ai.areas.index'), { company_id: Number(value) }, { preserveScroll: true })}
                            options={area_companies.map((item) => ({ value: String(item.id), label: item.short_name }))}
                        />
                    ) : null}
                </div>

                <div className="flex flex-wrap items-center gap-x-4 gap-y-1 text-[13px] text-fg-muted">
                    <Semaphore level="ok" />
                    <Semaphore level="attention" />
                    <Semaphore level="late" />
                    <span>Horas ahorradas: salen de las solicitudes ya en producción, horas por semana × personas, llevadas a mes.</span>
                </div>

                {areas.length === 0 ? (
                    <EmptyState icon="building" title={`${companyName} no tiene áreas todavía`} description="Las áreas se crean en Administración." />
                ) : (
                    <div className={cx('flex flex-col gap-4', selected_area && 'lg:grid lg:grid-cols-[minmax(0,1fr)_minmax(0,1.15fr)] lg:items-start lg:gap-6')}>
                        <ul aria-label="Áreas" className={cx('grid gap-3', selected_area ? 'sm:grid-cols-2 lg:grid-cols-1' : 'sm:grid-cols-2 xl:grid-cols-3')}>
                            {areas.map((summary) => (
                                <li key={summary.area.id}>
                                    <AreaCard
                                        summary={summary}
                                        href={hrefFor(summary.area.id)}
                                        current={selected_area?.summary.area.id === summary.area.id}
                                        companyName={companyName}
                                    />
                                </li>
                            ))}
                        </ul>
                        {selected_area ? <Detail detail={selected_area} closeHref={hrefFor()} canViewCosts={canViewCosts} /> : null}
                    </div>
                )}
            </div>
        </GestorLayout>
    );
}
