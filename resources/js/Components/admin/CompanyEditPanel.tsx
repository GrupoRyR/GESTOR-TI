import { useForm } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import { Button } from '@/Components/ui/Button';
import { ColorField } from '@/Components/ui/ColorField';
import { FileDrop } from '@/Components/ui/FileDrop';
import { Icon } from '@/Components/ui/Icon';
import { Switch } from '@/Components/ui/Switch';
import { TextField } from '@/Components/ui/TextField';
import { cx } from '@/Components/ui/utils';
import { route } from '@/lib/route';
import type { AdminCompany } from '@/types/pages/admin';
import { AdminDialog } from './AdminDialog';
import { buttonContrast, contrastNote } from './brandContrast';
import { parseDomains } from './companyWizard';

interface CompanyForm {
    _method: 'put';
    name: string;
    short_name: string;
    slug: string;
    domains: string;
    sender_email: string;
    colors: { primary: string; accent: string; dark: string };
    logo: File | null;
    logo_dark: File | null;
    active: boolean;
    tenant_id: string;
}

const GUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;

function initialForm(company: AdminCompany): CompanyForm {
    return {
        _method: 'put',
        name: company.name,
        short_name: company.short_name,
        slug: company.slug,
        domains: company.domains.join(', '),
        sender_email: company.sender_email,
        colors: { ...company.colors },
        logo: null,
        logo_dark: null,
        active: company.active,
        tenant_id: company.tenant_id,
    };
}

export interface CompanyEditPanelProps {
    company: AdminCompany;
    canChangeTenant: boolean;
}

/**
 * Edit panel of one company: data, Microsoft tenant (locked; Súper Admin
 * can change it through an audited dialog), sender mailbox, state, logos
 * and colors with a live contrast note. Saves as multipart (logos).
 */
export function CompanyEditPanel({ company, canChangeTenant }: CompanyEditPanelProps) {
    const form = useForm<CompanyForm>(initialForm(company));
    const { data, setData, errors, processing } = form;
    const [tenantOpen, setTenantOpen] = useState(false);
    const [tenantDraft, setTenantDraft] = useState(company.tenant_id);
    const [tenantError, setTenantError] = useState<string | null>(null);
    const tenantChanged = data.tenant_id !== company.tenant_id;
    const contrast = buttonContrast(data.colors);

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        form.transform((values) => {
            const { tenant_id, ...rest } = values;
            return {
                ...rest,
                domains: parseDomains(values.domains),
                // Only a Súper Admin may send a new tenant; the server audits it.
                ...(canChangeTenant && tenant_id !== company.tenant_id ? { tenant_id } : {}),
            };
        });
        form.post(route('admin.companies.update', { company: company.id }), { forceFormData: true, preserveScroll: true });
    };

    const discard = () => {
        form.reset();
        form.clearErrors();
        setTenantDraft(company.tenant_id);
    };

    const confirmTenant = () => {
        const next = tenantDraft.trim().toLowerCase();
        if (!GUID.test(next)) {
            setTenantError('El ID tiene la forma 00000000-0000-0000-0000-000000000000.');
            return;
        }
        setData('tenant_id', next);
        setTenantError(null);
        setTenantOpen(false);
    };

    const setColor = (key: keyof CompanyForm['colors']) => (hex: string) => setData('colors', { ...data.colors, [key]: hex });

    return (
        <>
            <form
                onSubmit={submit}
                noValidate
                aria-labelledby="empresa-editada"
                className="@container flex flex-col gap-5 rounded-card border border-line bg-surface p-5 md:p-6"
            >
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <h2 id="empresa-editada" className="font-display text-xl leading-tight font-bold">
                        {company.name}
                    </h2>
                    <div className="flex gap-2">
                        <Button variant="secondary" onClick={discard} disabled={!form.isDirty || processing}>
                            Descartar
                        </Button>
                        <Button type="submit" loading={processing}>
                            Guardar cambios
                        </Button>
                    </div>
                </div>

                <div className="grid grid-cols-1 gap-3.5 @md:grid-cols-2 @xl:grid-cols-3">
                    <TextField
                        label="Nombre"
                        value={data.name}
                        onChange={(event) => setData('name', event.target.value)}
                        error={errors.name}
                        autoComplete="organization"
                    />
                    <TextField
                        label="Nombre corto"
                        value={data.short_name}
                        onChange={(event) => setData('short_name', event.target.value)}
                        error={errors.short_name}
                        hint="Se ve en el menú y en el selector de empresa."
                    />
                    <TextField
                        label="Identificador"
                        value={data.slug}
                        onChange={(event) => setData('slug', event.target.value)}
                        error={errors.slug}
                        autoCapitalize="off"
                        spellCheck={false}
                    />

                    <div className="flex flex-col gap-1.5 @md:col-span-2">
                        <span id="tenant-label" className="text-[13px] font-semibold">
                            ID de inquilino de Microsoft
                        </span>
                        <div
                            aria-labelledby="tenant-label"
                            role="group"
                            className={cx(
                                'flex min-h-11 items-center gap-2 rounded-control border border-line bg-subtle py-1 pr-1 pl-3 text-[15px] text-fg-soft',
                                errors.tenant_id && 'border-status-failure',
                            )}
                        >
                            <Icon name="lock" size={14} />
                            <span className="tabular min-w-0 flex-1 truncate">{data.tenant_id}</span>
                            {canChangeTenant ? (
                                <button
                                    type="button"
                                    onClick={() => {
                                        setTenantDraft(data.tenant_id);
                                        setTenantError(null);
                                        setTenantOpen(true);
                                    }}
                                    className="inline-flex h-9 shrink-0 items-center rounded-[8px] border border-line-strong bg-surface px-3 text-xs font-bold text-fg hover:bg-ground"
                                >
                                    Cambiar
                                </button>
                            ) : null}
                        </div>
                        {errors.tenant_id ? (
                            <p className="text-[13px] font-semibold text-status-failure">{errors.tenant_id}</p>
                        ) : null}
                        <p className={cx('text-[13px] leading-snug', tenantChanged ? 'font-semibold text-status-maintenance' : 'text-fg-muted')}>
                            {tenantChanged
                                ? 'Inquilino nuevo sin guardar. Al guardar queda en auditoría.'
                                : 'Solo Súper Admin. El cambio queda en auditoría.'}
                        </p>
                    </div>

                    <TextField
                        label="Dominios"
                        value={data.domains}
                        onChange={(event) => setData('domains', event.target.value)}
                        error={errors.domains}
                        hint="Sepáralos con comas."
                        autoCapitalize="off"
                        spellCheck={false}
                    />
                    <TextField
                        label="Correo emisor"
                        type="email"
                        value={data.sender_email}
                        onChange={(event) => setData('sender_email', event.target.value)}
                        error={errors.sender_email}
                        hint={`Todo correo de un registro de ${data.short_name || company.short_name} sale de este buzón.`}
                        containerClassName="@md:col-span-2"
                        autoComplete="off"
                    />
                    <div className="flex flex-col gap-1.5">
                        <span className="text-[13px] font-semibold">Estado</span>
                        <Switch
                            checked={data.active}
                            onChange={(checked) => setData('active', checked)}
                            label={data.active ? 'Activa: pueden entrar' : 'Inactiva: nadie entra'}
                        />
                    </div>
                </div>

                <div className="grid grid-cols-1 gap-3.5 border-t border-line pt-5 @md:grid-cols-2 @xl:grid-cols-[1fr_1fr_1.2fr]">
                    <FileDrop
                        label="Logo para fondo claro"
                        accept="image/svg+xml,image/png"
                        previewUrl={company.logo_url}
                        onFile={(file) => setData('logo', file)}
                        error={errors.logo}
                        hint="SVG o PNG."
                    />
                    <FileDrop
                        label="Logo para fondo oscuro"
                        accept="image/svg+xml,image/png"
                        surface="dark"
                        previewUrl={company.logo_dark_url ?? company.logo_url}
                        onFile={(file) => setData('logo_dark', file)}
                        error={errors.logo_dark}
                        hint={company.logo_dark_url ? undefined : 'Si no hay, se usa el claro.'}
                    />
                    <fieldset className="flex min-w-0 flex-col gap-3 @md:col-span-2 @xl:col-span-1">
                        <legend className="mb-1.5 text-[13px] font-semibold">Colores</legend>
                        <ColorField label="Principal" value={data.colors.primary} onChange={setColor('primary')} error={errors['colors.primary']} />
                        <ColorField label="Acento" value={data.colors.accent} onChange={setColor('accent')} error={errors['colors.accent']} />
                        <ColorField label="Oscuro" value={data.colors.dark} onChange={setColor('dark')} error={errors['colors.dark']} />
                        <p
                            aria-live="polite"
                            className={cx(
                                'flex items-start gap-1.5 text-[13px] leading-snug',
                                contrast.passes ? 'text-fg-muted' : 'text-status-maintenance',
                            )}
                        >
                            <span className={cx('mt-0.5', contrast.passes ? 'text-status-active' : 'text-status-maintenance-dot')}>
                                <Icon name={contrast.passes ? 'check-circle' : 'alert-triangle'} size={14} />
                            </span>
                            {contrastNote(data.colors)}
                        </p>
                    </fieldset>
                </div>
            </form>

            <AdminDialog
                open={tenantOpen}
                onClose={() => setTenantOpen(false)}
                title="Cambiar el inquilino de Microsoft"
                description={
                    <>
                        Con otro inquilino, la gente de {company.short_name} entra con otras cuentas y los colaboradores se vuelven a
                        sincronizar desde allí. El cambio queda en auditoría con tu nombre.
                    </>
                }
                onSubmit={(event) => {
                    event.preventDefault();
                    confirmTenant();
                }}
                actions={
                    <>
                        <Button variant="secondary" onClick={() => setTenantOpen(false)}>
                            Cancelar
                        </Button>
                        <Button type="submit" variant="dark">
                            Usar este inquilino
                        </Button>
                    </>
                }
            >
                <TextField
                    label="ID de inquilino nuevo"
                    value={tenantDraft}
                    onChange={(event) => setTenantDraft(event.target.value)}
                    error={tenantError ?? undefined}
                    hint="Microsoft Entra › Información general › ID de inquilino. Se aplica al guardar la empresa."
                    autoCapitalize="off"
                    spellCheck={false}
                    autoFocus
                />
            </AdminDialog>
        </>
    );
}
