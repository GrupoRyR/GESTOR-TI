import { Link, router } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import { CostsLockNote, Placeholderable, ProductStateChip } from '@/Components/ai/products/ProductParts';
import { joinWords, openRequestsText, PRODUCT_STATE_ORDER, PRODUCT_STATES, productTotals, ratingText } from '@/Components/ai/products/products';
import { FilterSelect } from '@/Components/ai/triage/InboxParts';
import { Button } from '@/Components/ui/Button';
import { CompanyMark } from '@/Components/ui/CompanyMark';
import { DataTable, type DataTableColumn } from '@/Components/ui/DataTable';
import { EmptyState } from '@/Components/ui/EmptyState';
import { Icon } from '@/Components/ui/Icon';
import { SegmentedControl } from '@/Components/ui/SegmentedControl';
import { cx } from '@/Components/ui/utils';
import { GestorLayout } from '@/Layouts/GestorLayout';
import { can } from '@/lib/can';
import { formatCOP } from '@/lib/format';
import { route } from '@/lib/route';
import type { Product, ProductState } from '@/types/ai';
import type { ProductFilters, ProductsIndexProps } from '@/types/pages/ai-knowledge';
import type { Company, PageProps } from '@/types/shared';

const ALL = 'all';

const hours = (value: number | null) => (value === null ? '—' : `${value.toLocaleString('es-CO')} h`);

function toQuery(filters: ProductFilters): Record<string, string | number> {
    const query: Record<string, string | number> = {};
    if (filters.q) query.q = filters.q;
    if (filters.company_id !== null) query.company_id = filters.company_id;
    if (filters.area_id !== null) query.area_id = filters.area_id;
    if (filters.state !== null) query.state = filters.state;
    return query;
}

function RatingCell({ product }: { product: Product }) {
    if (!product.rating) {
        return (
            <span className="flex flex-col">
                <span className="font-semibold text-fg-muted">Sin calificar</span>
                <span className="text-xs text-fg-muted">{product.state === 'development' ? 'aún no se entrega' : 'sin votos'}</span>
            </span>
        );
    }
    return (
        <span className="flex flex-col">
            <b className="tabular">{ratingText(product.rating.average)}</b>
            <span className="text-xs text-fg-muted">
                {product.rating.count} {product.rating.count === 1 ? 'persona' : 'personas'}
            </span>
        </span>
    );
}

/** Phone card (below 768 px the table becomes this list). */
function ProductCard({ product, company, showCompany, canViewCosts }: { product: Product; company: Company | undefined; showCompany: boolean; canViewCosts: boolean }) {
    return (
        <article className="relative flex flex-col gap-3 rounded-card border border-line bg-surface p-4">
            <div className="flex items-start gap-3">
                {showCompany && company ? <CompanyMark company={company} size={28} /> : null}
                <div className="flex min-w-0 flex-1 flex-col gap-0.5">
                    <Link
                        href={route('ai.products.show', { product: product.slug })}
                        className="text-base leading-tight font-bold text-fg no-underline after:absolute after:inset-0 after:rounded-card hover:underline"
                    >
                        {product.name}
                    </Link>
                    <span className="text-[13px] text-fg-muted">
                        {product.slug} · {product.version}
                    </span>
                </div>
                <ProductStateChip state={product.state} />
            </div>
            <dl className="grid grid-cols-2 gap-x-4 gap-y-2 text-[13px]">
                <div className="flex flex-col">
                    <dt className="text-fg-muted">Áreas y dueño</dt>
                    <dd>
                        {joinWords(product.areas)} · <Placeholderable text={product.owner} />
                    </dd>
                </div>
                <div className="flex flex-col">
                    <dt className="text-fg-muted">Stack</dt>
                    <dd>
                        {product.stack} · {product.host}
                    </dd>
                </div>
                <div className="flex flex-col">
                    <dt className="text-fg-muted">Calificación</dt>
                    <dd>{product.rating ? `${ratingText(product.rating.average)} · ${product.rating.count} personas` : 'Sin calificar'}</dd>
                </div>
                <div className="flex flex-col">
                    <dt className="text-fg-muted">Horas al mes</dt>
                    <dd className="tabular font-semibold">{hours(product.hours_saved_per_month)}</dd>
                </div>
                {canViewCosts ? (
                    <div className="flex flex-col">
                        <dt className="flex items-center gap-1 text-fg-muted">
                            Costo al mes <Icon name="lock" size={12} label="Visible solo con permiso de costos" />
                        </dt>
                        <dd className="tabular font-semibold">{product.monthly_cost === null ? '—' : formatCOP(product.monthly_cost)}</dd>
                    </div>
                ) : null}
                <div className="flex flex-col">
                    <dt className="text-fg-muted">Solicitudes</dt>
                    <dd className={product.open_requests > 0 ? 'font-bold' : 'text-fg-muted'}>{openRequestsText(product.open_requests)}</dd>
                </div>
            </dl>
        </article>
    );
}

/** Productos: the apps IT already built, their version, state and what they give back to each area. */
export default function ProductsIndex(props: PageProps<ProductsIndexProps>) {
    const { products, filters, state_counts, areas, companies, current_company_id, auth } = props;
    const allCompanies = current_company_id === null;
    const canViewCosts = can(auth.user, 'ai.costs.view');
    const [q, setQ] = useState(filters.q ?? '');
    const filtered = filters.q !== null || filters.company_id !== null || filters.area_id !== null || filters.state !== null;
    const totals = productTotals(products);
    const companyOf = (id: number) => companies.find((company) => company.id === id);
    const totalCount = PRODUCT_STATE_ORDER.reduce((sum, state) => sum + state_counts[state], 0);

    const apply = (change: Partial<ProductFilters>) => {
        router.get(route('ai.products.index'), toQuery({ ...filters, ...change }), { preserveState: true, preserveScroll: true });
    };

    const onSearch = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        apply({ q: q.trim() === '' ? null : q.trim() });
    };

    const columns: DataTableColumn<Product>[] = [
        ...(allCompanies
            ? [
                  {
                      key: 'company',
                      header: 'Empresa',
                      hideHeader: true,
                      width: '28px',
                      render: (product: Product) => {
                          const company = companyOf(product.company_id);
                          return company ? <CompanyMark company={company} size={24} /> : null;
                      },
                  },
              ]
            : []),
        {
            key: 'name',
            header: 'Producto y versión',
            rowLink: true,
            render: (product) => (
                <span className="flex min-w-0 flex-col">
                    <b className="truncate">{product.name}</b>
                    <span className="truncate text-xs text-fg-muted">
                        {product.slug} · {product.version}
                    </span>
                </span>
            ),
        },
        {
            key: 'areas',
            header: 'Áreas y dueño',
            width: '170px',
            render: (product) => (
                <span className="flex min-w-0 flex-col">
                    <span className="truncate">{joinWords(product.areas)}</span>
                    <Placeholderable text={product.owner} className="truncate text-xs text-fg-muted" />
                </span>
            ),
        },
        {
            key: 'stack',
            header: 'Stack',
            width: '120px',
            render: (product) => (
                <span className="flex min-w-0 flex-col">
                    <span className="truncate">{product.stack}</span>
                    <span className="truncate text-xs text-fg-muted">{product.host}</span>
                </span>
            ),
        },
        { key: 'state', header: 'Estado', width: '136px', render: (product) => <ProductStateChip state={product.state} /> },
        { key: 'rating', header: 'Calificación', width: '104px', render: (product) => <RatingCell product={product} /> },
        {
            key: 'hours',
            header: 'Horas al mes',
            width: '90px',
            align: 'end',
            render: (product) => <span className={cx('tabular', product.hours_saved_per_month === null ? 'text-fg-muted' : 'font-semibold')}>{hours(product.hours_saved_per_month)}</span>,
        },
        ...(canViewCosts
            ? [
                  {
                      key: 'cost',
                      header: (
                          <span className="inline-flex items-center gap-1" title="Solo lo ven quienes tienen permiso de costos">
                              <Icon name="lock" size={12} />
                              Costo al mes
                          </span>
                      ),
                      width: '118px',
                      align: 'end' as const,
                      render: (product: Product) => <span className="tabular">{product.monthly_cost === null ? '—' : formatCOP(product.monthly_cost)}</span>,
                  },
              ]
            : []),
        {
            key: 'open',
            header: 'Solicitudes',
            width: '92px',
            align: 'end',
            render: (product) => (
                <span className={cx('text-[13px]', product.open_requests > 0 ? 'font-bold' : 'text-fg-muted')}>{openRequestsText(product.open_requests)}</span>
            ),
        },
    ];

    const template = columns.map((column) => column.width ?? 'minmax(0, 1fr)').join(' ');

    return (
        <GestorLayout shared={props} title="Productos · Gestor IA" tab="products" mode="it">
            <div className="flex flex-col gap-4">
                <div className="flex flex-wrap items-end justify-between gap-3">
                    <div className="flex max-w-[640px] flex-col gap-1">
                        <h1 className="font-display text-[26px] leading-tight font-bold tracking-[-0.02em] md:text-[30px]">Productos</h1>
                        <p className="text-sm text-fg-soft">Las apps que TI ya construyó, en qué versión van y cuánto tiempo le devuelven a cada área.</p>
                    </div>
                    <Button icon="plus" href={route('ai.products.create')}>
                        Agregar producto
                    </Button>
                </div>

                <div className="flex flex-wrap items-center gap-2">
                    <form role="search" onSubmit={onSearch} className="w-full sm:w-[260px]">
                        <label className="flex h-11 items-center gap-2 rounded-control border border-line-strong bg-surface px-3 text-sm has-focus-visible:outline-2 has-focus-visible:outline-offset-2 has-focus-visible:outline-fg">
                            <Icon name="search" size={16} className="text-fg-muted" />
                            <span className="sr-only">Buscar producto</span>
                            <input
                                type="search"
                                value={q}
                                onChange={(event) => setQ(event.target.value)}
                                placeholder="Nombre, stack o dueño"
                                className="min-w-0 flex-1 bg-transparent outline-none placeholder:text-fg-muted"
                            />
                        </label>
                    </form>
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
                        options={[
                            { value: ALL, label: 'todas' },
                            ...areas.map((area) => ({
                                value: String(area.id),
                                label: allCompanies && companies.length > 1 ? `${area.name} · ${companyOf(area.company_id)?.short_name ?? ''}` : area.name,
                            })),
                        ]}
                        onChange={(value) => apply({ area_id: value === ALL ? null : Number(value) })}
                    />
                    <div className="max-w-full overflow-x-auto">
                        <SegmentedControl<ProductState | typeof ALL>
                            label="Estado"
                            value={filters.state ?? ALL}
                            onChange={(value) => apply({ state: value === ALL ? null : value })}
                            options={[
                                { value: ALL, label: `Todos · ${totalCount}` },
                                ...PRODUCT_STATE_ORDER.map((state) => ({ value: state, label: `${PRODUCT_STATES[state].label} · ${state_counts[state]}` })),
                            ]}
                        />
                    </div>
                </div>

                {products.length === 0 ? (
                    <EmptyState
                        icon="search"
                        title={filtered ? 'Ningún producto con estos filtros' : 'Todavía no hay productos'}
                        description={
                            filtered
                                ? 'Cambia el estado, el área o la búsqueda para ver otros productos.'
                                : 'Un producto aparece aquí cuando un proyecto llega a producción o cuando lo agregas a mano.'
                        }
                        actions={
                            filtered ? (
                                <Button
                                    variant="secondary"
                                    onClick={() => {
                                        setQ('');
                                        apply({ q: null, company_id: null, area_id: null, state: null });
                                    }}
                                >
                                    Quitar filtros
                                </Button>
                            ) : (
                                <Button variant="secondary" icon="plus" href={route('ai.products.create')}>
                                    Agregar producto
                                </Button>
                            )
                        }
                    />
                ) : (
                    <>
                        <section aria-label="Lista de productos" className="hidden overflow-hidden rounded-card border border-line bg-surface md:block">
                            <DataTable
                                label="Productos"
                                columns={columns}
                                rows={products}
                                rowKey={(product) => product.slug}
                                rowHref={(product) => route('ai.products.show', { product: product.slug })}
                                minWidth={canViewCosts ? 1000 : 880}
                            />
                            <div className="overflow-x-auto">
                                <div
                                    style={{ gridTemplateColumns: template, minWidth: canViewCosts ? 1000 : 880 }}
                                    className="grid min-h-12 items-center gap-x-4 border-t border-line bg-subtle px-5 text-[13px] font-bold"
                                >
                                    {columns.map((column) => {
                                        const value =
                                            column.key === 'name'
                                                ? `${totals.count} ${totals.count === 1 ? 'producto' : 'productos'}`
                                                : column.key === 'rating'
                                                  ? totals.rating === null
                                                      ? '—'
                                                      : `${totals.rating.toLocaleString('es-CO', { minimumFractionDigits: 1, maximumFractionDigits: 1 })} promedio`
                                                  : column.key === 'hours'
                                                    ? hours(totals.hours)
                                                    : column.key === 'cost'
                                                      ? totals.cost === null
                                                          ? '—'
                                                          : formatCOP(totals.cost)
                                                      : column.key === 'open'
                                                        ? String(totals.open)
                                                        : '';
                                        return (
                                            <span key={column.key} className={cx('tabular min-w-0', column.align === 'end' && 'text-right')}>
                                                {value}
                                            </span>
                                        );
                                    })}
                                </div>
                            </div>
                        </section>

                        <ul className="flex flex-col gap-3 md:hidden" aria-label="Lista de productos">
                            {products.map((product) => (
                                <li key={product.slug}>
                                    <ProductCard product={product} company={companyOf(product.company_id)} showCompany={allCompanies} canViewCosts={canViewCosts} />
                                </li>
                            ))}
                        </ul>
                        <p className="tabular text-[13px] text-fg-muted md:hidden">
                            {totals.count} productos · {hours(totals.hours)} ahorradas al mes · {totals.open} solicitudes abiertas
                        </p>
                    </>
                )}

                {canViewCosts ? (
                    <CostsLockNote>La columna de costo solo aparece para quienes tienen el permiso de costos. Los solicitantes ven la ficha sin cifras de dinero.</CostsLockNote>
                ) : (
                    <CostsLockNote>El costo mensual de cada app solo lo ven quienes tienen el permiso de costos.</CostsLockNote>
                )}
            </div>
        </GestorLayout>
    );
}
