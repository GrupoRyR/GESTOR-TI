import { Link, router } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import { AdminPageHeader, FilterSelect } from '@/Components/admin/AdminParts';
import { UserDetailPanel } from '@/Components/admin/UserDetailPanel';
import { Avatar } from '@/Components/ui/Avatar';
import { Button } from '@/Components/ui/Button';
import { Chip } from '@/Components/ui/Chip';
import { CompanyMark } from '@/Components/ui/CompanyMark';
import { DataTable, type DataTableColumn } from '@/Components/ui/DataTable';
import { EmptyState } from '@/Components/ui/EmptyState';
import { controlClasses } from '@/Components/ui/Field';
import { Icon } from '@/Components/ui/Icon';
import { Pagination } from '@/Components/ui/Pagination';
import { AdminLayout } from '@/Layouts/AdminLayout';
import { formatRelative } from '@/lib/format';
import { route } from '@/lib/route';
import type { AdminUserRow, AdminUsersIndexProps } from '@/types/pages/admin';
import type { Company, PageProps } from '@/types/shared';

function LocalMark() {
    return (
        <span
            role="img"
            aria-label="Cuenta local"
            title="Cuenta local de emergencia"
            className="inline-flex h-6 w-8 shrink-0 items-center justify-center rounded-[6px] bg-fg text-[10px] font-bold text-on-fg"
        >
            LOC
        </span>
    );
}

/** Usuarios: everyone with a Gestor account, their roles, and a detail panel to change them. */
export default function AdminUsersIndex(props: PageProps<AdminUsersIndexProps>) {
    const { users, filters, selected, role_options, area_options, vault_holder_name, companies } = props;
    const [query, setQuery] = useState(filters.q ?? '');

    const companyById = (id: number | null): Company | undefined => (id === null ? undefined : companies.find((item) => item.id === id));
    const roleLabel = (name: string) => role_options.find((role) => role.name === name)?.label ?? name;
    const filtered = Boolean(filters.q) || filters.role !== null;

    const visit = (next: { q: string | null; role: string | null }) => {
        const data: Record<string, string> = {};
        if (next.q) data.q = next.q;
        if (next.role) data.role = next.role;
        router.get(route('admin.users.index'), data, { preserveState: true, preserveScroll: true, replace: true });
    };

    const search = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        visit({ q: query.trim() || null, role: filters.role });
    };

    const mark = (row: AdminUserRow) => {
        if (row.company_id === null) return <LocalMark />;
        const owner = companyById(row.company_id);
        return owner ? <CompanyMark company={owner} size={24} /> : null;
    };

    const lastLogin = (row: AdminUserRow) => (row.last_login_at ? formatRelative(row.last_login_at) : 'Nunca');
    const telegram = (row: AdminUserRow) => (row.is_local ? '—' : row.telegram_linked ? 'Sí' : 'No');

    const columns: DataTableColumn<AdminUserRow>[] = [
        {
            key: 'name',
            header: 'Usuario',
            rowLink: true,
            render: (row) => (
                <span className="flex min-w-0 flex-col">
                    <b className="truncate font-semibold">
                        {row.name}
                        {!row.active ? <span className="font-normal text-fg-muted"> · inactivo</span> : null}
                    </b>
                    <span className="truncate text-xs text-fg-muted">{row.is_local ? `${row.email} · inicio de sesión local` : row.email}</span>
                </span>
            ),
        },
        { key: 'company', header: 'Empresa', hideHeader: true, width: '32px', render: mark },
        {
            key: 'roles',
            header: 'Roles',
            width: 'minmax(0, 1.3fr)',
            render: (row) => (
                <span className="flex flex-wrap gap-1.5">
                    {row.roles.map((role) => (
                        <Chip key={role} tone="gray" size="sm">
                            {roleLabel(role)}
                        </Chip>
                    ))}
                </span>
            ),
        },
        { key: 'last', header: 'Último acceso', width: '116px', render: (row) => <span className="text-[13px] text-fg-soft">{lastLogin(row)}</span> },
        { key: 'telegram', header: 'Telegram', width: '72px', render: (row) => <span className="text-[13px] text-fg-soft">{telegram(row)}</span> },
    ];

    const empty = (
        <EmptyState
            bordered={false}
            icon="filter"
            title="Ningún usuario coincide"
            description="Prueba con otro nombre o correo, o quita el filtro de rol."
            actions={
                <Button
                    variant="secondary"
                    onClick={() => {
                        setQuery('');
                        visit({ q: null, role: null });
                    }}
                >
                    Quitar filtros
                </Button>
            }
        />
    );

    return (
        <AdminLayout shared={props} title="Usuarios · Administración" tab="users">
            <div className="grid grid-cols-1 items-start gap-6 xl:grid-cols-[minmax(0,1fr)_360px]">
                <div className="flex min-w-0 flex-col gap-3.5">
                    <AdminPageHeader title="Usuarios" description="Se crean solos la primera vez que alguien entra, con el rol Usuario final" />

                    <div className="flex flex-col gap-2 sm:flex-row sm:flex-wrap">
                        <form role="search" onSubmit={search} className="relative min-w-0 flex-1 sm:max-w-[360px]">
                            <label htmlFor="buscar-usuario" className="sr-only">
                                Buscar por nombre o correo
                            </label>
                            <span className="pointer-events-none absolute inset-y-0 left-3 flex items-center text-fg-muted">
                                <Icon name="search" size={16} />
                            </span>
                            <input
                                id="buscar-usuario"
                                type="search"
                                value={query}
                                onChange={(event) => setQuery(event.target.value)}
                                placeholder="Buscar por nombre o correo"
                                className={`${controlClasses(false)} pl-9`}
                            />
                        </form>
                        <FilterSelect
                            label="Rol"
                            value={filters.role ?? ''}
                            onChange={(event) => visit({ q: filters.q, role: event.target.value || null })}
                            options={[{ value: '', label: 'todos' }, ...role_options.map((role) => ({ value: role.name, label: role.label }))]}
                        />
                    </div>

                    <section aria-label="Lista de usuarios" className="hidden overflow-hidden rounded-card border border-line bg-surface md:block">
                        <DataTable
                            label="Usuarios"
                            columns={columns}
                            rows={users.data}
                            rowKey={(row) => row.id}
                            rowHref={(row) => route('admin.users.show', { user: row.id })}
                            isCurrentRow={(row) => row.id === selected?.id}
                            empty={filtered ? empty : undefined}
                            minWidth={680}
                        />
                        {users.data.length > 0 ? <Pagination paginated={users} /> : null}
                    </section>

                    <section aria-label="Lista de usuarios" className="flex flex-col gap-2 md:hidden">
                        {users.data.length === 0 ? (
                            <div className="rounded-card border border-line bg-surface p-5">{empty}</div>
                        ) : (
                            <ul className="flex flex-col gap-2">
                                {users.data.map((row) => {
                                    const current = row.id === selected?.id;
                                    return (
                                        <li key={row.id}>
                                            <Link
                                                href={route('admin.users.show', { user: row.id })}
                                                aria-current={current ? 'true' : undefined}
                                                className={`flex min-h-16 items-center gap-3 rounded-[14px] bg-surface p-3 no-underline ${current ? 'border-2 border-fg' : 'border border-line'}`}
                                            >
                                                <Avatar name={row.name} size="md" />
                                                <span className="flex min-w-0 flex-1 flex-col gap-0.5">
                                                    <b className="truncate text-[15px] font-semibold">{row.name}</b>
                                                    <span className="truncate text-[13px] text-fg-muted">
                                                        {row.roles.map(roleLabel).join(', ')} · {lastLogin(row)}
                                                    </span>
                                                </span>
                                                {mark(row)}
                                            </Link>
                                        </li>
                                    );
                                })}
                            </ul>
                        )}
                        {users.data.length > 0 ? (
                            <div className="overflow-hidden rounded-card border border-line bg-surface">
                                <Pagination paginated={users} className="border-t-0" />
                            </div>
                        ) : null}
                    </section>
                </div>

                {selected ? (
                    <aside aria-label="Detalle del usuario" className="order-first xl:sticky xl:top-4 xl:order-none">
                        <UserDetailPanel
                            key={selected.id}
                            user={selected}
                            company={companyById(selected.company_id)}
                            roleOptions={role_options}
                            areaOptions={area_options}
                            vaultHolderName={vault_holder_name}
                        />
                    </aside>
                ) : (
                    <aside className="hidden flex-col gap-3 rounded-card border border-dashed border-line-strong p-5 text-sm text-fg-muted xl:flex">
                        <Icon name="user" size={22} />
                        Escoge a alguien de la lista para ver y cambiar sus roles.
                    </aside>
                )}
            </div>
        </AdminLayout>
    );
}
