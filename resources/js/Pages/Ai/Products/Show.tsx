import { Link } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { CostsLockNote, Placeholderable, ProductStateChip, RatingSquares } from '@/Components/ai/products/ProductParts';
import { requestsBreakdown } from '@/Components/ai/products/products';
import { Button, buttonClasses } from '@/Components/ui/Button';
import { Chip } from '@/Components/ui/Chip';
import { CompanyMark } from '@/Components/ui/CompanyMark';
import { Icon } from '@/Components/ui/Icon';
import { PhaseRail } from '@/Components/ui/PhaseRail';
import { StatTile } from '@/Components/ui/StatTile';
import { StatusChip } from '@/Components/ui/StatusChip';
import { cx } from '@/Components/ui/utils';
import { GestorLayout } from '@/Layouts/GestorLayout';
import { can } from '@/lib/can';
import { formatCOP, formatRelative, formatShortDate } from '@/lib/format';
import { route } from '@/lib/route';
import type { DocSummary, RequestSummary } from '@/types/ai';
import type { ProductHealth, ProductIncident, ProductsShowProps } from '@/types/pages/ai-knowledge';
import type { PageProps } from '@/types/shared';

const decimal = (value: number) => value.toLocaleString('es-CO', { minimumFractionDigits: 1, maximumFractionDigits: 1 });

function Card({ title, actions, children, className }: { title: string; actions?: ReactNode; children: ReactNode; className?: string }) {
    return (
        <section aria-label={title} className={cx('flex min-w-0 flex-col overflow-hidden rounded-card border border-line bg-surface', className)}>
            <div className="flex flex-wrap items-center justify-between gap-x-3 gap-y-1 px-5 pt-4 pb-3">
                <h2 className="font-display text-lg leading-tight font-bold">{title}</h2>
                {actions}
            </div>
            {children}
        </section>
    );
}

/** Title without the product prefix: "Visualizador de cartera: filtro por asesor…" → "Filtro por asesor…". */
function shortTitle(title: string, productName: string): string {
    const prefix = `${productName}: `;
    if (!title.startsWith(prefix)) return title;
    const rest = title.slice(prefix.length);
    return `${rest.charAt(0).toUpperCase()}${rest.slice(1)}`;
}

const HEALTH_PARTS = [
    { title: 'Estado', text: 'Si la app responde o está caída, con forma y texto' },
    { title: 'Último latido', text: 'Hace cuánto avisó la app que sigue viva' },
    { title: 'Errores', text: 'Cuántos errores tuvo en las últimas 24 horas' },
];

const HEALTH_STATUS: Record<'up' | 'degraded' | 'down', { label: string; tone: 'green' | 'amber' | 'red'; icon: 'check-circle' | 'alert-triangle' | 'x-circle' }> = {
    up: { label: 'Responde', tone: 'green', icon: 'check-circle' },
    degraded: { label: 'Lenta', tone: 'amber', icon: 'alert-triangle' },
    down: { label: 'Caída', tone: 'red', icon: 'x-circle' },
};

function HealthBlock({ health, now }: { health: ProductHealth; now: string }) {
    if (!health.enabled) {
        return (
            <section aria-label="Salud de la app" className="flex flex-col gap-4 rounded-card border-2 border-dashed border-line-strong bg-ground/60 p-5">
                <div className="flex items-start justify-between gap-3">
                    <div className="flex flex-col gap-0.5">
                        <h2 className="font-display text-lg leading-tight font-bold">Salud de la app</h2>
                        <span className="text-[13px] font-semibold text-fg-muted">Monitoreo aún no activo</span>
                    </div>
                    <span aria-hidden="true" className="flex items-center gap-1.5 pt-1.5">
                        <span className="size-2.5 rounded-full border-2 border-line-strong" />
                        <span className="size-2.5 rotate-45 border-2 border-line-strong" />
                        <span className="size-2.5 border-2 border-line-strong" />
                    </span>
                </div>
                <div className="grid gap-3 sm:grid-cols-3">
                    {HEALTH_PARTS.map((part) => (
                        <div key={part.title} className="flex flex-col gap-1 rounded-control border border-dashed border-line-strong p-3">
                            <span className="text-[13px] font-bold text-fg-soft">{part.title}</span>
                            <span className="text-[13px] leading-snug text-fg-muted">{part.text}</span>
                        </div>
                    ))}
                </div>
                <p className="text-[13px] text-fg-muted">Se llena solo cuando la app envíe su latido al Gestor con el Kit RYR.</p>
            </section>
        );
    }
    const status = HEALTH_STATUS[health.status];
    return (
        <Card title="Salud de la app">
            <dl className="grid gap-3 px-5 pb-5 sm:grid-cols-3">
                <div className="flex flex-col gap-1">
                    <dt className="text-[13px] text-fg-muted">Estado</dt>
                    <dd>
                        <Chip tone={status.tone} icon={status.icon}>
                            {status.label}
                        </Chip>
                    </dd>
                </div>
                <div className="flex flex-col gap-1">
                    <dt className="text-[13px] text-fg-muted">Último latido</dt>
                    <dd className="text-sm font-semibold">{formatRelative(health.last_heartbeat_at, new Date(now))}</dd>
                </div>
                <div className="flex flex-col gap-1">
                    <dt className="text-[13px] text-fg-muted">Errores en 24 h</dt>
                    <dd className="tabular text-sm font-semibold">{health.errors_24h}</dd>
                </div>
            </dl>
        </Card>
    );
}

function Incidents({ incidents, slug }: { incidents: ProductIncident[]; slug: string }) {
    return (
        <Card
            title="Incidentes"
            actions={
                <Button variant="secondary" href={route('ai.products.incidents.create', { product: slug })}>
                    Registrar incidente
                </Button>
            }
        >
            {incidents.length === 0 ? (
                <div className="mx-5 mb-5 flex items-start gap-3 rounded-control bg-ground p-4">
                    <span className="flex size-10 shrink-0 items-center justify-center rounded-icon bg-subtle text-fg-soft">
                        <Icon name="check-circle" size={20} />
                    </span>
                    <span className="flex flex-col gap-0.5">
                        <b className="text-sm">Sin incidentes registrados</b>
                        <span className="text-[13px] leading-snug text-fg-muted">Con el monitoreo apagado, los incidentes solo aparecen si alguien los registra a mano.</span>
                    </span>
                </div>
            ) : (
                <ul className="flex flex-col">
                    {incidents.map((incident) => (
                        <li key={incident.id} className="flex flex-wrap items-center justify-between gap-2 border-t border-line px-5 py-3 text-sm">
                            <span className="flex min-w-0 flex-col">
                                <b>{incident.title}</b>
                                <span className="text-[13px] text-fg-muted">
                                    {formatShortDate(incident.started_at)} · {incident.reported_by.name}
                                </span>
                            </span>
                            <Chip tone={incident.resolved_at ? 'green' : 'red'} icon={incident.resolved_at ? 'check-circle' : 'alert-triangle'} size="sm">
                                {incident.resolved_at ? 'Resuelto' : 'Abierto'}
                            </Chip>
                        </li>
                    ))}
                </ul>
            )}
        </Card>
    );
}

function RequestCard({ request, productName, note }: { request: RequestSummary; productName: string; note: string | undefined }) {
    return (
        <Link
            href={route('ai.requests.show', { request: request.key })}
            className="flex flex-col gap-2 border-t border-line px-5 py-3.5 text-fg no-underline transition-colors duration-150 hover:bg-ground"
        >
            <span className="flex flex-wrap items-center gap-2">
                <b className="tabular text-[13px]">{request.key}</b>
                <StatusChip status={request.status} size="sm" />
            </span>
            <span className="text-sm font-semibold">{shortTitle(request.title, productName)}</span>
            <PhaseRail status={request.status} variant="bar" />
            {note ? <span className="text-[13px] text-fg-muted">{note}</span> : null}
        </Link>
    );
}

function DocRow({ doc, now }: { doc: DocSummary; now: string }) {
    if (doc.not_applicable) {
        return (
            <div className="flex items-center justify-between gap-3 border-t border-line px-5 py-3">
                <span className="flex min-w-0 flex-col">
                    <b className="text-sm text-fg-soft">{doc.title}</b>
                    <span className="text-[13px] text-fg-muted">{doc.template === 'prompts' ? 'No aplica: esta app no usa IA' : 'No aplica'}</span>
                </span>
                <Chip tone="outline" size="sm">
                    n/a
                </Chip>
            </div>
        );
    }
    const relative = formatRelative(doc.updated_at, new Date(now));
    const when = relative.startsWith('hace') || relative.startsWith('hoy') || relative.startsWith('ayer') ? relative : formatShortDate(doc.updated_at);
    return (
        <Link
            href={route('ai.docs.edit', { doc: doc.id })}
            className="flex min-h-14 items-center justify-between gap-3 border-t border-line px-5 py-3 text-fg no-underline transition-colors duration-150 hover:bg-ground"
        >
            <span className="flex min-w-0 flex-col">
                <b className="text-sm">{doc.title}</b>
                <span className="text-[13px] text-fg-muted">
                    versión {doc.version} · {when}
                </span>
            </span>
            <Icon name="chevron-right" size={16} className="shrink-0 text-fg-muted" />
        </Link>
    );
}

/** Ficha de producto: metadata, results, health placeholder, versions, open requests, docs and "Pedir mejora". */
export default function ProductsShow(props: PageProps<ProductsShowProps>) {
    const { product, health, incidents, hours_note, cost_note, request_notes, now, companies, auth } = props;
    const company = companies.find((item) => item.id === product.company_id);
    const canViewCosts = can(auth.user, 'ai.costs.view');
    const improveHref = route('ai.requests.create', { producto: product.slug });

    const meta: { term: string; value: ReactNode }[] = [
        { term: 'Dueño', value: <Placeholderable text={product.owner} /> },
        { term: 'Áreas', value: product.areas.join(' · ') },
        {
            term: 'Dirección',
            value: product.url ? (
                <a href={product.url} target="_blank" rel="noopener noreferrer" className="font-semibold break-all text-fg underline underline-offset-2">
                    <Placeholderable text={product.url} />
                </a>
            ) : (
                'Sin publicar'
            ),
        },
        {
            term: 'Repositorio',
            value: product.repository ? (
                <a href={product.repository} target="_blank" rel="noopener noreferrer" className="font-semibold break-all text-fg underline underline-offset-2">
                    <Placeholderable text={product.repository} />
                </a>
            ) : (
                'Sin repositorio'
            ),
        },
        { term: 'Stack', value: `${product.stack} · ${product.host}` },
        { term: 'Modelo de IA', value: product.ai_model ?? 'No usa IA' },
    ];

    return (
        <GestorLayout
            shared={props}
            title={`${product.name} · Productos`}
            tab="products"
            mode="it"
            breadcrumb={[{ label: 'Productos', href: route('ai.products.index') }, { label: product.name }]}
        >
            <div className="flex flex-col gap-4">
                <section aria-labelledby="product-title" className="flex flex-col gap-5 rounded-card border border-line bg-surface p-5 md:p-6">
                    <div className="flex flex-wrap items-start justify-between gap-4">
                        <div className="flex min-w-0 items-start gap-3.5">
                            {company ? <CompanyMark company={company} size={48} /> : null}
                            <div className="flex min-w-0 flex-col gap-1">
                                <div className="flex flex-wrap items-center gap-2">
                                    <h1 id="product-title" className="font-display text-[24px] leading-tight font-bold tracking-[-0.02em] md:text-[28px]">
                                        {product.name}
                                    </h1>
                                    <ProductStateChip state={product.state} size="md" />
                                    <Chip tone="outline" size="sm">
                                        {product.version}
                                    </Chip>
                                </div>
                                <p className="text-sm text-fg-soft">
                                    {product.slug} · {company?.short_name ?? ''} · {product.description}
                                </p>
                            </div>
                        </div>
                        <div className="flex flex-wrap gap-2">
                            {product.url ? (
                                <a href={product.url} target="_blank" rel="noopener noreferrer" className={buttonClasses({ variant: 'secondary' })}>
                                    <Icon name="external" size={16} />
                                    Abrir la app
                                    <span className="sr-only">(se abre en otra pestaña)</span>
                                </a>
                            ) : null}
                            <Button variant="dark" href={route('ai.products.edit', { product: product.slug })}>
                                Editar ficha
                            </Button>
                        </div>
                    </div>
                    <dl className="grid grid-cols-2 gap-x-5 gap-y-3 border-t border-line pt-4 md:grid-cols-3 xl:grid-cols-6">
                        {meta.map((item) => (
                            <div key={item.term} className="flex min-w-0 flex-col gap-0.5">
                                <dt className="text-xs font-semibold text-fg-muted">{item.term}</dt>
                                <dd className="text-sm">{item.value}</dd>
                            </div>
                        ))}
                    </dl>
                </section>

                <section aria-label="Resultados del producto" className="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">
                    <StatTile
                        label="Calificación"
                        value={product.rating ? `${decimal(product.rating.average)} de 5` : 'Sin calificar'}
                        note={
                            product.rating ? (
                                <span className="flex flex-col gap-2">
                                    <RatingSquares average={product.rating.average} />
                                    {product.rating.count} {product.rating.count === 1 ? 'persona calificó' : 'personas calificaron'}
                                </span>
                            ) : (
                                'Aún nadie la califica'
                            )
                        }
                    />
                    <StatTile
                        label="Horas ahorradas al mes"
                        value={product.hours_saved_per_month === null ? '—' : `${product.hours_saved_per_month.toLocaleString('es-CO')} h`}
                        note={hours_note ?? undefined}
                    />
                    {canViewCosts && product.monthly_cost !== null ? (
                        <StatTile label="Costo mensual" value={formatCOP(product.monthly_cost)} note={cost_note ?? undefined} restricted />
                    ) : (
                        <div className="flex flex-col gap-2 rounded-card border border-dashed border-line-strong p-4 md:p-5">
                            <span className="text-[13px] font-semibold text-fg-soft">Costo mensual</span>
                            <CostsLockNote>Solo lo ven quienes tienen el permiso de costos.</CostsLockNote>
                        </div>
                    )}
                    <StatTile label="Solicitudes abiertas" value={String(product.requests.length)} note={requestsBreakdown(product.requests)} />
                </section>

                <div className="grid gap-4 lg:grid-cols-[minmax(0,1.35fr)_minmax(0,1fr)] lg:items-start">
                    <div className="flex min-w-0 flex-col gap-4">
                        <HealthBlock health={health} now={now} />

                        <Card
                            title="Versiones"
                            actions={
                                product.next_version ? (
                                    <span className="text-[13px] text-fg-muted">
                                        Siguiente: {product.next_version.tag} con{' '}
                                        <Link href={route('ai.requests.show', { request: product.next_version.request_key })} className="font-semibold text-fg">
                                            {product.next_version.request_key}
                                        </Link>
                                    </span>
                                ) : undefined
                            }
                        >
                            <ol className="flex flex-col">
                                {product.versions.map((version) => (
                                    <li key={version.tag} className="grid gap-x-4 gap-y-2 border-t border-line px-5 py-3.5 sm:grid-cols-[110px_minmax(0,1fr)]">
                                        <div className="flex flex-wrap items-center gap-x-2 gap-y-1 sm:flex-col sm:items-start">
                                            <b className="tabular">{version.tag}</b>
                                            <span className="text-[13px] text-fg-muted">{formatShortDate(version.date)}</span>
                                            {version.current ? (
                                                <Chip tone="green" icon="check" size="sm">
                                                    En uso
                                                </Chip>
                                            ) : null}
                                        </div>
                                        <div className="flex min-w-0 flex-col gap-1.5">
                                            <span className="text-sm">{version.summary}</span>
                                            <ul className="flex flex-col gap-1">
                                                {version.request_keys.map((request) => (
                                                    <li key={request.key} className="flex flex-wrap gap-x-2 text-[13px]">
                                                        <Link href={route('ai.requests.show', { request: request.key })} className="tabular font-bold text-fg">
                                                            {request.key}
                                                        </Link>
                                                        <span className="text-fg-soft">{request.title}</span>
                                                    </li>
                                                ))}
                                            </ul>
                                        </div>
                                    </li>
                                ))}
                            </ol>
                        </Card>

                        <Incidents incidents={incidents} slug={product.slug} />
                    </div>

                    <div className="flex min-w-0 flex-col gap-4">
                        <Card
                            title="Solicitudes abiertas"
                            actions={
                                product.requests.length > 0 ? (
                                    <Link href={route('ai.inbox.index', { product: product.slug })} className="flex min-h-11 items-center text-[13px] font-bold text-fg">
                                        Ver todas
                                    </Link>
                                ) : undefined
                            }
                        >
                            {product.requests.length === 0 ? (
                                <p className="px-5 pb-5 text-sm text-fg-muted">Nada abierto. Las mejoras que pidan aparecen aquí.</p>
                            ) : (
                                product.requests.map((request) => (
                                    <RequestCard key={request.key} request={request} productName={product.name} note={request_notes[request.key]} />
                                ))
                            )}
                        </Card>

                        <Card
                            title="Documentación"
                            actions={
                                can(auth.user, 'ai.docs.edit') ? (
                                    <Link
                                        href={route('ai.docs.create', { owner_kind: 'product', owner_key: product.slug })}
                                        className="flex min-h-11 items-center gap-1 text-[13px] font-bold text-fg"
                                    >
                                        <Icon name="plus" size={14} />
                                        Nuevo documento
                                    </Link>
                                ) : undefined
                            }
                        >
                            {product.docs.length === 0 ? (
                                <p className="px-5 pb-5 text-sm text-fg-muted">Sin documentos todavía.</p>
                            ) : (
                                product.docs.map((doc) => <DocRow key={doc.id} doc={doc} now={now} />)
                            )}
                        </Card>

                        <section aria-labelledby="improve" className="flex flex-col gap-3 rounded-card border border-line bg-surface p-5">
                            <h2 id="improve" className="font-display text-lg leading-tight font-bold">
                                Botón “Pedir mejora”
                            </h2>
                            <p className="text-sm leading-normal text-fg-soft">
                                Viene en el Kit RYR y va dentro de {product.name}. Quien lo usa llega al formulario de solicitud con este producto ya elegido, sin
                                buscarlo.
                            </p>
                            <div className="flex flex-wrap items-center justify-between gap-3 rounded-control bg-ground p-3">
                                <span className="text-[13px] text-fg-muted">Así se ve dentro de la app</span>
                                <a href={improveHref} className={buttonClasses({ variant: 'secondary' })}>
                                    <Icon name="message" size={16} />
                                    Pedir mejora
                                </a>
                            </div>
                            <span className="text-[13px] text-fg-muted">
                                {product.requests_from_button_this_month === 0
                                    ? 'Este mes no llegaron solicitudes por este botón.'
                                    : `Este mes ${product.requests_from_button_this_month === 1 ? 'llegó 1 solicitud' : `llegaron ${product.requests_from_button_this_month} solicitudes`} por este botón.`}
                            </span>
                        </section>
                    </div>
                </div>
            </div>
        </GestorLayout>
    );
}
