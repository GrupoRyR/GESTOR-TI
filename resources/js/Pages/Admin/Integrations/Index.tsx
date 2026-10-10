import { useForm } from '@inertiajs/react';
import type { FormEvent, ReactNode } from 'react';
import { AdminPageHeader } from '@/Components/admin/AdminParts';
import { BudgetBar } from '@/Components/ui/BudgetBar';
import { Button, buttonClasses } from '@/Components/ui/Button';
import { Chip, type ChipTone } from '@/Components/ui/Chip';
import { Icon } from '@/Components/ui/Icon';
import { Select } from '@/Components/ui/Select';
import { TextField } from '@/Components/ui/TextField';
import { cx } from '@/Components/ui/utils';
import { AdminLayout } from '@/Layouts/AdminLayout';
import { formatRelative } from '@/lib/format';
import { route } from '@/lib/route';
import type { AdminIntegrationsIndexProps, IntegrationsProps } from '@/types/pages/admin';
import type { Company, PageProps } from '@/types/shared';

const usd = new Intl.NumberFormat('es-CO', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const money = (amount: number) => `US$ ${usd.format(amount)}`;

const monthName = new Intl.DateTimeFormat('es-CO', { month: 'long', timeZone: 'America/Bogota' });

function StatusTag({ tone, children }: { tone: ChipTone; children: ReactNode }) {
    const icon = tone === 'amber' ? 'alert-triangle' : tone === 'red' ? 'x-circle' : undefined;
    return (
        <Chip tone={tone} size="sm" dot={tone === 'green'} icon={icon}>
            {children}
        </Chip>
    );
}

function Card({
    title,
    status,
    subtitle,
    dashed = false,
    className,
    children,
    footer,
}: {
    title: string;
    status: ReactNode;
    subtitle: ReactNode;
    dashed?: boolean;
    className?: string;
    children?: ReactNode;
    footer?: ReactNode;
}) {
    const id = `integracion-${title.toLowerCase().replace(/[^a-z]+/g, '-')}`;
    return (
        <section
            aria-labelledby={id}
            className={cx(
                'flex flex-col gap-3 rounded-card bg-surface p-[18px]',
                dashed ? 'border-[1.5px] border-dashed border-line-strong' : 'border border-line',
                className,
            )}
        >
            <div className="flex items-center justify-between gap-3">
                <h2 id={id} className="font-display text-lg font-bold">
                    {title}
                </h2>
                {status}
            </div>
            <p className="text-[13px] leading-[1.45] text-fg-soft">{subtitle}</p>
            {children}
            {footer ? <div className="mt-auto flex flex-col gap-2 pt-1">{footer}</div> : null}
        </section>
    );
}

function MicrosoftCard({ microsoft, companyById }: { microsoft: IntegrationsProps['microsoft']; companyById: (id: number) => Company | undefined }) {
    const failing = microsoft.companies.filter((item) => item.status === 'error').length;
    const never = microsoft.companies.every((item) => item.status === 'never');
    const status = failing > 0 ? (
        <StatusTag tone="amber">{failing === 1 ? '1 con fallas' : `${failing} con fallas`}</StatusTag>
    ) : never ? (
        <StatusTag tone="gray">Sin sincronizar</StatusTag>
    ) : (
        <StatusTag tone="green">Activa</StatusTag>
    );

    return (
        <Card
            title="Microsoft"
            status={status}
            subtitle="Inicio de sesión y colaboradores"
            footer={
                <Button variant="secondary" href={route('admin.integrations.microsoft.test')} method="post" preserveScroll>
                    Probar conexión
                </Button>
            }
        >
            <ul className="flex flex-col gap-2 text-[13px]">
                {microsoft.companies.map((item) => {
                    const name = companyById(item.company_id)?.short_name ?? `Empresa ${item.company_id}`;
                    return (
                        <li
                            key={item.company_id}
                            className={cx(
                                'flex flex-col gap-1 rounded-[10px] px-3 py-2.5',
                                item.status === 'error' ? 'bg-status-failure-bg' : 'bg-ground',
                            )}
                        >
                            <span className="flex justify-between gap-3">
                                <span>
                                    <b>{name}</b>
                                    {item.status === 'ok' && item.synced_count !== null ? ` · sincronizó ${item.synced_count}` : null}
                                    {item.status === 'error' ? ' · falló la última sincronización' : null}
                                </span>
                                <span
                                    className={cx(
                                        'shrink-0 font-semibold',
                                        item.status === 'ok' && 'text-status-active',
                                        item.status === 'error' && 'text-status-failure',
                                        item.status === 'never' && 'text-fg-muted',
                                    )}
                                >
                                    {item.status === 'error' ? 'Falló' : item.last_sync_at ? formatRelative(item.last_sync_at) : 'Nunca'}
                                </span>
                            </span>
                            {item.error ? <span className="leading-snug text-status-failure">{item.error}</span> : null}
                        </li>
                    );
                })}
            </ul>
        </Card>
    );
}

function MailCard({ mail, companyById }: { mail: IntegrationsProps['mail']; companyById: (id: number) => Company | undefined }) {
    const status = {
        ok: <StatusTag tone="green">Activa</StatusTag>,
        pending: <StatusTag tone="gray">Pendiente</StatusTag>,
        error: <StatusTag tone="red">Falla</StatusTag>,
    }[mail.status];
    const transport = mail.transport === 'graph' ? ' por Microsoft Graph' : mail.transport === 'smtp' ? ' por SMTP' : '';

    return (
        <Card
            title="Correo"
            status={status}
            subtitle={`Cada empresa envía desde su buzón${transport}`}
            footer={
                <Button variant="secondary" href={route('admin.integrations.mail.test')} method="post" preserveScroll>
                    Enviarme un correo de prueba
                </Button>
            }
        >
            <ul className="flex flex-col gap-2 text-[13px]">
                {mail.senders.map((sender) => (
                    <li key={sender.company_id} className="flex min-w-0 justify-between gap-3 rounded-[10px] bg-ground px-3 py-2.5">
                        <span className="min-w-0 truncate">{sender.email}</span>
                        <span className="shrink-0 text-fg-muted">{companyById(sender.company_id)?.short_name}</span>
                    </li>
                ))}
            </ul>
            {mail.note ? <p className="text-xs leading-[1.45] text-fg-muted">{mail.note}</p> : null}
        </Card>
    );
}

function TelegramCard({ telegram }: { telegram: IntegrationsProps['telegram'] }) {
    const status = {
        ok: <StatusTag tone="green">Activa</StatusTag>,
        off: <StatusTag tone="gray">Apagada</StatusTag>,
        error: <StatusTag tone="red">Falla</StatusTag>,
    }[telegram.status];
    const bot = telegram.bot_username ? `@${telegram.bot_username}` : '[NOMBRE DEL BOT]';

    return (
        <Card
            title="Telegram"
            status={status}
            subtitle={`Bot ${bot} con webhook`}
            footer={
                <Button
                    variant="secondary"
                    href={route('admin.integrations.telegram.test')}
                    method="post"
                    preserveScroll
                    disabled={telegram.status === 'off'}
                >
                    Enviarme un mensaje de prueba
                </Button>
            }
        >
            <dl className="grid grid-cols-[130px_minmax(0,1fr)] gap-y-2 text-[13px]">
                <dt className="text-fg-muted">Personas vinculadas</dt>
                <dd className="tabular font-semibold">{telegram.linked_users}</dd>
                <dt className="text-fg-muted">Último mensaje</dt>
                <dd>{telegram.last_message_at ? formatRelative(telegram.last_message_at) : 'Ninguno todavía'}</dd>
                <dt className="text-fg-muted">Webhook</dt>
                <dd className={cx('font-semibold', telegram.webhook_ok ? 'text-status-active' : 'text-status-failure')}>
                    {telegram.webhook_ok ? 'Responde' : 'No responde'}
                </dd>
            </dl>
        </Card>
    );
}

function GithubCard({ github }: { github: IntegrationsProps['github'] }) {
    const form = useForm({ organization: github.organization ?? '' });
    const connected = github.status === 'connected';
    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        form.put(route('admin.integrations.github.update'), { preserveScroll: true });
    };

    return (
        <Card
            title="GitHub"
            dashed={!connected}
            status={connected ? <StatusTag tone="green">Conectado</StatusTag> : <StatusTag tone="gray">Sin conectar</StatusTag>}
            subtitle={
                connected
                    ? `${github.repositories_count} ${github.repositories_count === 1 ? 'repositorio' : 'repositorios'} con la app instalada. Crea issues desde las tareas y recibe pull requests y releases.`
                    : 'Para crear issues desde las tareas y recibir pull requests y releases. Se usa desde la etapa D.'
            }
        >
            <form onSubmit={submit} noValidate className="flex flex-1 flex-col gap-3">
                <TextField
                    label="Organización"
                    value={form.data.organization}
                    onChange={(event) => form.setData('organization', event.target.value)}
                    error={form.errors.organization}
                    placeholder="[ORGANIZACIÓN DE GITHUB]"
                    autoCapitalize="off"
                    spellCheck={false}
                />
                <div className="mt-auto flex flex-col gap-2">
                    {form.isDirty ? (
                        <Button type="submit" variant="secondary" loading={form.processing}>
                            Guardar organización
                        </Button>
                    ) : null}
                    {github.install_url ? (
                        <a href={github.install_url} target="_blank" rel="noopener noreferrer" className={buttonClasses({ variant: 'dark' })}>
                            {connected ? 'Cambiar repositorios en GitHub' : 'Instalar la app de GitHub'}
                            <Icon name="external" size={16} />
                            <span className="sr-only">(abre GitHub en otra pestaña)</span>
                        </a>
                    ) : (
                        <>
                            <Button variant="dark" disabled>
                                Instalar la app de GitHub
                            </Button>
                            <span className="text-xs text-fg-muted">Primero guarda la organización.</span>
                        </>
                    )}
                </div>
            </form>
        </Card>
    );
}

function AiCard({ ai }: { ai: IntegrationsProps['ai'] }) {
    const form = useForm({ provider: ai.provider, model: ai.model, monthly_cap_usd: ai.monthly_cap_usd === null ? '' : String(ai.monthly_cap_usd) });
    const status = {
        ok: <StatusTag tone="green">Activa</StatusTag>,
        off: <StatusTag tone="gray">Apagada</StatusTag>,
        capped: <StatusTag tone="red">Tope alcanzado</StatusTag>,
    }[ai.status];
    const providerLabel = ai.provider_options.find((option) => option.value === form.data.provider)?.label ?? form.data.provider;
    const warnPercent = Math.round(ai.warn_ratio * 100);
    const cap = ai.monthly_cap_usd;

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        const raw = form.data.monthly_cap_usd.trim().replace(',', '.');
        if (raw !== '' && !(Number(raw) > 0)) {
            form.setError('monthly_cap_usd', 'Escribe un valor en dólares mayor que cero, o déjalo vacío.');
            return;
        }
        form.transform((values) => ({ ...values, monthly_cap_usd: raw === '' ? null : Number(raw) }));
        form.put(route('admin.integrations.ai.update'), { preserveScroll: true });
    };

    return (
        <section
            aria-labelledby="integracion-ia"
            className="grid grid-cols-1 gap-5 rounded-card border border-line bg-surface p-[18px] md:col-span-2 lg:grid-cols-2"
        >
            <form onSubmit={submit} noValidate className="flex flex-col gap-3">
                <div className="flex items-center justify-between gap-3">
                    <h2 id="integracion-ia" className="font-display text-lg font-bold">
                        Proveedor de IA
                    </h2>
                    {status}
                </div>
                <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <Select
                        label="Proveedor"
                        value={form.data.provider}
                        onChange={(event) => form.setData('provider', event.target.value)}
                        options={ai.provider_options}
                        error={form.errors.provider}
                    />
                    <Select
                        label="Modelo"
                        value={form.data.model}
                        onChange={(event) => form.setData('model', event.target.value)}
                        options={ai.model_options}
                        error={form.errors.model}
                    />
                    <TextField
                        label="Tope mensual (US$)"
                        inputMode="decimal"
                        value={form.data.monthly_cap_usd}
                        onChange={(event) => {
                            form.setData('monthly_cap_usd', event.target.value);
                            form.clearErrors('monthly_cap_usd');
                        }}
                        error={form.errors.monthly_cap_usd}
                        placeholder="[TOPE]"
                        hint="Vacío = sin tope."
                        className="tabular"
                    />
                    <div className="flex items-start sm:pt-[26px]">
                        <Button type="submit" variant="secondary" fullWidth disabled={!form.isDirty} loading={form.processing}>
                            Guardar
                        </Button>
                    </div>
                </div>
                <p className="text-xs leading-[1.45] text-fg-muted">
                    {providerLabel} procesa los datos en sus servidores. Al modelo nunca llegan contraseñas, la bóveda, tarifas ni datos de
                    clientes.
                </p>
            </form>

            <div className="flex flex-col gap-2.5 border-t border-line pt-5 lg:border-t-0 lg:border-l lg:pt-0 lg:pl-5">
                <h3 className="font-sans text-[13px] font-bold tracking-normal">Gasto de {monthName.format(new Date())}</h3>
                <p className="tabular font-display text-[28px] leading-tight font-bold">
                    {money(ai.month_spend_usd)}{' '}
                    <span className="font-sans text-sm font-medium text-fg-muted">{cap === null ? 'sin tope mensual' : `de ${money(cap)} al mes`}</span>
                </p>
                {cap !== null ? (
                    <BudgetBar
                        label="Gasto del mes"
                        value={ai.month_spend_usd}
                        max={cap}
                        valueText={`${money(ai.month_spend_usd)} de ${money(cap)}`}
                        message={
                            ai.status === 'capped'
                                ? 'Llegó al tope: el Asistente y las sugerencias están apagados hasta el próximo mes o hasta subir el tope.'
                                : undefined
                        }
                    />
                ) : null}
                <p className="text-xs leading-[1.45] text-fg-muted">
                    {cap === null
                        ? 'Sin tope, el gasto no se corta. Pon uno para recibir aviso y que se apague solo.'
                        : `Aviso al ${warnPercent} %. Al 100 % se apagan el Asistente y las sugerencias; el resto sigue funcionando.`}
                </p>
            </div>
        </section>
    );
}

/** Integraciones: Microsoft, correo, Telegram, GitHub and the AI provider. No secrets are typed here. */
export default function AdminIntegrationsIndex(props: PageProps<AdminIntegrationsIndexProps>) {
    const { microsoft, mail, telegram, github, ai, companies } = props;
    const companyById = (id: number) => companies.find((item) => item.id === id);

    return (
        <AdminLayout shared={props} title="Integraciones · Administración" tab="integrations">
            <div className="flex flex-col gap-3.5">
                <AdminPageHeader
                    title="Integraciones"
                    description="Las llaves y contraseñas nunca se escriben aquí: van en el archivo .env del servidor."
                />
                <div className="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
                    <MicrosoftCard microsoft={microsoft} companyById={companyById} />
                    <MailCard mail={mail} companyById={companyById} />
                    <TelegramCard telegram={telegram} />
                    <GithubCard github={github} />
                    <AiCard ai={ai} />
                </div>
            </div>
        </AdminLayout>
    );
}
