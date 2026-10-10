import { useForm } from '@inertiajs/react';
import { useId, type FormEvent } from 'react';
import { Button } from '@/Components/ui/Button';
import { Icon } from '@/Components/ui/Icon';
import { cx } from '@/Components/ui/utils';
import { formatDate } from '@/lib/format';
import { calendarIso, expiresAt, toolsFor } from '@/lib/mcp';
import { route } from '@/lib/route';
import type { McpExpiryDays, McpScope } from '@/types/mcp';
import { McpModal } from './McpModal';

export interface CreateTokenPayload {
    name: string;
    scope: McpScope;
    expires_in_days: McpExpiryDays;
}

const SCOPES: { value: McpScope; title: string; description: string; icon: 'eye' | 'edit'; tone: string }[] = [
    { value: 'read', title: 'Solo lectura', description: 'Claude busca y lee solicitudes. No cambia nada.', icon: 'eye', tone: 'text-status-local' },
    {
        value: 'read_write',
        title: 'Lectura y escritura',
        description: 'Además crea y actualiza tareas, agrega notas y adjunta documentos.',
        icon: 'edit',
        tone: 'text-status-maintenance',
    },
];

const EXPIRY: McpExpiryDays[] = [30, 90, 180];

export interface CreateTokenDialogProps {
    open: boolean;
    onClose: () => void;
    now: Date;
    /** Prefill for "Crear token de reemplazo". */
    initial?: Partial<CreateTokenPayload>;
}

/** E-McpCreate: name, permission as radio cards listing their tools, expiry with the computed date. */
export function CreateTokenDialog({ open, onClose, now, initial }: CreateTokenDialogProps) {
    const form = useForm<CreateTokenPayload>({ name: '', scope: 'read', expires_in_days: 90, ...initial });
    const nameId = useId();
    const nameHintId = useId();
    const nameErrorId = useId();
    const expiryId = useId();
    const expiryDateId = useId();
    const radioName = useId();

    const close = () => {
        form.reset();
        form.clearErrors();
        onClose();
    };

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        const name = form.data.name.trim();
        if (!name) {
            form.setError('name', 'Escribe un nombre para reconocer el token.');
            return;
        }
        form.transform((data) => ({ ...data, name }));
        form.post(route('profile.mcp.tokens.store'), { preserveScroll: true, onSuccess: close });
    };

    return (
        <McpModal
            open={open}
            onClose={close}
            title="Crear token"
            description="Para que Claude se conecte al Gestor con tu usuario"
            onSubmit={submit}
            footnote="Puedes revocarlo cuando quieras."
            actions={
                <>
                    <Button variant="secondary" onClick={close}>
                        Cancelar
                    </Button>
                    <Button type="submit" loading={form.processing}>
                        Crear token
                    </Button>
                </>
            }
        >
            <div className="flex flex-col gap-1.5">
                <label htmlFor={nameId} className="text-[13px] font-semibold">
                    Nombre
                </label>
                <span id={nameHintId} className="-mt-1 text-[13px] text-fg-muted">
                    Para reconocerlo después, por ejemplo dónde lo vas a usar
                </span>
                <input
                    id={nameId}
                    type="text"
                    value={form.data.name}
                    maxLength={60}
                    autoComplete="off"
                    onChange={(event) => form.setData('name', event.target.value)}
                    placeholder="Por ejemplo: Claude · portátil"
                    aria-invalid={form.errors.name ? true : undefined}
                    aria-describedby={cx(nameHintId, form.errors.name && nameErrorId)}
                    className={cx(
                        'h-11 rounded-control border bg-surface px-3.5 text-[15px] text-fg outline-none placeholder:text-fg-muted focus-visible:outline-2 focus-visible:outline-fg',
                        form.errors.name ? 'border-status-failure' : 'border-line-strong',
                    )}
                />
                {form.errors.name ? (
                    <span id={nameErrorId} className="flex items-center gap-1.5 text-[13px] font-semibold text-status-failure">
                        <Icon name="alert-triangle" size={14} />
                        {form.errors.name}
                    </span>
                ) : null}
            </div>

            <fieldset className="flex min-w-0 flex-col">
                <legend className="mb-2 text-[13px] font-semibold">Permiso</legend>
                <div className="grid items-start gap-2.5 sm:grid-cols-2">
                    {SCOPES.map((scope) => {
                        const checked = form.data.scope === scope.value;
                        return (
                            <label
                                key={scope.value}
                                className={cx(
                                    'flex cursor-pointer flex-col gap-2.5 rounded-[12px] p-3.5 transition-colors',
                                    'has-focus-visible:outline-2 has-focus-visible:outline-offset-2 has-focus-visible:outline-fg',
                                    checked ? 'border-2 border-brand-button bg-brand-tint/40' : 'm-px border border-line-strong bg-surface hover:border-fg-muted',
                                )}
                            >
                                <span className="flex items-center gap-2.5">
                                    <input
                                        type="radio"
                                        name={radioName}
                                        value={scope.value}
                                        checked={checked}
                                        onChange={() => form.setData('scope', scope.value)}
                                        className="size-5 shrink-0 accent-[var(--color-brand-button)]"
                                    />
                                    <b className="text-[15px]">{scope.title}</b>
                                    <Icon name={scope.icon} size={18} className={cx('ml-auto', scope.tone)} />
                                </span>
                                <span className="text-[13px] leading-[1.4] text-fg-soft">{scope.description}</span>
                                <ul aria-label={`Herramientas con ${scope.title.toLowerCase()}`} className="flex flex-col gap-1">
                                    {toolsFor(scope.value).map((tool) => (
                                        <li key={tool.key} className="flex items-center gap-1.5 text-xs">
                                            <Icon name="check" size={13} className="text-status-active" />
                                            <code className="font-mono text-xs">{tool.key}</code>
                                        </li>
                                    ))}
                                </ul>
                            </label>
                        );
                    })}
                </div>
            </fieldset>

            <div className="flex flex-col gap-1.5">
                <label htmlFor={expiryId} className="text-[13px] font-semibold">
                    Vence en
                </label>
                <div className="flex flex-wrap items-center gap-3">
                    <span className="relative block w-[220px] max-w-full">
                        <select
                            id={expiryId}
                            value={form.data.expires_in_days}
                            onChange={(event) => form.setData('expires_in_days', Number(event.target.value) as McpExpiryDays)}
                            aria-describedby={expiryDateId}
                            className="h-11 w-full appearance-none rounded-control border border-line-strong bg-surface pr-10 pl-3.5 text-[15px] text-fg outline-none focus-visible:outline-2 focus-visible:outline-fg"
                        >
                            {EXPIRY.map((days) => (
                                <option key={days} value={days}>
                                    {days} días
                                </option>
                            ))}
                        </select>
                        <Icon name="chevron-down" size={16} className="pointer-events-none absolute top-3.5 right-3 text-fg-soft" />
                    </span>
                    <span id={expiryDateId} className="text-sm text-fg-soft">
                        Vence el {formatDate(calendarIso(expiresAt(now, form.data.expires_in_days)))}
                    </span>
                </div>
            </div>

            <div role="note" className="flex items-start gap-2.5 rounded-[12px] bg-status-maintenance-bg px-3.5 py-3 text-[13px] leading-[1.45] text-status-maintenance">
                <Icon name="key" size={18} className="mt-px shrink-0" />
                <span>
                    <b>El token se muestra una sola vez.</b> Tenlo a mano para pegarlo en Claude apenas lo crees.
                </span>
            </div>
        </McpModal>
    );
}
