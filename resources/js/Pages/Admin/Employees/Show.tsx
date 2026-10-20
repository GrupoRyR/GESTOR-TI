import { Link, router } from '@inertiajs/react';
import { useState, type ReactNode } from 'react';
import { AccessRow } from '@/Components/employees/AccessRow';
import { Button } from '@/Components/ui/Button';
import { Chip } from '@/Components/ui/Chip';
import { CompanyMark } from '@/Components/ui/CompanyMark';
import { ConfirmDialog } from '@/Components/ui/Dialog';
import { Icon } from '@/Components/ui/Icon';
import { Select } from '@/Components/ui/Select';
import { cx, initials } from '@/Components/ui/utils';
import { AppLayout } from '@/Layouts/AppLayout';
import { DEFAULT_REVEAL_SECONDS } from '@/lib/accessReveal';
import { formatRelative } from '@/lib/format';
import { route } from '@/lib/route';
import type { EmployeeDetail, EmployeeRef, EmployeeShowProps } from '@/types/pages/admin-employees';
import type { PageProps } from '@/types/shared';

const CARD = 'rounded-[14px] border border-line bg-surface md:rounded-card';
const H2 = 'font-display text-lg font-bold';

/** Select value that sends `area_id: null` (back to the Microsoft department mapping). */
const AUTO = 'auto';

function PhoneHeader() {
    return (
        <header className="sticky top-0 z-20 flex h-[60px] items-center gap-2 border-b border-line bg-surface px-4">
            <Link
                href={route('employees.index')}
                aria-label="Volver a Colaboradores"
                className="-ml-3 flex size-11 shrink-0 items-center justify-center rounded-control text-fg hover:bg-subtle"
            >
                <Icon name="chevron-left" size={20} />
            </Link>
            <b className="text-base">Colaboradores</b>
        </header>
    );
}

function PersonLink({ person, detail }: { person: EmployeeRef; detail?: string | null }) {
    return (
        <Link
            href={route('employees.show', { employee: person.id })}
            className="flex min-h-14 items-center gap-3 px-4 py-2 no-underline hover:bg-subtle/60 md:px-5"
        >
            <span aria-hidden="true" className="flex size-9 shrink-0 items-center justify-center rounded-full bg-subtle text-[13px] font-bold text-fg-soft">
                {initials(person.name)}
            </span>
            <span className="flex min-w-0 flex-1 flex-col">
                <b className="truncate text-[15px] font-semibold md:text-sm">{person.name}</b>
                {detail ? <span className="truncate text-[13px] text-fg-muted md:text-xs">{detail}</span> : null}
            </span>
            <Icon name="chevron-right" size={16} className="shrink-0 text-fg-muted" />
        </Link>
    );
}

function Label({ children, className }: { children: ReactNode; className?: string }) {
    return <div className={cx('px-4 pt-2 text-xs font-bold text-fg-muted md:px-5', className)}>{children}</div>;
}

function Organization({ employee }: { employee: EmployeeDetail }) {
    const reports = employee.reports;
    return (
        <section aria-labelledby="org-title" className={cx(CARD, 'overflow-hidden')}>
            <h2 id="org-title" className={cx(H2, 'border-b border-line px-4 py-3.5 md:px-5 md:py-4')}>
                Organización
            </h2>
            <Label>Jefe</Label>
            {employee.manager ? (
                <PersonLink person={employee.manager} detail={employee.manager.job_title} />
            ) : (
                <p className="px-4 pt-1 pb-3 text-sm text-fg-soft md:px-5">Sin jefe en Microsoft.</p>
            )}
            {reports !== undefined ? (
                <div className="border-t border-line pb-1">
                    <Label className="pt-3">{reports.length > 0 ? `Le reportan · ${reports.length}` : 'Le reportan'}</Label>
                    {reports.length > 0 ? (
                        <ul>
                            {reports.map((report) => (
                                <li key={report.id}>
                                    <PersonLink person={report} detail={[report.job_title, report.area_name].filter(Boolean).join(' · ')} />
                                </li>
                            ))}
                        </ul>
                    ) : (
                        <p className="px-4 pt-0.5 pb-3 text-sm text-fg-soft md:px-5">Nadie le reporta.</p>
                    )}
                </div>
            ) : null}
        </section>
    );
}

function AreaAssignment({ employee, areas, canManage }: { employee: EmployeeDetail; areas: EmployeeShowProps['areas']; canManage: boolean }) {
    const current = employee.area ? String(employee.area.id) : '';
    const [choice, setChoice] = useState(current);
    const [error, setError] = useState<string | null>(null);
    const [saving, setSaving] = useState(false);
    const missing = employee.area === null;
    const department = employee.department;

    const explanation = missing
        ? department
            ? `En Microsoft su departamento es «${department}» y no corresponde a ningún área del Gestor. Elige una; queda fija aunque se sincronice.`
            : 'En Microsoft no tiene departamento. Elige un área; queda fija aunque se sincronice.'
        : employee.area_locked
          ? 'Se eligió a mano en el Gestor: la sincronización no la toca. Si eliges «Según Microsoft», vuelve a salir del departamento.'
          : department
            ? `Sale del departamento «${department}» de Microsoft. Si la cambias aquí, queda fija y la sincronización no la toca.`
            : 'Si la cambias aquí, queda fija y la sincronización no la toca.';

    if (!canManage) {
        return (
            <section aria-labelledby="area-title" className={cx(CARD, 'flex flex-col gap-2 p-4 md:p-5')}>
                <h2 id="area-title" className={H2}>
                    Área en el Gestor
                </h2>
                <p className="text-sm text-fg-soft">
                    {employee.area ? employee.area.name : 'Sin área'}
                    {employee.area && employee.area_locked ? ' · elegida a mano' : ''}
                </p>
                <p className="text-[13px] text-fg-muted">Solo quien administra colaboradores puede cambiarla.</p>
            </section>
        );
    }

    const options = [
        ...(employee.area_locked ? [{ value: AUTO, label: department ? `Según Microsoft («${department}»)` : 'Según Microsoft' }] : []),
        ...areas.map((area) => ({ value: String(area.id), label: area.name })),
    ];
    const changed = choice !== '' && choice !== current;

    const save = () => {
        if (!changed) return;
        setError(null);
        router.put(
            route('employees.update', { employee: employee.id }),
            { area_id: choice === AUTO ? null : Number(choice) },
            {
                preserveScroll: true,
                onStart: () => setSaving(true),
                onFinish: () => setSaving(false),
                onError: (errors) => setError(errors.area_id ?? 'No se pudo guardar el área. Intenta de nuevo.'),
            },
        );
    };

    return (
        <section
            id="area"
            aria-labelledby="area-title"
            className={cx(CARD, 'flex flex-col gap-3 p-4 md:p-5', missing && 'border-status-maintenance/40')}
        >
            <h2 id="area-title" className={H2}>
                {missing ? 'Asignar área' : 'Área en el Gestor'}
            </h2>
            <p className="text-sm leading-[1.45] text-fg-soft">{explanation}</p>
            <form
                className="flex flex-col gap-2 sm:flex-row sm:items-end"
                onSubmit={(event) => {
                    event.preventDefault();
                    save();
                }}
            >
                <Select
                    label="Área"
                    options={options}
                    placeholder={missing ? 'Elige un área' : undefined}
                    value={choice}
                    onChange={(event) => setChoice(event.target.value)}
                    error={error ?? undefined}
                    containerClassName="sm:w-[280px]"
                />
                <Button type="submit" variant={missing ? 'primary' : 'secondary'} disabled={!changed} loading={saving} className="max-sm:w-full">
                    Guardar área
                </Button>
            </form>
        </section>
    );
}

function Accesses({ employeeId, accesses, canReveal }: { employeeId: number; accesses: EmployeeShowProps['accesses']; canReveal: boolean }) {
    return (
        <section aria-labelledby="accesses-title" className={cx(CARD, 'overflow-hidden')}>
            <div className="flex flex-wrap items-center justify-between gap-x-3 gap-y-1 px-4 py-3.5 md:px-5 md:py-4">
                <h2 id="accesses-title" className={H2}>
                    Accesos · {accesses.length}
                </h2>
                {canReveal ? (
                    <span className="inline-flex items-center gap-1.5 text-[13px] text-fg-muted">
                        <Icon name="lock" size={14} />
                        Visible porque puedes ver credenciales
                    </span>
                ) : null}
            </div>
            {accesses.length > 0 ? (
                <ul>
                    {accesses.map((access) => (
                        <AccessRow key={access.id} employeeId={employeeId} access={access} canReveal={canReveal} />
                    ))}
                </ul>
            ) : (
                <p className="border-t border-line px-4 py-4 text-sm text-fg-soft md:px-5">Todavía no tiene accesos registrados.</p>
            )}
            <p className="border-t border-line px-4 py-3 text-[13px] leading-[1.45] text-fg-muted md:px-5">
                {canReveal
                    ? `La contraseña se muestra ${DEFAULT_REVEAL_SECONDS} segundos y se vuelve a ocultar. Cada vez que alguien la ve queda en la auditoría.`
                    : 'Usuarios y contraseñas solo los ven quienes tienen permiso de ver credenciales.'}
            </p>
        </section>
    );
}

function GestorAccount({ employee }: { employee: EmployeeDetail }) {
    const roles = employee.user_roles ?? [];
    return (
        <section aria-labelledby="gestor-title" className={cx(CARD, 'flex flex-col gap-2.5 p-4 md:gap-3 md:p-5')}>
            <h2 id="gestor-title" className={H2}>
                En el Gestor
            </h2>
            {employee.user_id === null ? (
                <>
                    <span className="text-sm text-fg-soft">No ha entrado</span>
                    <p className="text-[13px] leading-[1.45] text-fg-muted">La cuenta se crea sola la primera vez que entra con Microsoft.</p>
                </>
            ) : (
                <>
                    {roles.length > 0 ? (
                        <div className="flex flex-wrap gap-1.5">
                            {roles.map((role) => (
                                <Chip key={role} size="sm">
                                    {role}
                                </Chip>
                            ))}
                        </div>
                    ) : (
                        <span className="text-sm text-fg-soft">Tiene cuenta en el Gestor</span>
                    )}
                    <p className="text-[13px] leading-[1.45] text-fg-muted">Entra con su cuenta de Microsoft. Los roles se cambian en Administración.</p>
                </>
            )}
        </section>
    );
}

function MicrosoftInfo({ employee }: { employee: EmployeeDetail }) {
    const synced = employee.source === 'graph';
    return (
        <section aria-labelledby="ms-title" className={cx(CARD, 'flex flex-col gap-2.5 p-4 md:gap-3 md:p-5')}>
            <h2 id="ms-title" className={H2}>
                Microsoft
            </h2>
            <dl className="grid grid-cols-[116px_minmax(0,1fr)] gap-y-2 text-sm md:grid-cols-[120px_minmax(0,1fr)] md:gap-y-2.5">
                <dt className="text-fg-muted">Origen</dt>
                <dd>{synced ? 'Sincronizado desde Microsoft' : 'Agregado a mano en el Gestor'}</dd>
                <dt className="text-fg-muted">Departamento</dt>
                <dd className="[overflow-wrap:anywhere]">{employee.department ?? '—'}</dd>
                {synced ? (
                    <>
                        <dt className="text-fg-muted">Última vez</dt>
                        <dd>{employee.last_synced_at ? formatRelative(employee.last_synced_at) : 'Todavía no'}</dd>
                    </>
                ) : null}
            </dl>
            <p className="text-[13px] leading-[1.45] text-fg-muted">
                {synced
                    ? 'Nombre, correo, cargo y jefe vienen de Microsoft. Se cambian allá.'
                    : 'No viene de Microsoft: la sincronización no cambia sus datos.'}
            </p>
        </section>
    );
}

function StatusSection({ employee, canManage }: { employee: EmployeeDetail; canManage: boolean }) {
    const [confirming, setConfirming] = useState(false);
    const [saving, setSaving] = useState(false);
    const active = employee.status === 'active';

    const setStatus = (status: 'active' | 'inactive') => {
        router.put(
            route('employees.update', { employee: employee.id }),
            { status },
            {
                preserveScroll: true,
                onStart: () => setSaving(true),
                onFinish: () => {
                    setSaving(false);
                    setConfirming(false);
                },
            },
        );
    };

    return (
        <section
            aria-labelledby="status-title"
            className={cx('flex flex-col gap-3 md:rounded-card md:border md:border-line md:bg-surface md:p-5', !canManage && 'max-md:hidden')}
        >
            <h2 id="status-title" className={cx(H2, 'max-md:sr-only')}>
                Estado
            </h2>
            <p className="text-sm leading-[1.45] text-fg-soft max-md:sr-only">
                {active
                    ? canManage
                        ? 'Activo. Si ya no trabaja en la empresa, márcalo como inactivo: sale de la lista de activos y no se borra nada.'
                        : 'Activo.'
                    : 'Inactivo. No aparece en la lista de activos; sus datos siguen guardados.'}
            </p>
            {canManage ? (
                active ? (
                    <Button variant="secondary" onClick={() => setConfirming(true)} className="max-md:w-full md:self-start">
                        Marcar como inactivo
                    </Button>
                ) : (
                    <Button variant="secondary" onClick={() => setStatus('active')} loading={saving} className="max-md:w-full md:self-start">
                        Marcar como activo
                    </Button>
                )
            ) : null}
            <ConfirmDialog
                open={confirming}
                onClose={() => setConfirming(false)}
                onConfirm={() => setStatus('inactive')}
                title={`¿Marcar a ${employee.name} como inactivo?`}
                description="Sale de la lista de activos. No se borra nada y puedes volver a activarlo cuando quieras."
                confirmLabel="Marcar como inactivo"
                tone="primary"
                processing={saving}
            />
        </section>
    );
}

/** Ficha del colaborador (A-EmployeeDetail, A-EmployeeDetailPhone). */
export default function EmployeeShow(props: PageProps<EmployeeShowProps>) {
    const { employee, accesses, can_reveal, can_manage, areas, companies } = props;
    const company = employee.company_id !== undefined ? companies.find((item) => item.id === employee.company_id) : undefined;
    const active = employee.status === 'active';

    const areaSection = <AreaAssignment key={`${employee.id}-${employee.area?.id ?? 'none'}-${employee.area_locked}`} employee={employee} areas={areas} canManage={can_manage} />;

    return (
        <AppLayout shared={props} title={`${employee.name} · Colaboradores · Gestor TI`} active="employees" phoneHeader={<PhoneHeader />}>
            <div className="flex flex-col gap-3 md:gap-4">
                <nav aria-label="Ruta" className="flex items-center gap-1.5 text-sm text-fg-muted max-md:hidden">
                    <Link href={route('employees.index')} className="inline-flex min-h-11 items-center gap-1.5 font-semibold text-fg no-underline hover:text-fg-soft">
                        <Icon name="chevron-left" size={16} />
                        Colaboradores
                    </Link>
                    <span aria-hidden="true">/</span>
                    <span aria-current="page" className="truncate">
                        {employee.name}
                    </span>
                </nav>

                <section className={cx(CARD, 'flex flex-col gap-3 p-4 md:flex-row md:items-center md:gap-5 md:p-6')}>
                    <div className="flex min-w-0 flex-1 items-center gap-3 md:gap-5">
                        <span
                            aria-hidden="true"
                            className="flex size-14 shrink-0 items-center justify-center rounded-full bg-subtle text-lg font-bold text-fg-soft md:size-[72px] md:text-2xl"
                        >
                            {initials(employee.name)}
                        </span>
                        <div className="flex min-w-0 flex-1 flex-col gap-0.5 md:gap-2">
                            <h1 className="font-display text-[22px] leading-tight font-bold tracking-[-0.01em] [overflow-wrap:anywhere] md:text-[30px] md:tracking-[-0.02em]">
                                {employee.name}
                            </h1>
                            <span className="text-sm text-fg-soft md:text-[15px]">{employee.job_title ?? 'Sin cargo en Microsoft'}</span>
                            <div className="hidden flex-wrap items-center gap-2 md:flex">
                                <HeaderChips employee={employee} company={company} canManage={can_manage} active={active} />
                            </div>
                        </div>
                    </div>
                    <div className="flex flex-wrap items-center gap-1.5 md:hidden">
                        <HeaderChips employee={employee} company={company} canManage={can_manage} active={active} />
                    </div>
                    {employee.email ? (
                        <a
                            href={`mailto:${employee.email}`}
                            className="inline-flex min-h-11 min-w-0 items-center gap-2 text-sm font-semibold text-fg no-underline md:h-11 md:shrink-0 md:rounded-control md:border md:border-line-strong md:bg-surface md:px-3.5 md:hover:bg-subtle"
                        >
                            <Icon name="message" size={16} className="shrink-0" />
                            <span className="truncate">{employee.email}</span>
                        </a>
                    ) : null}
                </section>

                <div className="grid grid-cols-1 items-start gap-3 md:gap-4 lg:grid-cols-[minmax(0,1fr)_340px] xl:grid-cols-[minmax(0,1fr)_380px]">
                    <div className="flex min-w-0 flex-col gap-3 md:gap-4">
                        {employee.area === null ? areaSection : null}
                        <Organization employee={employee} />
                        {employee.area !== null ? areaSection : null}
                        <Accesses employeeId={employee.id} accesses={accesses} canReveal={can_reveal} />
                    </div>
                    <aside className="flex min-w-0 flex-col gap-3 md:gap-4" aria-label="Cuenta, origen y estado">
                        <GestorAccount employee={employee} />
                        <MicrosoftInfo employee={employee} />
                        <StatusSection employee={employee} canManage={can_manage} />
                    </aside>
                </div>
            </div>
        </AppLayout>
    );
}

function HeaderChips({
    employee,
    company,
    canManage,
    active,
}: {
    employee: EmployeeDetail;
    company: PageProps['companies'][number] | undefined;
    canManage: boolean;
    active: boolean;
}) {
    return (
        <>
            {company ? (
                <span className="inline-flex h-7 items-center gap-1.5 rounded-[10px] border border-line pr-2.5 pl-1 text-[13px] font-bold">
                    <CompanyMark company={company} size={20} decorative />
                    {company.short_name}
                </span>
            ) : null}
            {employee.area ? (
                <Chip>Área: {employee.area.name}</Chip>
            ) : canManage ? (
                <a href="#area" className="no-underline">
                    <Chip tone="amber" icon="alert-triangle">
                        Sin área · asignar
                    </Chip>
                </a>
            ) : (
                <Chip tone="amber" icon="alert-triangle">
                    Sin área
                </Chip>
            )}
            {active ? (
                <Chip tone="green" icon="check">
                    Activo
                </Chip>
            ) : (
                <Chip tone="slate">Inactivo</Chip>
            )}
        </>
    );
}
