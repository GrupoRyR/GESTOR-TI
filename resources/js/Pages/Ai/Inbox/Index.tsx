import { router } from '@inertiajs/react';
import { useState } from 'react';
import { RequestActionDialogs, useRequestActions, type RequestAction } from '@/Components/ai/requests/actions/RequestActionDialogs';
import { RequestActionMenu } from '@/Components/ai/requests/actions/RequestActionMenu';
import { DueText } from '@/Components/ai/triage/FirstResponse';
import { FilterSelect, InboxCard, requestMeta, SelectBox } from '@/Components/ai/triage/InboxParts';
import { answeredLabel, firstResponseState } from '@/Components/ai/triage/triage';
import { Button } from '@/Components/ui/Button';
import { CompanyMark } from '@/Components/ui/CompanyMark';
import { DataTable, type DataTableColumn } from '@/Components/ui/DataTable';
import { EmptyState } from '@/Components/ui/EmptyState';
import { Icon } from '@/Components/ui/Icon';
import { Pagination } from '@/Components/ui/Pagination';
import { PhaseRail } from '@/Components/ui/PhaseRail';
import { Semaphore } from '@/Components/ui/Semaphore';
import { SizeTag } from '@/Components/ui/SizeTag';
import { StatusChip } from '@/Components/ui/StatusChip';
import { cx } from '@/Components/ui/utils';
import { GestorLayout } from '@/Layouts/GestorLayout';
import { can } from '@/lib/can';
import { formatRelative } from '@/lib/format';
import { hasRoute, route } from '@/lib/route';
import type { RequestSummary } from '@/types/ai';
import type { InboxFilters, InboxIndexProps, InboxQuick } from '@/types/pages/ai-triage';
import type { PageProps } from '@/types/shared';

const QUICK: { value: InboxQuick; label: string }[] = [
    { value: 'unanswered', label: 'Sin primera respuesta' },
    { value: 'needs_info', label: 'Necesita información' },
    { value: 'pending_approval', label: 'Pendiente de aprobación' },
    { value: 'all', label: 'Todas' },
];

const ALL = 'all';

/** Query for `ai.inbox.index`: only what differs from "Todas" without filters. */
function toQuery(filters: InboxFilters): Record<string, string | number> {
    const query: Record<string, string | number> = {};
    if (filters.quick !== 'all') query.quick = filters.quick;
    if (filters.area_id !== null) query.area_id = filters.area_id;
    if (filters.company_id !== null) query.company_id = filters.company_id;
    return query;
}

/** Bandeja: every request waiting on IT, ordered by first response, with bulk and row actions. */
export default function InboxIndex(props: PageProps<InboxIndexProps>) {
    const { requests, filters, counts, areas, companies, current_company_id, auth, first_response_business_days, last_answered } = props;
    const now = new Date(props.now);
    const actions = useRequestActions();
    const [selected, setSelected] = useState<string[]>([]);
    const [assignee, setAssignee] = useState('');
    const allCompanies = current_company_id === null;
    const canViewCosts = can(auth.user, 'ai.costs.view');
    const canApprove = can(auth.user, 'ai.projects.approve');
    const canPropose = can(auth.user, 'ai.manage');
    const rows = requests.data;
    const companyOf = (id: number) => companies.find((company) => company.id === id);
    const selectedRows = rows.filter((row) => selected.includes(row.key));
    const states = rows.map((row) => firstResponseState(row.first_response, row.created_at, now));
    const late = states.filter((state) => state.level === 'late').length;
    const dueToday = states.filter((state) => state.level === 'today').length;

    const apply = (next: Partial<InboxFilters>) => {
        setSelected([]);
        router.get(route('ai.inbox.index'), toQuery({ ...filters, ...next }), { preserveState: true, preserveScroll: true, replace: true });
    };

    const toggle = (key: string, checked: boolean) => setSelected((previous) => (checked ? [...previous, key] : previous.filter((value) => value !== key)));
    const onRowAction = (row: RequestSummary) => (action: RequestAction) => actions.open(action, [row]);

    const assign = (userId: string) => {
        setAssignee(userId);
        if (!userId) return;
        router.post(route('ai.requests.bulk.assign'), { keys: selected, user_id: Number(userId) }, { preserveScroll: true, onSuccess: () => setSelected([]) });
    };

    const areaOptions = [
        { value: ALL, label: 'todas' },
        ...areas
            .filter((area) => filters.company_id === null || area.company_id === filters.company_id)
            .map((area) => ({
                value: String(area.id),
                label: allCompanies && filters.company_id === null ? `${area.name} · ${companyOf(area.company_id)?.short_name ?? ''}` : area.name,
            })),
    ];

    const columns: DataTableColumn<RequestSummary>[] = [
        {
            key: 'select',
            header: (
                <SelectBox
                    label="Seleccionar todas"
                    checked={rows.length > 0 && selected.length === rows.length}
                    indeterminate={selected.length > 0 && selected.length < rows.length}
                    onChange={(checked) => setSelected(checked ? rows.map((row) => row.key) : [])}
                />
            ),
            width: '24px',
            render: (row) => <SelectBox label={`Seleccionar ${row.key}`} checked={selected.includes(row.key)} onChange={(checked) => toggle(row.key, checked)} />,
        },
        {
            key: 'key',
            header: 'Clave',
            width: allCompanies ? '92px' : '68px',
            render: (row) => {
                const company = companyOf(row.company_id);
                return (
                    <span className="flex items-center gap-1.5">
                        {allCompanies && company ? <CompanyMark company={company} size={20} /> : null}
                        <b className="tabular">{row.key}</b>
                    </span>
                );
            },
        },
        {
            key: 'title',
            header: 'Solicitud',
            rowLink: true,
            render: (row) => (
                <span className="flex min-w-0 flex-col gap-0.5">
                    <b className="line-clamp-2 leading-snug font-semibold">{row.title}</b>
                    <span className="truncate text-xs text-fg-muted">{requestMeta(row, now)}</span>
                </span>
            ),
        },
        { key: 'area', header: 'Área', width: '92px', render: (row) => <span className="line-clamp-2 text-fg-soft">{row.area.name}</span> },
        { key: 'phase', header: 'Fase', width: '58px', render: (row) => <PhaseRail status={row.status} /> },
        { key: 'status', header: 'Estado', width: '172px', render: (row) => <StatusChip status={row.status} size="sm" /> },
        { key: 'size', header: 'Talla', width: '56px', render: (row) => (row.size ? <SizeTag size={row.size} /> : <span className="text-fg-muted">—</span>) },
        {
            key: 'priority',
            header: 'Prior.',
            width: '44px',
            align: 'end',
            render: (row) => (row.priority_final !== null ? <b className="tabular">{row.priority_final}</b> : <span className="text-fg-muted">—</span>),
        },
        {
            key: 'first',
            header: 'Primera respuesta',
            width: '150px',
            render: (row) => {
                const state = firstResponseState(row.first_response, row.created_at, now);
                return <DueText level={state.level} label={state.label} />;
            },
        },
        {
            key: 'menu',
            header: 'Acciones',
            hideHeader: true,
            width: '44px',
            render: (row) => <RequestActionMenu request={row} onAction={onRowAction(row)} />,
        },
    ];

    const filtered = filters.quick !== 'all' || filters.area_id !== null || filters.company_id !== null;
    const allAnswered = rows.length === 0 && filters.quick === 'unanswered' && filters.area_id === null;

    const emptyState = allAnswered ? (
        <section className="flex flex-col items-start gap-3.5 rounded-card border border-line bg-surface p-6 md:p-8">
            <span aria-hidden="true" className="flex size-12 items-center justify-center rounded-icon bg-status-active-bg text-status-active">
                <Icon name="check-circle" size={22} />
            </span>
            <h2 className="font-display text-xl leading-tight font-bold md:text-2xl">No hay solicitudes sin respuesta</h2>
            <p className="max-w-[52ch] text-[15px] leading-normal text-fg-soft">
                Todas las solicitudes nuevas ya tienen primera respuesta.
                {last_answered
                    ? ` La última, ${last_answered.key}, entró ${formatRelative(last_answered.created_at, now)} y ${answeredLabel(last_answered.created_at, last_answered.answered_at).replace('Respondida', 'se respondió')}.`
                    : ''}
            </p>
            <div className="flex flex-wrap items-center gap-2">
                <Button variant="dark" href={route('ai.backlog.index')}>
                    Ver el backlog
                </Button>
                <Button variant="quiet" href={route('ai.week.index')}>
                    Ir a Mi semana
                </Button>
            </div>
        </section>
    ) : (
        <EmptyState
            title="No hay solicitudes con estos filtros"
            description="Prueba con otra área o vuelve a ver todas las solicitudes abiertas."
            actions={
                <Button variant="secondary" onClick={() => apply({ quick: 'all', area_id: null, company_id: null })}>
                    Ver todas
                </Button>
            }
        />
    );

    return (
        <GestorLayout shared={props} title="Bandeja · Gestor IA" tab="inbox" mode="it" counts={{ inbox: counts.unanswered }}>
            <div className="flex flex-col gap-4">
                <div className="flex flex-wrap items-end justify-between gap-3">
                    <div className="flex min-w-0 flex-col gap-1.5">
                        <h1 className="font-display text-[26px] leading-tight font-bold tracking-[-0.02em] md:text-[30px]">
                            <span className="md:hidden">{filters.quick === 'unanswered' ? 'Por responder' : 'Bandeja'}</span>
                            <span className="hidden md:inline">Bandeja de solicitudes</span>
                        </h1>
                        <p className="flex flex-wrap items-center gap-x-4 gap-y-1 text-sm text-fg-soft">
                            <span className="md:hidden">
                                {late > 0 || dueToday > 0 ? (
                                    <b className="text-status-failure">
                                        {[late > 0 ? `${late} ${late === 1 ? 'vencida' : 'vencidas'}` : null, dueToday > 0 ? `${dueToday} ${dueToday === 1 ? 'vence' : 'vencen'} hoy` : null]
                                            .filter(Boolean)
                                            .join(' y ')}
                                    </b>
                                ) : (
                                    'Todo a tiempo'
                                )}
                            </span>
                            <span className="hidden items-center gap-x-4 md:flex">
                                <span className="inline-flex items-center gap-1.5">
                                    <span aria-hidden="true" className="size-2.5 rounded-[2px] bg-status-failure" />
                                    <span>
                                        <b className="text-fg">{counts.unanswered}</b> sin primera respuesta
                                    </span>
                                </span>
                                <span className="inline-flex items-center gap-1.5">
                                    <Icon name="hourglass" size={14} />
                                    <span>
                                        <b className="text-fg">{counts.pending_approval}</b> esperan aprobación
                                    </span>
                                </span>
                            </span>
                        </p>
                    </div>
                    <div className="hidden flex-wrap gap-2 md:flex">
                        <Button variant="secondary" icon="calendar" href={route('ai.roadmap.index')}>
                            Hoja de ruta
                        </Button>
                        {canApprove ? (
                            <Button variant="secondary" icon="hourglass" href={route('ai.approvals.index')}>
                                Ir a aprobaciones
                            </Button>
                        ) : null}
                        {canPropose && hasRoute('ai.proposals.create') ? (
                            <Button variant="dark" icon="plus" href={route('ai.proposals.create')}>
                                Proponer proyecto
                            </Button>
                        ) : null}
                    </div>
                </div>

                <div className="flex flex-col gap-2 lg:flex-row lg:items-center">
                    <div role="group" aria-label="Filtros rápidos" className="-mx-4 flex gap-2 overflow-x-auto px-4 pb-1 md:mx-0 md:flex-wrap md:px-0 md:pb-0">
                        {QUICK.map((option) => {
                            const pressed = option.value === filters.quick;
                            return (
                                <button
                                    key={option.value}
                                    type="button"
                                    aria-pressed={pressed}
                                    onClick={() => !pressed && apply({ quick: option.value })}
                                    className={cx(
                                        'inline-flex h-11 shrink-0 items-center gap-2 rounded-chip border px-3.5 text-[13px] font-bold whitespace-nowrap transition-colors duration-150',
                                        pressed ? 'border-fg bg-fg text-on-fg' : 'border-line-strong bg-surface text-fg hover:bg-subtle',
                                    )}
                                >
                                    {option.label}
                                    <span className={cx('tabular rounded-full px-1.5 text-xs', pressed ? 'bg-surface text-fg' : 'bg-subtle')}>{counts[option.value]}</span>
                                </button>
                            );
                        })}
                    </div>
                    <span aria-hidden="true" className="hidden h-6 w-px bg-line-strong lg:block" />
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
                </div>

                {selected.length > 0 ? (
                    <div
                        role="region"
                        aria-label="Acciones en bloque"
                        className="sticky top-[60px] z-10 flex flex-wrap items-center gap-2 rounded-card bg-fg px-4 py-2 text-on-fg md:top-[68px]"
                    >
                        <b className="mr-2 text-sm" aria-live="polite">
                            {selected.length} {selected.length === 1 ? 'seleccionada' : 'seleccionadas'}
                        </b>
                        <label className="relative inline-flex h-11 items-center gap-1.5 rounded-control px-3 text-sm font-bold hover:bg-white/10">
                            <Icon name="user" size={16} />
                            <span>Asignar a…</span>
                            <select
                                aria-label="Asignar a"
                                value={assignee}
                                onChange={(event) => assign(event.target.value)}
                                className="absolute inset-0 cursor-pointer appearance-none opacity-0"
                            >
                                <option value="">Elige quién</option>
                                {props.team.map((member) => (
                                    <option key={member.person.id} value={member.person.id}>
                                        {member.person.name}
                                    </option>
                                ))}
                            </select>
                        </label>
                        <button
                            type="button"
                            onClick={() => actions.open('ask', selectedRows)}
                            className="inline-flex h-11 items-center gap-1.5 rounded-control px-3 text-sm font-bold hover:bg-white/10"
                        >
                            <Icon name="question" size={16} />
                            Pedir información
                        </button>
                        <button
                            type="button"
                            onClick={() => setSelected([])}
                            className="ml-auto inline-flex h-11 items-center rounded-control px-3 text-sm font-semibold opacity-80 hover:opacity-100"
                        >
                            Quitar selección
                        </button>
                    </div>
                ) : null}

                {rows.length === 0 ? (
                    emptyState
                ) : (
                    <>
                        <div className="hidden overflow-hidden rounded-card border border-line bg-surface lg:block">
                            <DataTable
                                label="Solicitudes"
                                columns={columns}
                                rows={rows}
                                rowKey={(row) => row.key}
                                rowHref={(row) => route('ai.requests.show', { request: row.key })}
                                isCurrentRow={(row) => selected.includes(row.key)}
                                density="compact"
                                minWidth={1020}
                            />
                        </div>
                        <div className="grid gap-3 md:grid-cols-2 lg:hidden">
                            {rows.map((row) => (
                                <InboxCard
                                    key={row.key}
                                    request={row}
                                    company={companyOf(row.company_id)}
                                    showCompany={allCompanies}
                                    now={now}
                                    selected={selected.includes(row.key)}
                                    onSelect={(checked) => toggle(row.key, checked)}
                                    onAction={onRowAction(row)}
                                />
                            ))}
                        </div>
                    </>
                )}

                <div className="flex flex-col gap-3 text-[13px] text-fg-muted md:flex-row md:items-center md:justify-between">
                    <div className="flex flex-wrap items-center gap-x-4 gap-y-1">
                        <span>
                            Primera respuesta: {first_response_business_days} {first_response_business_days === 1 ? 'día hábil' : 'días hábiles'} desde que entra
                        </span>
                        <Semaphore level="ok">a tiempo</Semaphore>
                        <Semaphore level="attention">vence hoy</Semaphore>
                        <Semaphore level="late">vencida</Semaphore>
                    </div>
                    {rows.length > 0 ? (
                        <span>
                            {requests.meta.total} {filtered ? 'en este filtro' : requests.meta.total === 1 ? 'abierta' : 'abiertas'} · ordenadas por primera
                            respuesta
                        </span>
                    ) : null}
                </div>
                <Pagination paginated={requests} />
            </div>

            <RequestActionDialogs actions={actions} data={props} companies={companies} canViewCosts={canViewCosts} viewerId={auth.user.id} />
        </GestorLayout>
    );
}
