import { router } from '@inertiajs/react';
import { useId, useState } from 'react';
import { AdminPageHeader, FilterSelect, FilterToggle } from '@/Components/admin/AdminParts';
import { auditQuery, DEFAULT_AUDIT_FILTERS, isAuditFiltered } from '@/Components/admin/auditQuery';
import { Button, buttonClasses } from '@/Components/ui/Button';
import { Chip } from '@/Components/ui/Chip';
import { EmptyState } from '@/Components/ui/EmptyState';
import { Icon } from '@/Components/ui/Icon';
import { Pagination } from '@/Components/ui/Pagination';
import { cx } from '@/Components/ui/utils';
import { AdminLayout } from '@/Layouts/AdminLayout';
import { formatDateTime, formatRelative } from '@/lib/format';
import { route } from '@/lib/route';
import type { AdminAuditIndexProps, AuditEntry, AuditFilters, AuditOrigin, AuditRange } from '@/types/pages/admin';
import type { PageProps } from '@/types/shared';

const ORIGIN_LABELS: Record<AuditOrigin, string> = { web: 'Web', telegram: 'Telegram', system: 'Sistema', api: 'API' };

const RANGES: { value: AuditRange; label: string }[] = [
    { value: '24h', label: 'últimas 24 horas' },
    { value: '7d', label: 'últimos 7 días' },
    { value: '30d', label: 'últimos 30 días' },
    { value: 'all', label: 'todo' },
];

const GRID = 'md:grid md:grid-cols-[130px_170px_minmax(0,1fr)_110px] md:items-center md:gap-x-4';

function AuditRow({ entry }: { entry: AuditEntry }) {
    const [open, setOpen] = useState(false);
    const panelId = useId();
    const expandable = (entry.changes?.length ?? 0) > 0;
    const who = entry.causer?.name ?? 'Sin identificar';
    const origin = entry.origin === 'web' && entry.causer === null && entry.ip ? `IP ${entry.ip}` : ORIGIN_LABELS[entry.origin];

    const what = (
        <span className="flex min-w-0 flex-wrap items-center gap-x-2 gap-y-1">
            {entry.alert ? (
                <Chip tone="red" size="sm" icon="alert-triangle">
                    Alerta
                </Chip>
            ) : null}
            {entry.sensitive ? (
                <span className="text-fg-muted" title="Acción sensible">
                    <Icon name="lock" size={14} label="Acción sensible" />
                </span>
            ) : null}
            <span className="min-w-0">{entry.description}</span>
        </span>
    );

    const cells = (
        <>
            <span className="text-[13px] text-fg-soft" title={formatDateTime(entry.at)}>
                <time dateTime={entry.at}>{formatRelative(entry.at)}</time>
            </span>
            <b className={cx('truncate font-semibold', entry.causer === null && 'text-fg-soft')}>{who}</b>
            {what}
            <span className="text-[13px] text-fg-soft">
                {origin}
                {entry.ip && entry.causer !== null ? <span className="block text-xs text-fg-muted md:hidden">IP {entry.ip}</span> : null}
            </span>
        </>
    );

    return (
        <li className={cx('border-t border-line', open && 'bg-ground')}>
            {expandable ? (
                <button
                    type="button"
                    aria-expanded={open}
                    aria-controls={panelId}
                    onClick={() => setOpen(!open)}
                    className={cx(
                        'flex min-h-14 w-full flex-col gap-1 px-5 py-3 text-left text-sm focus-visible:-outline-offset-2 md:py-2',
                        GRID,
                        'md:grid-cols-[130px_170px_minmax(0,1fr)_110px_20px]',
                    )}
                >
                    {cells}
                    <span className="hidden text-fg-muted md:block" aria-hidden="true">
                        <Icon name="chevron-down" size={16} className={cx('transition-transform duration-150', open && 'rotate-180')} />
                    </span>
                    <span className="text-xs font-semibold text-fg-soft md:hidden">{open ? 'Ocultar el cambio' : 'Ver el cambio'}</span>
                </button>
            ) : (
                <div className={cx('flex min-h-14 flex-col gap-1 px-5 py-3 text-sm md:py-2', GRID, 'md:grid-cols-[130px_170px_minmax(0,1fr)_110px_20px]')}>
                    {cells}
                </div>
            )}
            {expandable ? (
                <div id={panelId} hidden={!open} className="px-5 pb-3.5 md:pl-[336px]">
                    <dl className="grid grid-cols-[90px_minmax(0,1fr)] gap-x-3 gap-y-1.5 rounded-control border border-line bg-surface px-3.5 py-3 text-[13px] sm:grid-cols-[140px_minmax(0,1fr)]">
                        {entry.changes?.map((change) => (
                            <div key={change.field} className="contents">
                                {entry.changes && entry.changes.length > 1 ? (
                                    <dt className="col-span-2 pt-1 font-bold first:pt-0">{change.field}</dt>
                                ) : null}
                                <dt className="text-fg-muted">Antes</dt>
                                <dd className={cx('min-w-0 break-words', change.old === null ? 'text-fg-muted' : 'text-status-failure line-through')}>
                                    {change.old ?? '(vacío)'}
                                </dd>
                                <dt className="text-fg-muted">Después</dt>
                                <dd className="min-w-0 font-semibold break-words text-status-active">{change.new ?? '(vacío)'}</dd>
                            </div>
                        ))}
                    </dl>
                </div>
            ) : null}
        </li>
    );
}

/** Auditoría: read-only log with filters, expandable before/after diffs and CSV export. */
export default function AdminAuditIndex(props: PageProps<AdminAuditIndexProps>) {
    const { entries, filters, causer_options, module_options } = props;

    const apply = (next: AuditFilters) => {
        router.get(route('admin.audit.index'), auditQuery(next), { preserveState: true, preserveScroll: true, replace: true });
    };

    const filtered = isAuditFiltered(filters);
    const empty = entries.data.length === 0;

    return (
        <AdminLayout shared={props} title="Auditoría · Administración" tab="audit">
            <div className="flex min-w-0 flex-col gap-3.5">
                <AdminPageHeader
                    title="Auditoría"
                    description="Quién cambió qué y cuándo. No se puede editar ni borrar."
                    actions={
                        <a href={route('admin.audit.export', auditQuery(filters))} download className={buttonClasses({ variant: 'secondary' })}>
                            <Icon name="download" size={16} strokeWidth={2.4} />
                            Descargar CSV
                        </a>
                    }
                />

                <div role="group" aria-label="Filtros" className="flex flex-wrap gap-2">
                    <FilterSelect
                        label="Persona"
                        value={filters.causer_id === null ? '' : String(filters.causer_id)}
                        onChange={(event) => apply({ ...filters, causer_id: event.target.value === '' ? null : Number(event.target.value) })}
                        options={[{ value: '', label: 'todas' }, ...causer_options.map((person) => ({ value: String(person.id), label: person.name }))]}
                    />
                    <FilterSelect
                        label="Módulo"
                        value={filters.module ?? ''}
                        onChange={(event) => apply({ ...filters, module: event.target.value || null })}
                        options={[{ value: '', label: 'todos' }, ...module_options]}
                    />
                    <FilterToggle pressed={filters.sensitive_only} onChange={(pressed) => apply({ ...filters, sensitive_only: pressed })}>
                        Solo acciones sensibles
                    </FilterToggle>
                    <FilterSelect
                        label="Periodo"
                        value={filters.range}
                        onChange={(event) => apply({ ...filters, range: event.target.value as AuditRange })}
                        options={RANGES}
                    />
                </div>

                {empty ? (
                    <EmptyState
                        icon={filtered ? 'filter' : 'list'}
                        title={filtered ? 'Nada coincide con estos filtros' : 'Todavía no hay registros'}
                        description={
                            filtered
                                ? 'Prueba con otro periodo o quita los filtros.'
                                : 'Aquí aparece cada cambio de empresas, roles, contraseñas e integraciones.'
                        }
                        actions={
                            filtered ? (
                                <Button variant="secondary" onClick={() => apply(DEFAULT_AUDIT_FILTERS)}>
                                    Quitar filtros
                                </Button>
                            ) : undefined
                        }
                    />
                ) : (
                    <section aria-label="Registro de auditoría" className="overflow-hidden rounded-card border border-line bg-surface">
                        <div
                            aria-hidden="true"
                            className="hidden h-10 grid-cols-[130px_170px_minmax(0,1fr)_110px_20px] items-center gap-x-4 bg-subtle px-5 text-xs font-bold text-fg-muted md:grid"
                        >
                            <span>Cuándo</span>
                            <span>Quién</span>
                            <span>Qué hizo</span>
                            <span>Desde</span>
                        </div>
                        <ul className="[&>li:first-child]:border-t-0 md:[&>li:first-child]:border-t">
                            {entries.data.map((entry) => (
                                <AuditRow key={entry.id} entry={entry} />
                            ))}
                        </ul>
                        <Pagination paginated={entries} />
                    </section>
                )}
            </div>
        </AdminLayout>
    );
}
