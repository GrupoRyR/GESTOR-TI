import { useForm } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import { AdminDialog } from '@/Components/admin/AdminDialog';
import { AdminPageHeader, joinSpanish } from '@/Components/admin/AdminParts';
import { changedCells, hasPermission, rolesPayload, togglePermission, type RoleGrant } from '@/Components/admin/roleMatrix';
import { Button } from '@/Components/ui/Button';
import { Chip } from '@/Components/ui/Chip';
import { Icon } from '@/Components/ui/Icon';
import { Select } from '@/Components/ui/Select';
import { TextField } from '@/Components/ui/TextField';
import { cx } from '@/Components/ui/utils';
import { AdminLayout } from '@/Layouts/AdminLayout';
import { route } from '@/lib/route';
import type { AdminRole, AdminRolesIndexProps } from '@/types/pages/admin';
import type { PageProps } from '@/types/shared';

const SCOPES = [
    { value: 'Global', label: 'Global: todas las empresas' },
    { value: 'Empresa', label: 'Empresa: solo la suya' },
    { value: 'Empresa y área', label: 'Empresa y área: solo su área' },
];

const toGrants = (roles: AdminRole[]): RoleGrant[] => roles.map((role) => ({ name: role.name, permissions: role.permissions }));

function AddRoleDialog({ open, onClose, roles }: { open: boolean; onClose: () => void; roles: AdminRole[] }) {
    const form = useForm<{ label: string; scope: string; copy_from: string }>({ label: '', scope: 'Empresa', copy_from: '' });
    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        if (!form.data.label.trim()) {
            form.setError('label', 'Escribe el nombre del rol.');
            return;
        }
        form.transform((values) => ({ ...values, copy_from: values.copy_from || null }));
        form.post(route('admin.roles.store'), { preserveScroll: true, onSuccess: onClose });
    };

    return (
        <AdminDialog
            open={open}
            onClose={onClose}
            title="Agregar rol"
            description="Empieza vacío o con los permisos de otro rol; después los ajustas en la tabla."
            onSubmit={submit}
            actions={
                <>
                    <Button variant="secondary" onClick={onClose}>
                        Cancelar
                    </Button>
                    <Button type="submit" loading={form.processing}>
                        Agregar rol
                    </Button>
                </>
            }
        >
            <TextField
                label="Nombre"
                value={form.data.label}
                onChange={(event) => {
                    form.setData('label', event.target.value);
                    form.clearErrors('label');
                }}
                error={form.errors.label}
                autoFocus
            />
            <Select
                label="Alcance"
                value={form.data.scope}
                onChange={(event) => form.setData('scope', event.target.value)}
                options={SCOPES}
                error={form.errors.scope}
            />
            <Select
                label="Copiar permisos de"
                value={form.data.copy_from}
                onChange={(event) => form.setData('copy_from', event.target.value)}
                placeholder="Ninguno: empieza sin permisos"
                options={roles.map((role) => ({ value: role.name, label: role.label }))}
                error={form.errors.copy_from}
                optional
            />
        </AdminDialog>
    );
}

/** Roles y permisos: role × permission matrix saved in one PUT. */
export default function AdminRolesIndex(props: PageProps<AdminRolesIndexProps>) {
    const { roles, permission_groups, vault, reserved_modules } = props;
    const saved = toGrants(roles);
    const form = useForm<{ roles: RoleGrant[] }>({ roles: saved });
    const [dialog, setDialog] = useState({ open: false, nonce: 0 });

    const changes = changedCells(saved, form.data.roles);

    const toggle = (roleName: string, permission: string, on: boolean) =>
        form.setData('roles', togglePermission(form.data.roles, roleName, permission, on));

    const save = () => {
        form.transform((values) => ({ roles: rolesPayload(values.roles) }));
        form.put(route('admin.roles.update'), { preserveScroll: true });
    };

    return (
        <AdminLayout shared={props} title="Roles y permisos · Administración" tab="roles">
            <div className="flex flex-col gap-3.5">
                <AdminPageHeader
                    title="Roles y permisos"
                    description="Marca qué puede hacer cada rol. El servidor vuelve a revisar cada acción."
                    actions={
                        <>
                            {changes > 0 ? (
                                <span role="status" className="flex items-center gap-2">
                                    <Chip tone="amber" dot>
                                        {changes === 1 ? '1 cambio sin guardar' : `${changes} cambios sin guardar`}
                                    </Chip>
                                    <Button variant="quiet" onClick={() => form.reset()}>
                                        Descartar
                                    </Button>
                                </span>
                            ) : (
                                <span role="status" className="sr-only">
                                    Sin cambios
                                </span>
                            )}
                            <Button variant="secondary" icon="plus" onClick={() => setDialog((previous) => ({ open: true, nonce: previous.nonce + 1 }))}>
                                Agregar rol
                            </Button>
                            <Button onClick={save} disabled={changes === 0} loading={form.processing}>
                                Guardar cambios
                            </Button>
                        </>
                    }
                />

                <section aria-label="Permisos por rol" className="overflow-hidden rounded-card border border-line bg-surface">
                    <div className="overflow-x-auto">
                        <table className="w-full border-collapse text-left" style={{ minWidth: 190 + roles.length * 132 }}>
                            <caption className="sr-only">Permisos de cada rol. Cada casilla da o quita un permiso.</caption>
                            <colgroup>
                                <col />
                                {roles.map((role) => (
                                    <col key={role.name} className="w-[132px]" />
                                ))}
                            </colgroup>
                            <thead>
                                <tr className="bg-subtle text-[13px] font-bold">
                                    <th scope="col" className="sticky left-0 z-10 shadow-[1px_0_0_var(--color-line)] w-[190px] bg-subtle px-4 py-3 align-bottom text-fg-muted md:w-auto md:min-w-[280px] md:px-5">
                                        Permiso
                                    </th>
                                    {roles.map((role) => (
                                        <th key={role.name} scope="col" className="px-2 py-3 text-center align-bottom leading-tight">
                                            {role.label}
                                            <span className="block text-[11px] font-semibold text-fg-muted">
                                                {role.scope}
                                                {!role.system ? ' · propio' : ''}
                                            </span>
                                        </th>
                                    ))}
                                </tr>
                            </thead>
                            {permission_groups.map((group) => (
                                <tbody key={group.key}>
                                    <tr className="border-t border-line">
                                        <th
                                            scope="colgroup"
                                            colSpan={roles.length + 1}
                                            className="px-5 pt-2.5 pb-1.5 text-[13px] font-bold text-fg-muted"
                                        >
                                            <span className="sticky left-5">{group.label}</span>
                                        </th>
                                    </tr>
                                    {group.permissions.map((permission) => (
                                        <tr key={permission.name} className="group border-t border-subtle hover:bg-ground">
                                            <th scope="row" className="sticky left-0 z-10 shadow-[1px_0_0_var(--color-line)] bg-surface px-4 py-1.5 text-sm font-normal group-hover:bg-ground md:px-5">
                                                <span className="flex flex-wrap items-baseline gap-x-2.5">
                                                    <span>{permission.label}</span>
                                                    <code className="text-[11px] text-fg-muted">{permission.name}</code>
                                                </span>
                                                {permission.hint ? <span className="block text-xs text-fg-muted">{permission.hint}</span> : null}
                                            </th>
                                            {roles.map((role) => {
                                                const on = hasPermission(form.data.roles, role.name, permission.name);
                                                const changed = on !== hasPermission(saved, role.name, permission.name);
                                                return (
                                                    <td key={role.name} className={cx('p-0 text-center', changed && 'bg-status-maintenance-bg')}>
                                                        <label className="mx-auto flex size-11 cursor-pointer items-center justify-center">
                                                            <input
                                                                type="checkbox"
                                                                checked={on}
                                                                onChange={(event) => toggle(role.name, permission.name, event.target.checked)}
                                                                aria-label={`${permission.label} para ${role.label}`}
                                                                className="size-5 cursor-pointer accent-brand-button"
                                                            />
                                                        </label>
                                                    </td>
                                                );
                                            })}
                                        </tr>
                                    ))}
                                </tbody>
                            ))}
                            <tbody>
                                <tr className="border-t border-line bg-fg text-on-fg dark:bg-ground dark:text-fg">
                                    <th scope="row" className="sticky left-0 z-10 shadow-[1px_0_0_var(--color-line)] bg-fg px-4 py-3 text-sm font-normal md:px-5 dark:bg-ground">
                                        <span className="flex flex-wrap items-center gap-x-2.5 gap-y-1">
                                            <span className="text-status-maintenance-dot">
                                                <Icon name="lock" size={16} />
                                            </span>
                                            Entrar a la bóveda
                                            <code className="text-[11px] opacity-70">ai.vault.access</code>
                                        </span>
                                    </th>
                                    <td colSpan={roles.length} className="px-4 py-3 text-center text-[13px] text-status-maintenance-dot">
                                        No se da por rol. Solo {vault.holder_name}; asignarlo a otra persona pide su confirmación.
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    {reserved_modules.length > 0 ? (
                        <p className="flex items-center gap-2 border-t border-line px-5 py-3 text-[13px] text-fg-muted">
                            <span aria-hidden="true" className="size-2.5 shrink-0 rounded-[3px] border-[1.5px] border-dashed border-fg-muted" />
                            {joinSpanish(reserved_modules)} {reserved_modules.length === 1 ? 'tiene' : 'tienen'} permisos reservados; aparecen aquí cuando se active
                            {reserved_modules.length === 1 ? ' el módulo' : ' cada módulo'}.
                        </p>
                    ) : null}
                </section>
            </div>

            <AddRoleDialog key={dialog.nonce} open={dialog.open} onClose={() => setDialog((previous) => ({ ...previous, open: false }))} roles={roles} />
        </AdminLayout>
    );
}
