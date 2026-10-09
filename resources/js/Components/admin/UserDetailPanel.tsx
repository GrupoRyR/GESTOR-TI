import { router, useForm } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import { Avatar } from '@/Components/ui/Avatar';
import { Button } from '@/Components/ui/Button';
import { Checkbox } from '@/Components/ui/Checkbox';
import { Chip } from '@/Components/ui/Chip';
import { Select } from '@/Components/ui/Select';
import { route } from '@/lib/route';
import type { AdminUserDetail, RoleOption } from '@/types/pages/admin';
import type { Company } from '@/types/shared';
import { ConfirmDialog } from './AdminDialog';
import { VaultNote } from './AdminParts';

interface RolesForm {
    roles: string[];
    area_id: string;
}

export interface UserDetailPanelProps {
    user: AdminUserDetail;
    company: Company | undefined;
    roleOptions: RoleOption[];
    areaOptions: { id: number; name: string; company_id: number }[];
    vaultHolderName: string;
}

/** Side panel of one user: roles (area for Aprobador de área), vault note, save and deactivate. */
export function UserDetailPanel({ user, company, roleOptions, areaOptions, vaultHolderName }: UserDetailPanelProps) {
    const form = useForm<RolesForm>({
        roles: user.roles,
        area_id: user.approver_area_id === null ? '' : String(user.approver_area_id),
    });
    const { data, setData, errors, processing } = form;
    const [confirmOpen, setConfirmOpen] = useState(false);
    const [deactivating, setDeactivating] = useState(false);

    const needsArea = roleOptions.some((role) => role.requires_area && data.roles.includes(role.name));
    const areas = areaOptions.filter((area) => area.company_id === user.company_id);
    const subtitle = user.is_local
        ? 'Cuenta local de emergencia'
        : [company?.short_name, user.area_name ?? 'Sin área'].filter(Boolean).join(' · ');

    const toggleRole = (name: string, checked: boolean) => {
        setData('roles', checked ? [...data.roles, name] : data.roles.filter((role) => role !== name));
        form.clearErrors('roles');
    };

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        if (needsArea && data.area_id === '') {
            form.setError('area_id', 'Escoge el área que aprueba.');
            return;
        }
        form.transform((values) => ({ roles: values.roles, area_id: needsArea && values.area_id !== '' ? Number(values.area_id) : null }));
        form.put(route('admin.users.roles.update', { user: user.id }), { preserveScroll: true });
    };

    const deactivate = () => {
        setDeactivating(true);
        router.patch(
            route('admin.users.deactivate', { user: user.id }),
            {},
            {
                preserveScroll: true,
                onFinish: () => {
                    setDeactivating(false);
                    setConfirmOpen(false);
                },
            },
        );
    };

    return (
        <>
            <form onSubmit={submit} noValidate aria-labelledby="usuario-escogido" className="flex flex-col gap-4 rounded-card border border-line bg-surface p-5">
                <div className="flex items-center gap-3">
                    <Avatar name={user.name} size="lg" />
                    <span className="flex min-w-0 flex-col">
                        <h2 id="usuario-escogido" className="truncate font-sans text-base font-bold tracking-normal">
                            {user.name}
                        </h2>
                        <span className="truncate text-[13px] text-fg-muted">{subtitle}</span>
                    </span>
                    {!user.active ? (
                        <Chip tone="slate" size="sm" className="ml-auto">
                            Inactivo
                        </Chip>
                    ) : null}
                </div>

                {user.is_local ? (
                    <p className="rounded-control bg-status-local-bg px-3.5 py-3 text-[13px] leading-[1.45] text-status-local">
                        Entra con usuario y contraseña, no con Microsoft. Es para cuando Microsoft no responde; cada uso queda en auditoría. Sus
                        roles no se cambian desde aquí.
                    </p>
                ) : null}

                <fieldset className="flex flex-col" disabled={user.is_local || !user.active}>
                    <legend className="pb-1.5 text-[13px] font-bold text-fg-muted">Roles</legend>
                    {roleOptions.map((role) => (
                        <div key={role.name}>
                            <Checkbox
                                label={role.label}
                                description={role.description}
                                checked={data.roles.includes(role.name)}
                                onChange={(event) => toggleRole(role.name, event.target.checked)}
                            />
                            {role.requires_area && data.roles.includes(role.name) ? (
                                <Select
                                    label="Área que aprueba"
                                    value={data.area_id}
                                    onChange={(event) => {
                                        setData('area_id', event.target.value);
                                        form.clearErrors('area_id');
                                    }}
                                    placeholder="Escoge un área"
                                    options={areas.map((area) => ({ value: String(area.id), label: area.name }))}
                                    error={errors.area_id}
                                    containerClassName="mb-2 ml-8"
                                />
                            ) : null}
                        </div>
                    ))}
                    {errors.roles ? <p className="text-[13px] font-semibold text-status-failure">{errors.roles}</p> : null}
                </fieldset>

                <VaultNote holderName={vaultHolderName} />

                {user.active ? (
                    <div className="flex gap-2">
                        {!user.is_local ? (
                            <Button type="submit" loading={processing} disabled={!form.isDirty} className="flex-1">
                                Guardar roles
                            </Button>
                        ) : null}
                        <Button variant="danger" onClick={() => setConfirmOpen(true)} className={user.is_local ? 'flex-1' : undefined}>
                            Desactivar
                        </Button>
                    </div>
                ) : (
                    <p className="text-[13px] text-fg-muted">Este usuario está desactivado: no puede entrar ni recibe avisos.</p>
                )}
            </form>

            <ConfirmDialog
                open={confirmOpen}
                onClose={() => setConfirmOpen(false)}
                onConfirm={deactivate}
                processing={deactivating}
                title={`¿Desactivar a ${user.name}?`}
                description={
                    user.is_local
                        ? 'Nadie podrá entrar con la cuenta de emergencia hasta que la vuelvas a activar. Queda en auditoría.'
                        : 'No podrá entrar al Gestor ni recibirá avisos. Sus solicitudes y su historial se conservan. Queda en auditoría.'
                }
                confirmLabel="Desactivar"
            />
        </>
    );
}
