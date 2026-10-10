import { Link, router, useForm } from '@inertiajs/react';
import { useEffect, useState, type FormEvent, type ReactNode } from 'react';
import { VaultZone } from '@/Components/ai/VaultZone';
import { Button, buttonClasses } from '@/Components/ui/Button';
import { ConfirmDialog } from '@/Components/ui/Dialog';
import { controlClasses, Field, useFieldIds } from '@/Components/ui/Field';
import { Icon, type IconName } from '@/Components/ui/Icon';
import { Select } from '@/Components/ui/Select';
import { TextArea } from '@/Components/ui/TextArea';
import { TextField } from '@/Components/ui/TextField';
import { cx } from '@/Components/ui/utils';
import { countdownSpoken, formatCountdown } from '@/lib/countdown';
import { route } from '@/lib/route';
import { useClock } from '@/lib/useClock';
import type { VaultSecretType } from '@/types/ai';
import type { VaultCreateProps, VaultEditProps, VaultItemPayload, VaultProjectOption } from '@/types/pages/ai-value-vault';
import type { FormErrors } from '@/types/shared';
import {
    deleteConfirmMatches,
    nextRotation,
    nextRotationText,
    REVEAL_SECONDS,
    ROTATION_OPTIONS,
    ROTATION_REMINDER_DAYS,
    rotationBase,
    sessionLeft,
    VAULT_ENV_LABELS,
    VAULT_TYPE_LABELS,
} from './vault';

/** Types that never carry a user name. */
const NO_USERNAME: VaultSecretType[] = ['api_key', 'token'];
const TYPE_ORDER: VaultSecretType[] = ['api_key', 'user_password', 'token', 'connection_string'];

const projectKey = (option: VaultProjectOption) => `${option.product}|${option.environment}`;
const projectLabel = (option: VaultProjectOption) => `${option.product} · ${VAULT_ENV_LABELS[option.environment].toLowerCase()}`;

export type VaultItemFormProps = ({ mode: 'edit' } & VaultEditProps) | ({ mode: 'create' } & VaultCreateProps);

function initialState(props: VaultItemFormProps): VaultItemPayload {
    if (props.mode === 'edit') {
        const { item } = props;
        return {
            name: item.name,
            product: item.product,
            environment: item.environment,
            type: item.type,
            username: item.username ?? '',
            // The current value never reaches the browser: the field always starts empty.
            value: '',
            rotation_days: item.rotation_days,
            notes: item.notes ?? '',
        };
    }
    const project = props.defaults ?? props.projects[0] ?? { product: '', environment: 'production' as const };
    return { name: '', product: project.product, environment: project.environment, type: 'api_key', username: '', value: '', rotation_days: 90, notes: '' };
}

function InfoCard({ icon, tone, title, children }: { icon: IconName; tone: 'amber' | 'neutral'; title: string; children: ReactNode }) {
    return (
        <div className={cx('flex items-start gap-3 rounded-[14px] border border-line-strong p-3.5', tone === 'amber' && 'bg-surface')}>
            <span
                className={cx(
                    'flex size-9 shrink-0 items-center justify-center rounded-[12px]',
                    tone === 'amber' ? 'bg-status-maintenance-bg text-status-maintenance' : 'bg-subtle text-fg',
                )}
            >
                <Icon name={icon} size={18} />
            </span>
            <span className="flex min-w-0 flex-col gap-1 text-[13px] leading-[1.45] text-fg-soft">
                <b className="text-sm text-fg">{title}</b>
                {children}
            </span>
        </div>
    );
}

/** Secret input: type=password with a show toggle; never prefilled, never autocompleted. */
function SecretField({ value, onChange, error, creating }: { value: string; onChange: (value: string) => void; error?: string; creating: boolean }) {
    const [visible, setVisible] = useState(false);
    const hint = creating ? (
        <span className="flex items-start gap-1.5 font-semibold text-status-maintenance">
            <Icon name="lock" size={14} className="mt-0.5 shrink-0" />
            Se guarda cifrado. Después solo se ve {REVEAL_SECONDS} segundos con “Ver valor”.
        </span>
    ) : (
        <span className="flex items-start gap-1.5 font-semibold text-status-maintenance">
            <Icon name="info" size={14} className="mt-0.5 shrink-0" />
            Déjalo en blanco para no cambiarla. El valor actual nunca se carga en este formulario.
        </span>
    );
    const ids = useFieldIds('vault-value', hint, error);
    return (
        <Field label="Valor" hint={hint} error={error} ids={ids}>
            <span className="flex items-center gap-2">
                <input
                    id={ids.inputId}
                    type={visible ? 'text' : 'password'}
                    value={value}
                    onChange={(event) => onChange(event.target.value)}
                    placeholder={creating ? 'Pega aquí el valor' : 'Pega aquí el valor nuevo'}
                    autoComplete="new-password"
                    autoCapitalize="off"
                    autoCorrect="off"
                    spellCheck={false}
                    aria-invalid={error ? true : undefined}
                    aria-describedby={ids.describedBy}
                    className={cx(controlClasses(Boolean(error)), 'min-w-0 flex-1', value !== '' && 'font-mono')}
                />
                <button
                    type="button"
                    aria-pressed={visible}
                    aria-label={visible ? 'Ocultar lo que escribes' : 'Mostrar lo que escribes'}
                    onClick={() => setVisible((current) => !current)}
                    className="flex size-11 shrink-0 items-center justify-center rounded-control border border-line-strong text-fg hover:bg-subtle"
                >
                    <Icon name={visible ? 'eye-off' : 'eye'} size={18} />
                </button>
            </span>
        </Field>
    );
}

/** Edit / create a vault credential inside the dark protected zone (board C-VaultItemForm). */
export function VaultItemForm(props: VaultItemFormProps) {
    const { session, projects, holder_name, telegram_alerts, today, now, errors: serverErrors = {} } = props;
    const editing = props.mode === 'edit';
    const form = useForm<VaultItemPayload>(initialState(props));
    const [confirmText, setConfirmText] = useState(props.mode === 'edit' ? (props.preview_delete_confirm ?? '') : '');
    const [deleteOpen, setDeleteOpen] = useState(false);
    const [deleting, setDeleting] = useState(false);
    const clock = useClock({ startAt: now });
    const left = sessionLeft(session, clock);
    const locked = left === 0;

    useEffect(() => {
        if (Object.keys(serverErrors).length > 0) form.setError(serverErrors as Partial<Record<keyof VaultItemPayload, string>>);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    // Drop a typed secret from memory as soon as the vault locks.
    useEffect(() => {
        if (locked && form.data.value !== '') form.setData('value', '');
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [locked]);

    const errors = form.errors as FormErrors;
    const { data } = form;
    const first = holder_name.split(' ')[0] ?? holder_name;
    const itemId = props.mode === 'edit' ? props.item.id : null;
    const cancelHref = route('ai.vault.index', itemId !== null ? { item: itemId } : undefined);

    const next = nextRotation(
        rotationBase({ lastRotatedAt: props.mode === 'edit' ? props.last_rotated_at : null, today, valueChanged: data.value !== '' }),
        data.rotation_days,
    );

    const projectOptions = projects.some((option) => projectKey(option) === projectKey(data))
        ? projects
        : [{ product: data.product, environment: data.environment }, ...projects];
    const noUsername = NO_USERNAME.includes(data.type);

    const submit = (event: FormEvent) => {
        event.preventDefault();
        if (locked) return;
        const options = { preserveScroll: true, onSuccess: () => form.reset('value') };
        if (itemId !== null) form.put(route('ai.vault.update', { item: itemId }), options);
        else form.post(route('ai.vault.store'), options);
    };

    const destroy = () => {
        if (itemId === null) return;
        router.delete(route('ai.vault.destroy', { item: itemId }), {
            onStart: () => setDeleting(true),
            onFinish: () => {
                setDeleting(false);
                setDeleteOpen(false);
            },
        });
    };

    const itemName = props.mode === 'edit' ? props.item.name : '';
    const canDelete = editing && deleteConfirmMatches(confirmText, itemName);
    const title = props.mode === 'edit' ? `Editar credencial · ${props.item.name}` : 'Nueva credencial';
    const description =
        props.mode === 'edit' ? `Bóveda · ${props.item.product} · ${VAULT_ENV_LABELS[props.item.environment].toLowerCase()}` : 'Bóveda · el valor se guarda cifrado';

    return (
        <VaultZone
            titleAs="h1"
            title={<span className="break-all">{title}</span>}
            description={description}
            actions={
                <>
                    <span
                        role="timer"
                        aria-live="off"
                        aria-label={`La bóveda se cierra en ${countdownSpoken(left)}`}
                        className="tabular inline-flex h-11 items-center gap-1.5 rounded-control bg-status-maintenance-bg px-3 text-[13px] font-bold text-status-maintenance"
                    >
                        <Icon name="clock" size={14} />
                        Se cierra en {formatCountdown(left)}
                    </span>
                    <Link href={cancelHref} className={buttonClasses({ variant: 'secondary' })}>
                        Cancelar
                    </Link>
                    <Button type="submit" form="vault-form" variant="vault" loading={form.processing} disabled={locked}>
                        {editing ? 'Guardar cambios' : 'Guardar credencial'}
                    </Button>
                </>
            }
        >
            {locked ? (
                <p role="status" className="flex flex-wrap items-center gap-x-3 gap-y-2 rounded-control bg-subtle p-3 text-sm text-fg-soft">
                    <span className="flex items-center gap-2">
                        <Icon name="lock" size={16} />
                        La bóveda se cerró por inactividad. Confirma tu identidad otra vez para guardar.
                    </span>
                    <a href={route('ai.vault.reauth', itemId !== null ? { item: itemId } : undefined)} className="font-bold text-status-maintenance underline underline-offset-2">
                        Confirmar identidad con Microsoft
                    </a>
                </p>
            ) : null}

            {Object.keys(errors).length > 0 ? (
                <p role="alert" className="flex items-center gap-2 text-sm font-semibold text-status-failure">
                    <Icon name="alert-triangle" size={16} />
                    {Object.keys(errors).length === 1 ? 'Falta corregir 1 campo.' : `Falta corregir ${Object.keys(errors).length} campos.`}
                </p>
            ) : null}

            <div className="-mx-5 grid border-t border-line md:-mx-6 lg:grid-cols-[minmax(0,1fr)_380px]">
                <form
                    id="vault-form"
                    onSubmit={submit}
                    noValidate
                    autoComplete="off"
                    aria-label="Datos de la credencial"
                    className="flex min-w-0 flex-col gap-4 px-5 py-5 md:px-6 lg:border-r lg:border-line"
                >
                    <div className="grid grid-cols-1 gap-3.5 sm:grid-cols-2">
                        <TextField
                            label="Nombre"
                            value={data.name}
                            error={errors.name}
                            autoComplete="off"
                            spellCheck={false}
                            onChange={(event) => form.setData('name', event.target.value)}
                        />
                        <Select
                            label="Proyecto"
                            value={projectKey(data)}
                            options={projectOptions.map((option) => ({ value: projectKey(option), label: projectLabel(option) }))}
                            error={errors.product ?? errors.environment}
                            onChange={(event) => {
                                const option = projectOptions.find((item) => projectKey(item) === event.target.value);
                                if (option) form.setData({ ...data, product: option.product, environment: option.environment });
                            }}
                        />
                        <Select
                            label="Tipo"
                            value={data.type}
                            options={TYPE_ORDER.map((type) => ({ value: type, label: VAULT_TYPE_LABELS[type] }))}
                            error={errors.type}
                            onChange={(event) => {
                                const type = event.target.value as VaultSecretType;
                                form.setData({ ...data, type, username: NO_USERNAME.includes(type) ? '' : data.username });
                            }}
                        />
                        <TextField
                            label="Usuario"
                            value={noUsername ? '' : data.username}
                            placeholder={noUsername ? '—' : undefined}
                            disabled={noUsername}
                            error={errors.username}
                            hint={noUsername ? `Las ${data.type === 'token' ? 'credenciales de tipo token' : 'llaves de API'} no llevan usuario.` : undefined}
                            autoComplete="off"
                            spellCheck={false}
                            onChange={(event) => form.setData('username', event.target.value)}
                        />
                    </div>

                    <SecretField value={data.value} onChange={(value) => form.setData('value', value)} error={errors.value} creating={!editing} />

                    <div className="grid grid-cols-1 gap-3.5 sm:grid-cols-2">
                        <Select
                            label="Rotar cada"
                            value={data.rotation_days === null ? 'none' : String(data.rotation_days)}
                            options={ROTATION_OPTIONS.map((days) => ({ value: days === null ? 'none' : String(days), label: days === null ? 'No rotar' : `${days} días` }))}
                            error={errors.rotation_days}
                            onChange={(event) => form.setData('rotation_days', event.target.value === 'none' ? null : Number(event.target.value))}
                        />
                        <div className="flex min-w-0 flex-col gap-1.5">
                            <span id="vault-next-label" className="text-[13px] font-semibold">
                                Próxima rotación
                            </span>
                            <output
                                aria-labelledby="vault-next-label"
                                aria-describedby="vault-next-hint"
                                className="flex h-11 items-center rounded-control border border-dashed border-line-strong px-3 text-[15px] font-semibold"
                            >
                                {nextRotationText(next, today)}
                            </output>
                            <span id="vault-next-hint" className="text-[13px] leading-snug text-fg-muted">
                                {next === null
                                    ? 'Sin fecha: no te avisamos.'
                                    : data.value !== ''
                                      ? `Cuenta desde hoy porque cambias el valor. Te avisamos ${ROTATION_REMINDER_DAYS} días antes.`
                                      : `Se calcula sola. Te avisamos ${ROTATION_REMINDER_DAYS} días antes.`}
                            </span>
                        </div>
                    </div>

                    <TextArea
                        label="Notas"
                        rows={4}
                        value={data.notes}
                        error={errors.notes}
                        hint="No pegues aquí contraseñas ni llaves: las notas no se cifran como el valor."
                        onChange={(event) => form.setData('notes', event.target.value)}
                    />
                </form>

                <div className="flex min-w-0 flex-col gap-3.5 border-t border-line px-5 py-5 md:px-6 lg:border-t-0">
                    <InfoCard icon="shield" tone="amber" title={`Solo ${first} puede ver esta credencial`}>
                        La bóveda es solo de {holder_name} (permiso ai.vault.access). No se puede compartir desde aquí.
                    </InfoCard>
                    <InfoCard icon={telegram_alerts ? 'telegram' : 'history'} tone="neutral" title="Todo cambio queda registrado">
                        {telegram_alerts
                            ? `Cada cambio queda en la auditoría y le avisa a ${first} por Telegram, con qué cambió, cuándo y desde dónde. El valor nunca va en el aviso.`
                            : 'Cada cambio queda en la auditoría, con qué cambió, cuándo y desde dónde. El valor nunca se guarda en la auditoría.'}
                    </InfoCard>

                    {editing ? (
                        <section
                            aria-labelledby="vault-danger-title"
                            className="mt-auto flex flex-col gap-2.5 rounded-[14px] border border-status-failure-line bg-status-failure-bg p-4"
                        >
                            <h2 id="vault-danger-title" className="flex items-center gap-2 text-[15px] font-bold text-status-failure">
                                <Icon name="alert-triangle" size={16} />
                                Eliminar credencial
                            </h2>
                            <p className="text-[13px] leading-[1.45] text-fg-soft">
                                Se borra el valor y no se puede deshacer. La auditoría conserva quién la eliminó y cuándo.
                            </p>
                            <TextField
                                label={`Escribe "${itemName}" para confirmar`}
                                value={confirmText}
                                autoComplete="off"
                                spellCheck={false}
                                autoCapitalize="off"
                                className="font-mono"
                                hint={canDelete ? 'El nombre coincide.' : 'Se activa cuando el nombre coincide.'}
                                onChange={(event) => setConfirmText(event.target.value)}
                            />
                            <Button variant="danger" fullWidth disabled={!canDelete || locked} onClick={() => setDeleteOpen(true)}>
                                Eliminar credencial
                            </Button>
                        </section>
                    ) : null}
                </div>
            </div>

            {editing ? (
                <ConfirmDialog
                    open={deleteOpen}
                    onClose={() => setDeleteOpen(false)}
                    onConfirm={destroy}
                    processing={deleting}
                    title={`¿Eliminar ${itemName}?`}
                    description={`Se borra el valor de la bóveda y no se puede recuperar. La auditoría conserva quién la eliminó y cuándo.${telegram_alerts ? ` ${first} recibe el aviso por Telegram.` : ''}`}
                    confirmLabel="Eliminar credencial"
                />
            ) : null}
        </VaultZone>
    );
}
