import { useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { Button } from '@/Components/ui/Button';
import { Select } from '@/Components/ui/Select';
import { Switch } from '@/Components/ui/Switch';
import { TextField } from '@/Components/ui/TextField';
import { route } from '@/lib/route';
import type { AdminArea } from '@/types/pages/admin';
import { AdminDialog } from './AdminDialog';

interface AreaForm {
    company_id: number;
    name: string;
    departments: string;
    approver_id: string;
    active: boolean;
}

export interface AreaDialogProps {
    open: boolean;
    onClose: () => void;
    companyId: number;
    companyName: string;
    /** `null` = new area. */
    area: AdminArea | null;
    approverOptions: { id: number; name: string }[];
    approvalEnabled: boolean;
}

/** Add or edit an area: name, Microsoft departments it groups, approver, state. */
export function AreaDialog({ open, onClose, companyId, companyName, area, approverOptions, approvalEnabled }: AreaDialogProps) {
    const form = useForm<AreaForm>({
        company_id: companyId,
        name: area?.name ?? '',
        departments: area?.departments.join(', ') ?? '',
        approver_id: area?.approver ? String(area.approver.id) : '',
        active: area?.active ?? true,
    });
    const { data, setData, errors, processing } = form;

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        form.transform((values) => ({
            ...values,
            departments: values.departments
                .split(',')
                .map((item) => item.trim())
                .filter(Boolean),
            approver_id: values.approver_id === '' ? null : Number(values.approver_id),
        }));
        const options = { preserveScroll: true, onSuccess: onClose };
        if (area) form.put(route('admin.areas.update', { area: area.id }), options);
        else form.post(route('admin.areas.store'), options);
    };

    return (
        <AdminDialog
            open={open}
            onClose={onClose}
            title={area ? `Editar ${area.name}` : `Agregar área a ${companyName}`}
            description="La misma área sirve para colaboradores, solicitudes, proyectos y productos."
            onSubmit={submit}
            actions={
                <>
                    <Button variant="secondary" onClick={onClose}>
                        Cancelar
                    </Button>
                    <Button type="submit" loading={processing}>
                        {area ? 'Guardar área' : 'Agregar área'}
                    </Button>
                </>
            }
        >
            <TextField label="Nombre" value={data.name} onChange={(event) => setData('name', event.target.value)} error={errors.name} autoFocus />
            <TextField
                label="Departamentos de Microsoft"
                value={data.departments}
                onChange={(event) => setData('departments', event.target.value)}
                error={errors.departments}
                hint="Tal como vienen de Microsoft, separados por comas. Su gente queda en esta área."
                optional
            />
            <Select
                label="Aprobador de área"
                value={data.approver_id}
                onChange={(event) => setData('approver_id', event.target.value)}
                placeholder="No requiere"
                options={approverOptions.map((person) => ({ value: String(person.id), label: person.name }))}
                error={errors.approver_id}
                hint={
                    approvalEnabled
                        ? 'Da el visto bueno a las solicitudes del área antes de que lleguen a TI.'
                        : `Solo cuenta cuando el visto bueno del área esté encendido para ${companyName}.`
                }
            />
            {area ? (
                <Switch
                    checked={data.active}
                    onChange={(checked) => setData('active', checked)}
                    label={data.active ? 'Activa' : 'Inactiva'}
                    description="Un área inactiva no aparece al pedir ni al asignar."
                />
            ) : null}
        </AdminDialog>
    );
}
