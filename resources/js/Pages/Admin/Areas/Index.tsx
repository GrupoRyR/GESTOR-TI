import { router, useForm } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import { AdminPageHeader } from '@/Components/admin/AdminParts';
import { AreaDialog } from '@/Components/admin/AreaDialog';
import { Button } from '@/Components/ui/Button';
import { Chip } from '@/Components/ui/Chip';
import { DataTable, type DataTableColumn } from '@/Components/ui/DataTable';
import { EmptyState } from '@/Components/ui/EmptyState';
import { Icon } from '@/Components/ui/Icon';
import { IconButton } from '@/Components/ui/IconButton';
import { SegmentedControl } from '@/Components/ui/SegmentedControl';
import { Select } from '@/Components/ui/Select';
import { Switch } from '@/Components/ui/Switch';
import { AdminLayout } from '@/Layouts/AdminLayout';
import { route } from '@/lib/route';
import type { AdminArea, AdminAreasIndexProps } from '@/types/pages/admin';
import type { PageProps } from '@/types/shared';

function people(count: number): string {
    return `${count.toLocaleString('es-CO')} ${count === 1 ? 'persona' : 'personas'}`;
}

interface MappingForm {
    company_id: number;
    mappings: { department: string; area_id: number | null }[];
}

/** Áreas of one company: table, add/edit dialog, unmapped departments and the area-approval switch. */
export default function AdminAreasIndex(props: PageProps<AdminAreasIndexProps>) {
    const { company_id, areas, unmapped_departments, area_approval_enabled, approver_options, companies } = props;
    const company = companies.find((item) => item.id === company_id);
    const companyName = company?.short_name ?? 'la empresa';

    const [dialog, setDialog] = useState<{ open: boolean; area: AdminArea | null; nonce: number }>({ open: false, area: null, nonce: 0 });
    const openDialog = (area: AdminArea | null) => setDialog((previous) => ({ open: true, area, nonce: previous.nonce + 1 }));

    const [approval, setApproval] = useState(area_approval_enabled);
    const toggleApproval = (enabled: boolean) => {
        setApproval(enabled);
        router.put(
            route('admin.areas.approval.update'),
            { company_id, enabled },
            { preserveScroll: true, onError: () => setApproval(!enabled) },
        );
    };

    const mappingForm = useForm<MappingForm>({
        company_id,
        mappings: unmapped_departments.map((item) => ({ department: item.name, area_id: item.area_id })),
    });
    const unmappedPeople = unmapped_departments.reduce((sum, item) => sum + item.employees_count, 0);
    const pending = mappingForm.data.mappings.filter((item) => item.area_id === null).length;

    const saveMappings = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        mappingForm.put(route('admin.areas.mappings.update'), { preserveScroll: true });
    };

    const setMapping = (index: number, value: string) => {
        mappingForm.setData(
            'mappings',
            mappingForm.data.mappings.map((item, position) => (position === index ? { ...item, area_id: value === '' ? null : Number(value) } : item)),
        );
    };

    const activeAreas = areas.filter((area) => area.active);
    const areaOptions = activeAreas.map((area) => ({ value: String(area.id), label: area.name }));

    const columns: DataTableColumn<AdminArea>[] = [
        {
            key: 'name',
            header: 'Área',
            render: (area) => (
                <span className="flex min-w-0 items-center gap-2">
                    <b className="truncate font-semibold">{area.name}</b>
                    {!area.active ? (
                        <Chip tone="slate" size="sm">
                            Inactiva
                        </Chip>
                    ) : null}
                </span>
            ),
        },
        { key: 'people', header: 'Personas', width: '96px', render: (area) => <span className="tabular">{area.employees_count}</span> },
        {
            key: 'departments',
            header: 'Departamentos de Microsoft',
            width: 'minmax(0, 1.4fr)',
            render: (area) => (
                <span className="line-clamp-2 text-[13px] text-fg-soft">{area.departments.length > 0 ? area.departments.join(', ') : '—'}</span>
            ),
        },
        {
            key: 'approver',
            header: 'Aprobador de área',
            width: '180px',
            render: (area) =>
                area.approver ? (
                    <span className="truncate text-[13px] font-semibold">{area.approver.name}</span>
                ) : (
                    <span className="text-[13px] text-fg-muted">No requiere</span>
                ),
        },
        {
            key: 'edit',
            header: 'Editar',
            hideHeader: true,
            width: '44px',
            render: (area) => <IconButton icon="edit" label={`Editar ${area.name}`} iconSize={16} onClick={() => openDialog(area)} />,
        },
    ];

    return (
        <AdminLayout shared={props} title="Áreas · Administración" tab="areas">
            <div className="flex flex-col gap-4">
                {companies.length > 1 ? (
                    <SegmentedControl
                        label="Empresa"
                        value={String(company_id)}
                        options={companies.map((item) => ({ value: String(item.id), label: item.short_name }))}
                        onChange={(value) => router.get(route('admin.areas.index'), { company_id: Number(value) }, { preserveScroll: true })}
                        className="self-start"
                    />
                ) : null}

                <div className="grid grid-cols-1 items-start gap-6 xl:grid-cols-[minmax(0,1fr)_340px]">
                    <div className="flex min-w-0 flex-col gap-3.5">
                        <AdminPageHeader
                            title={`Áreas de ${companyName}`}
                            description="Las mismas para colaboradores, solicitudes, proyectos y productos"
                            actions={
                                <Button icon="plus" onClick={() => openDialog(null)}>
                                    Agregar área
                                </Button>
                            }
                        />

                        {areas.length === 0 ? (
                            <EmptyState
                                icon="building"
                                title={`${companyName} todavía no tiene áreas`}
                                description="Crea las áreas y asígnales los departamentos que vienen de Microsoft. Su gente queda en el área que le corresponde."
                                actions={
                                    <Button icon="plus" onClick={() => openDialog(null)}>
                                        Agregar la primera área
                                    </Button>
                                }
                            />
                        ) : (
                            <>
                                <section aria-label={`Áreas de ${companyName}`} className="hidden overflow-hidden rounded-card border border-line bg-surface md:block">
                                    <DataTable label={`Áreas de ${companyName}`} columns={columns} rows={areas} rowKey={(area) => area.id} minWidth={720} />
                                </section>
                                <ul aria-label={`Áreas de ${companyName}`} className="flex flex-col gap-2 md:hidden">
                                    {areas.map((area) => (
                                        <li key={area.id} className="flex items-start gap-3 rounded-[14px] border border-line bg-surface p-3.5">
                                            <span className="flex min-w-0 flex-1 flex-col gap-1">
                                                <span className="flex items-center gap-2">
                                                    <b className="truncate text-[15px] font-semibold">{area.name}</b>
                                                    {!area.active ? (
                                                        <Chip tone="slate" size="sm">
                                                            Inactiva
                                                        </Chip>
                                                    ) : null}
                                                </span>
                                                <span className="text-[13px] text-fg-muted">
                                                    {people(area.employees_count)} · {area.approver ? `Aprueba ${area.approver.name}` : 'Sin aprobador'}
                                                </span>
                                                {area.departments.length > 0 ? (
                                                    <span className="text-[13px] text-fg-soft">{area.departments.join(', ')}</span>
                                                ) : null}
                                            </span>
                                            <IconButton icon="edit" label={`Editar ${area.name}`} iconSize={16} onClick={() => openDialog(area)} />
                                        </li>
                                    ))}
                                </ul>
                            </>
                        )}
                    </div>

                    <aside className="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-1">
                        {unmapped_departments.length > 0 ? (
                            <form
                                onSubmit={saveMappings}
                                aria-labelledby="sin-area"
                                className="flex flex-col gap-3 rounded-card border border-status-maintenance-dot/50 bg-surface p-[18px]"
                            >
                                <h2 id="sin-area" className="flex items-center gap-2 font-display text-[17px] font-bold">
                                    <span className="text-status-maintenance-dot">
                                        <Icon name="alert-triangle" size={16} />
                                    </span>
                                    Departamentos sin área
                                </h2>
                                <p className="text-[13px] leading-[1.45] text-fg-soft">
                                    Vienen de Microsoft y aún no se sabe a qué área van.{' '}
                                    {unmappedPeople === 1 ? 'Esa persona queda' : `Sus ${people(unmappedPeople)} quedan`} «Sin área».
                                </p>
                                {unmapped_departments.map((department, index) => (
                                    <Select
                                        key={department.name}
                                        label={`«${department.name}» · ${people(department.employees_count)}`}
                                        value={mappingForm.data.mappings[index]?.area_id?.toString() ?? ''}
                                        onChange={(event) => setMapping(index, event.target.value)}
                                        placeholder="Escoge un área"
                                        options={areaOptions}
                                        error={(mappingForm.errors as Record<string, string>)[`mappings.${index}.area_id`]}
                                    />
                                ))}
                                <Button type="submit" variant="dark" loading={mappingForm.processing} disabled={pending === unmapped_departments.length}>
                                    Guardar y reasignar
                                </Button>
                                {pending > 0 && pending < unmapped_departments.length ? (
                                    <p className="text-[13px] text-fg-muted">Los que no tengan área se quedan como están.</p>
                                ) : null}
                            </form>
                        ) : (
                            <section className="flex items-start gap-3 rounded-card border border-line bg-surface p-[18px] text-[13px] leading-[1.45] text-fg-soft">
                                <span className="mt-0.5 text-status-active">
                                    <Icon name="check-circle" size={16} />
                                </span>
                                Todos los departamentos de Microsoft de {companyName} ya tienen área.
                            </section>
                        )}

                        <section aria-labelledby="visto-bueno" className="flex flex-col gap-2.5 rounded-card border border-line bg-surface p-[18px]">
                            <h2 id="visto-bueno" className="font-display text-[17px] font-bold">
                                Visto bueno del área
                            </h2>
                            <p className="text-[13px] leading-[1.45] text-fg-soft">
                                Si está activo, el jefe aprueba las solicitudes de su área antes de que lleguen a TI.
                            </p>
                            <Switch
                                checked={approval}
                                onChange={toggleApproval}
                                label={approval ? `Encendido para toda ${companyName}` : `Apagado para toda ${companyName}`}
                                className="flex-row-reverse justify-end"
                            />
                        </section>
                    </aside>
                </div>
            </div>

            <AreaDialog
                key={dialog.nonce}
                open={dialog.open}
                onClose={() => setDialog((previous) => ({ ...previous, open: false }))}
                companyId={company_id}
                companyName={companyName}
                area={dialog.area}
                approverOptions={approver_options}
                approvalEnabled={approval}
            />
        </AdminLayout>
    );
}
