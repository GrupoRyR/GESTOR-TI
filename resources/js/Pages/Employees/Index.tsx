import { Link, router } from '@inertiajs/react';
import { useCallback } from 'react';
import { AccountCell, AreaCell, employeeLine } from '@/Components/employees/EmployeeCells';
import { EmployeeFiltersBar } from '@/Components/employees/EmployeeFiltersBar';
import { Avatar } from '@/Components/ui/Avatar';
import { Button } from '@/Components/ui/Button';
import { CompanyMark } from '@/Components/ui/CompanyMark';
import { DataTable, type DataTableColumn } from '@/Components/ui/DataTable';
import { EmptyState } from '@/Components/ui/EmptyState';
import { ErrorState } from '@/Components/ui/ErrorState';
import { Icon } from '@/Components/ui/Icon';
import { Pagination } from '@/Components/ui/Pagination';
import { AppLayout } from '@/Layouts/AppLayout';
import { can } from '@/lib/can';
import { formatRelative } from '@/lib/format';
import { route } from '@/lib/route';
import type { EmployeeFilters, EmployeeRow, EmployeesIndexProps } from '@/types/pages/employees';
import type { Company, PageProps } from '@/types/shared';

const DEFAULT_FILTERS: EmployeeFilters = { q: null, company_id: null, area_id: null, status: 'active', without_area: false };

/** Query string for `employees.index`: only what differs from "no filter". */
function toQuery(filters: EmployeeFilters): Record<string, string | number | boolean> {
    const query: Record<string, string | number | boolean> = { status: filters.status };
    if (filters.q) query.q = filters.q;
    if (filters.company_id !== null) query.company_id = filters.company_id;
    if (filters.area_id !== null) query.area_id = filters.area_id;
    if (filters.without_area) query.without_area = true;
    return query;
}

function isFiltered(filters: EmployeeFilters): boolean {
    return Boolean(filters.q) || filters.area_id !== null || filters.without_area || filters.status !== 'active' || filters.company_id !== null;
}

function people(count: number): string {
    return `${count.toLocaleString('es-CO')} ${count === 1 ? 'persona' : 'personas'}`;
}

/** Colaboradores: directory synced from Microsoft, filters, table (desktop) or cards (phone). */
export default function EmployeesIndex(props: PageProps<EmployeesIndexProps>) {
    const { employees, filters, areas, counts, last_sync_at, sync_error, companies, current_company_id, auth } = props;
    const canManage = can(auth.user, 'ai.manage');
    const showAccesses = can(auth.user, 'credentials.view');
    const allCompanies = current_company_id === null;
    const company = companies.find((item) => item.id === current_company_id);
    const companyById = (id: number): Company | undefined => companies.find((item) => item.id === id);

    const applyFilters = useCallback((next: EmployeeFilters) => {
        router.get(route('employees.index'), toQuery(next), { preserveState: true, preserveScroll: true, replace: true });
    }, []);

    const syncButton = (label: string, variant: 'primary' | 'secondary' | 'dark') => (
        <Button variant={variant} icon="refresh" href={route('employees.sync')} method="post" preserveScroll>
            {label}
        </Button>
    );

    const columns: DataTableColumn<EmployeeRow>[] = [
        {
            key: 'avatar',
            header: 'Foto',
            hideHeader: true,
            width: '32px',
            render: (row) => <Avatar name={row.name} size="sm" />,
        },
        {
            key: 'name',
            header: 'Nombre',
            rowLink: true,
            render: (row) => (
                <span className="flex min-w-0 flex-col">
                    <b className="truncate font-semibold">{row.name}</b>
                    <span className="truncate text-xs text-fg-muted">{row.email}</span>
                </span>
            ),
        },
        { key: 'title', header: 'Cargo', width: '170px', render: (row) => <span className="line-clamp-2 text-fg-soft">{row.job_title ?? '—'}</span> },
        { key: 'area', header: 'Área', width: '150px', render: (row) => <AreaCell area={row.area} /> },
        ...(allCompanies
            ? [
                  {
                      key: 'company',
                      header: 'Empresa',
                      hideHeader: true,
                      width: '28px',
                      render: (row: EmployeeRow) => {
                          const owner = companyById(row.company_id);
                          return owner ? <CompanyMark company={owner} size={24} /> : null;
                      },
                  },
              ]
            : []),
        { key: 'manager', header: 'Jefe', width: '150px', render: (row) => <span className="truncate text-fg-soft">{row.manager_name ?? '—'}</span> },
        { key: 'account', header: 'En el Gestor', width: '150px', render: (row) => <AccountCell user={row.user} /> },
        ...(showAccesses
            ? [
                  {
                      key: 'accesses',
                      header: 'Accesos',
                      width: '72px',
                      render: (row: EmployeeRow) => (
                          <span className="tabular inline-flex items-center gap-1.5 text-fg-soft" title={`${row.accesses_count} accesos`}>
                              <Icon name="lock" size={14} />
                              {row.accesses_count}
                          </span>
                      ),
                  },
              ]
            : []),
    ];

    const filtered = isFiltered(filters);
    const empty = employees.data.length === 0;
    const scopeName = company ? company.short_name : 'ninguna empresa';
    const syncedLine = last_sync_at ? `sincronizado desde Microsoft ${formatRelative(last_sync_at)}` : 'todavía sin sincronizar con Microsoft';

    const emptyFiltered = (
        <EmptyState
            bordered={false}
            icon="filter"
            title="Ningún colaborador coincide"
            description="Prueba con otro nombre o quita los filtros."
            actions={
                <Button variant="secondary" onClick={() => applyFilters(DEFAULT_FILTERS)}>
                    Quitar filtros
                </Button>
            }
        />
    );

    return (
        <AppLayout shared={props} title="Colaboradores · Gestor TI" active="employees">
            <div className="flex flex-col gap-4">
                <div className="flex flex-wrap items-end justify-between gap-4">
                    <div className="flex min-w-0 flex-col gap-1">
                        <h1 className="text-[22px] leading-tight font-bold md:text-[30px]">Colaboradores</h1>
                        <p className="text-sm text-fg-muted">
                            <span className="tabular">{people(counts.total)}</span>
                            <span className="hidden md:inline"> · {syncedLine}</span>
                        </p>
                    </div>
                    {canManage ? (
                        <div className="flex flex-wrap gap-2">
                            <span className="hidden md:inline-flex">{syncButton('Sincronizar ahora', 'secondary')}</span>
                            <Button icon="plus" href={route('employees.create')}>
                                Agregar colaborador
                            </Button>
                        </div>
                    ) : null}
                </div>

                {sync_error ? (
                    <ErrorState
                        title="La sincronización con Microsoft falló"
                        description={sync_error}
                        actions={
                            canManage ? (
                                <>
                                    {syncButton('Volver a intentar', 'dark')}
                                    <Button variant="secondary" href={route('admin.integrations.index')}>
                                        Ver la integración
                                    </Button>
                                </>
                            ) : undefined
                        }
                        footnote={
                            last_sync_at
                                ? `Última sincronización correcta: ${formatRelative(last_sync_at)}`
                                : 'Todavía no hay una sincronización correcta.'
                        }
                    />
                ) : null}

                {empty && !filtered && !sync_error ? (
                    <EmptyState
                        icon="employees"
                        title={allCompanies ? 'Todavía no hay colaboradores' : `Todavía no hay colaboradores de ${scopeName}`}
                        description="Se cargan desde Microsoft una vez al día. Puedes traerlos ahora; toma menos de un minuto."
                        actions={
                            canManage ? (
                                <>
                                    {syncButton('Sincronizar ahora', 'primary')}
                                    <Button variant="secondary" href={route('employees.create')}>
                                        Agregar a mano
                                    </Button>
                                </>
                            ) : undefined
                        }
                    />
                ) : (
                    <>
                        <div className="flex flex-col gap-2 md:flex-row md:items-center md:justify-between">
                            <div className="min-w-0 flex-1">
                                <EmployeeFiltersBar
                                    filters={filters}
                                    areas={areas}
                                    withoutAreaCount={counts.without_area}
                                    companies={allCompanies ? companies : null}
                                    onChange={applyFilters}
                                />
                            </div>
                            <p className="hidden shrink-0 text-[13px] text-fg-muted xl:block">Los invitados de Microsoft no se muestran</p>
                        </div>

                        {/* Tablet and desktop */}
                        <section aria-label="Lista de colaboradores" className="hidden overflow-hidden rounded-card border border-line bg-surface md:block">
                            <DataTable
                                label="Colaboradores"
                                columns={columns}
                                rows={employees.data}
                                rowKey={(row) => row.id}
                                rowHref={(row) => route('employees.show', { employee: row.id })}
                                empty={emptyFiltered}
                                minWidth={allCompanies ? 1000 : 960}
                            />
                            {!empty ? <Pagination paginated={employees} /> : null}
                        </section>

                        {/* Phone */}
                        <section aria-label="Lista de colaboradores" className="flex flex-col gap-2 md:hidden">
                            {empty ? (
                                <div className="rounded-card border border-line bg-surface p-5">{emptyFiltered}</div>
                            ) : (
                                <ul className="flex flex-col gap-2">
                                    {employees.data.map((employee) => {
                                        const owner = allCompanies ? companyById(employee.company_id) : undefined;
                                        return (
                                            <li key={employee.id}>
                                                <Link
                                                    href={route('employees.show', { employee: employee.id })}
                                                    className="flex min-h-16 items-center gap-3 rounded-[14px] border border-line bg-surface p-3 no-underline"
                                                >
                                                    <Avatar name={employee.name} size="md" />
                                                    <span className="flex min-w-0 flex-1 flex-col gap-0.5">
                                                        <b className="truncate text-[15px] font-semibold">{employee.name}</b>
                                                        <span className="truncate text-[13px] text-fg-muted">{employeeLine(employee)}</span>
                                                    </span>
                                                    {owner ? <CompanyMark company={owner} size={24} /> : null}
                                                </Link>
                                            </li>
                                        );
                                    })}
                                </ul>
                            )}
                            {!empty ? (
                                <div className="overflow-hidden rounded-card border border-line bg-surface">
                                    <Pagination paginated={employees} className="border-t-0" />
                                </div>
                            ) : null}
                            {canManage ? <div className="pt-2">{syncButton('Sincronizar ahora', 'secondary')}</div> : null}
                        </section>
                    </>
                )}
            </div>
        </AppLayout>
    );
}
