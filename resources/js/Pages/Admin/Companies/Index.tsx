import { Link } from '@inertiajs/react';
import { CompanyEditPanel } from '@/Components/admin/CompanyEditPanel';
import { Button } from '@/Components/ui/Button';
import { Chip } from '@/Components/ui/Chip';
import { CompanyMark } from '@/Components/ui/CompanyMark';
import { EmptyState } from '@/Components/ui/EmptyState';
import { cx } from '@/Components/ui/utils';
import { AdminLayout } from '@/Layouts/AdminLayout';
import { route } from '@/lib/route';
import type { AdminCompaniesIndexProps, AdminCompany } from '@/types/pages/admin';
import type { PageProps } from '@/types/shared';

function people(count: number): string {
    return `${count.toLocaleString('es-CO')} ${count === 1 ? 'colaborador' : 'colaboradores'}`;
}

function CompanyCard({ company, current }: { company: AdminCompany; current: boolean }) {
    return (
        <Link
            href={route('admin.companies.edit', { company: company.id })}
            aria-current={current ? 'true' : undefined}
            className={cx(
                'flex flex-col gap-3 rounded-card bg-surface p-4 text-fg no-underline transition-colors duration-150',
                current ? 'border-2 border-fg' : 'border border-line hover:border-line-strong',
            )}
        >
            <span className="flex items-center gap-3">
                <CompanyMark company={company} size={40} variant="solid" decorative />
                <span className="flex min-w-0 flex-1 flex-col">
                    <b className="truncate text-[15px] font-bold">{company.name}</b>
                    <span className="truncate text-[13px] text-fg-muted">
                        {company.domains.map((domain) => `@${domain}`).join(', ')} · {people(company.employees_count)}
                    </span>
                </span>
                {company.active ? (
                    <Chip tone="green" dot size="sm">
                        Activa
                    </Chip>
                ) : (
                    <Chip tone="slate" dot size="sm">
                        Inactiva
                    </Chip>
                )}
            </span>
            <span className="truncate text-[13px] text-fg-soft">Correos salen de {company.sender_email}</span>
        </Link>
    );
}

/** Empresas: list of companies and the edit panel of the selected one. */
export default function AdminCompaniesIndex(props: PageProps<AdminCompaniesIndexProps>) {
    const { admin_companies, selected, can_change_tenant } = props;

    return (
        <AdminLayout shared={props} title="Empresas · Administración" tab="companies">
            <div className="grid grid-cols-1 items-start gap-6 xl:grid-cols-[minmax(320px,400px)_minmax(0,1fr)]">
                <section aria-labelledby="empresas-titulo" className="flex flex-col gap-3">
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <h1 id="empresas-titulo" className="font-display text-[22px] leading-tight font-bold tracking-[-0.02em] md:text-[26px]">
                            Empresas
                        </h1>
                        <Button icon="plus" href={route('admin.companies.create')}>
                            Agregar empresa
                        </Button>
                    </div>
                    <ul className="grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-1">
                        {admin_companies.map((company) => (
                            <li key={company.id}>
                                <CompanyCard company={company} current={company.id === selected?.id} />
                            </li>
                        ))}
                    </ul>
                </section>

                {selected ? (
                    <CompanyEditPanel key={selected.id} company={selected} canChangeTenant={can_change_tenant} />
                ) : (
                    <EmptyState
                        icon="building"
                        title="Escoge una empresa"
                        description="Toca una empresa de la lista para ver y cambiar sus datos, su cuenta de Microsoft, el correo emisor y su marca."
                        className="xl:mt-[56px]"
                    />
                )}
            </div>
        </AdminLayout>
    );
}
