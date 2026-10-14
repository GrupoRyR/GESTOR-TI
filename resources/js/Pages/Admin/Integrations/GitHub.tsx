import { Link, router } from '@inertiajs/react';
import { useId, useRef, useState, type ReactNode } from 'react';
import { AdminPageHeader } from '@/Components/admin/AdminParts';
import { ExternalLink } from '@/Components/github/ExternalLink';
import { Button } from '@/Components/ui/Button';
import { Chip } from '@/Components/ui/Chip';
import { ConfirmDialog } from '@/Components/ui/Dialog';
import { Icon } from '@/Components/ui/Icon';
import { NoPermission } from '@/Components/ui/NoPermission';
import { StatTile } from '@/Components/ui/StatTile';
import { cx } from '@/Components/ui/utils';
import { AdminLayout } from '@/Layouts/AdminLayout';
import { can } from '@/lib/can';
import { formatRelative, formatShortDate, formatTime } from '@/lib/format';
import { permissionsSummary, webhookEventLabel } from '@/lib/githubAdmin';
import { route } from '@/lib/route';
import type { GitHubConnection, GitHubRepo } from '@/types/github';
import type { AdminIntegrationsGitHubProps, FailedWebhookEvent } from '@/types/pages/admin';
import type { PageProps } from '@/types/shared';

function Card({ title, aside, children, className }: { title: string; aside?: ReactNode; children: ReactNode; className?: string }) {
    const id = useId();
    return (
        <section aria-labelledby={id} className={cx('flex min-w-0 flex-col gap-4 rounded-card border border-line bg-surface p-[18px] md:p-5', className)}>
            <div className="flex flex-wrap items-center justify-between gap-x-3 gap-y-1">
                <h2 id={id} className="font-display text-lg leading-tight font-bold">
                    {title}
                </h2>
                {aside}
            </div>
            {children}
        </section>
    );
}

function Breadcrumb() {
    return (
        <nav aria-label="Ruta" className="-mb-1 flex items-center gap-1.5 text-[13px] text-fg-muted">
            <Link href={route('admin.integrations.index')} className="inline-flex min-h-11 items-center font-semibold text-fg-soft underline-offset-[3px] hover:text-fg hover:underline">
                Integraciones
            </Link>
            <Icon name="chevron-right" size={14} />
            <span aria-current="page" className="font-semibold text-fg">
                GitHub
            </span>
        </nav>
    );
}

function ConnectionCard({ connection, repos }: { connection: GitHubConnection; repos: GitHubRepo[] }) {
    const rows: { term: string; value: ReactNode }[] = [
        { term: 'Tipo', value: connection.kind === 'app' ? 'App de GitHub de la cuenta del grupo' : 'Token de acceso limitado a los repositorios del grupo' },
        { term: 'Nombre de la app', value: connection.app_name },
        { term: 'Cuenta de GitHub', value: connection.organization },
        { term: 'Permisos', value: permissionsSummary(connection.permissions) },
        {
            term: 'Instalada en',
            value: `${connection.installed_repos_count} ${connection.installed_repos_count === 1 ? 'repositorio' : 'repositorios'}`,
        },
    ];

    return (
        <Card
            title="Conexión"
            aside={
                <ExternalLink href={connection.manage_url} className="text-[13px]" srHint="se abre en otra pestaña">
                    Cambiar repositorios en GitHub
                </ExternalLink>
            }
        >
            <dl className="grid grid-cols-1 gap-x-4 gap-y-2.5 text-sm sm:grid-cols-[150px_minmax(0,1fr)]">
                {rows.map((row) => (
                    <div key={row.term} className="contents">
                        <dt className="text-[13px] text-fg-muted sm:pt-px">{row.term}</dt>
                        <dd className="-mt-2 font-semibold sm:mt-0">{row.value}</dd>
                    </div>
                ))}
            </dl>
            {repos.length > 0 ? (
                <ul aria-label="Repositorios con la app instalada" className="flex flex-wrap gap-1.5">
                    {repos.map((repo) => (
                        <li
                            key={repo.full_name}
                            className={cx(
                                'inline-flex h-8 items-center gap-1.5 rounded-chip px-2.5 text-[13px] font-semibold',
                                repo.linked_to ? 'bg-subtle text-fg-soft' : 'border border-dashed border-line-strong text-fg-muted',
                            )}
                        >
                            <Icon name="code" size={13} />
                            {repo.name}
                            {repo.linked_to ? null : <span className="font-medium"> · sin vincular</span>}
                        </li>
                    ))}
                </ul>
            ) : null}
            <p className="flex items-start gap-2 rounded-control bg-ground px-3.5 py-2.5 text-[13px] leading-normal text-fg-soft">
                <Icon name="key" size={15} className="mt-0.5 shrink-0" />
                <span>
                    La llave privada de la app vive en el archivo .env del servidor. Aquí solo se ve si está configurada:{' '}
                    <b className="text-fg">{connection.private_key_configured ? 'configurada' : 'no configurada'}</b>.
                </span>
            </p>
        </Card>
    );
}

type CopyState = 'idle' | 'copied' | 'failed';

function WebhookCard({ webhook, now }: { webhook: GitHubConnection['webhook']; now: Date }) {
    const [copy, setCopy] = useState<CopyState>('idle');
    const timer = useRef<number | undefined>(undefined);
    const inputId = useId();

    const copyUrl = async () => {
        try {
            await navigator.clipboard.writeText(webhook.url);
            setCopy('copied');
        } catch {
            setCopy('failed');
        }
        window.clearTimeout(timer.current);
        timer.current = window.setTimeout(() => setCopy('idle'), 2500);
    };

    return (
        <Card
            title="Webhook"
            aside={
                <span className="text-[13px] text-fg-muted">
                    {webhook.last_delivery_at ? `Última entrega ${formatRelative(webhook.last_delivery_at, now)}` : 'Aún no llega ninguna entrega'}
                </span>
            }
        >
            <div className="flex flex-col gap-1.5">
                <label htmlFor={inputId} className="text-[13px] font-semibold">
                    Dirección que llama GitHub
                </label>
                <div className="flex gap-2">
                    <input
                        id={inputId}
                        type="text"
                        readOnly
                        value={webhook.url}
                        onFocus={(event) => event.currentTarget.select()}
                        className="h-11 min-w-0 flex-1 rounded-control border border-line-strong bg-ground px-3.5 font-mono text-[13px]"
                    />
                    <button
                        type="button"
                        onClick={copyUrl}
                        aria-label="Copiar dirección del webhook"
                        title="Copiar"
                        className="flex size-11 shrink-0 items-center justify-center rounded-control border border-line-strong bg-surface text-fg hover:bg-subtle"
                    >
                        <Icon name={copy === 'copied' ? 'check' : 'copy'} size={18} />
                    </button>
                </div>
                <p aria-live="polite" className="min-h-[18px] text-xs font-semibold text-fg-muted">
                    {copy === 'copied' ? 'Copiado' : copy === 'failed' ? 'No se pudo copiar. Selecciona el texto y cópialo.' : ''}
                </p>
            </div>

            <div className="flex flex-wrap items-center gap-x-3 gap-y-1.5">
                <span className="text-[13px] font-semibold">Secreto</span>
                {webhook.configured ? (
                    <Chip tone="green" icon="check-circle" size="sm">
                        Configurado
                    </Chip>
                ) : (
                    <Chip tone="amber" icon="alert-triangle" size="sm">
                        No configurado
                    </Chip>
                )}
                <span className="text-[13px] text-fg-muted">
                    {webhook.configured ? 'Cada llamada se verifica con su firma' : 'Sin secreto, las llamadas se rechazan'}
                </span>
            </div>

            <div className="flex flex-col gap-2">
                <span className="text-[13px] font-semibold">Eventos suscritos</span>
                <ul className="flex flex-wrap gap-1.5">
                    {webhook.events.map((event) => (
                        <li key={event} className="inline-flex h-8 items-center rounded-chip bg-subtle px-2.5 font-mono text-xs font-semibold text-fg-soft">
                            {webhookEventLabel(event)}
                        </li>
                    ))}
                </ul>
            </div>
        </Card>
    );
}

function eventWhen(iso: string, now: Date): string {
    const relative = formatRelative(iso, now);
    if (relative.startsWith('hoy')) return `Hoy ${formatTime(iso)}`;
    if (relative.startsWith('ayer')) return `Ayer ${formatTime(iso)}`;
    return `${formatShortDate(iso)}, ${formatTime(iso)}`;
}

function FailedEvents({ events, now }: { events: FailedWebhookEvent[]; now: Date }) {
    const [retrying, setRetrying] = useState<number | null>(null);
    const retry = (event: FailedWebhookEvent) =>
        router.post(
            route('admin.integrations.github.events.retry', { event: event.id }),
            {},
            { preserveScroll: true, onStart: () => setRetrying(event.id), onFinish: () => setRetrying(null) },
        );

    if (events.length === 0) {
        return <p className="rounded-control bg-ground px-4 py-3 text-[13px] text-fg-muted">Ningún evento falló este mes.</p>;
    }

    const fix = (event: FailedWebhookEvent) =>
        event.fix_product_slug ? (
            <Link
                href={route('ai.products.show', { product: event.fix_product_slug, vincular: 1 })}
                className="font-bold text-fg underline underline-offset-[3px]"
            >
                Vincularlo
            </Link>
        ) : null;

    return (
        <div className="flex flex-col gap-2">
            <h3 className="text-sm font-bold">Eventos fallidos</h3>
            {/* Phone: one card per event. */}
            <ul className="flex flex-col gap-2 md:hidden">
                {events.map((event) => (
                    <li key={event.id} className="flex flex-col gap-1.5 rounded-control border border-line px-3.5 py-3 text-[13px]">
                        <span className="flex flex-wrap items-center justify-between gap-2">
                            <code className="font-mono text-xs font-bold">{event.type}</code>
                            <span className="text-fg-muted">{eventWhen(event.received_at, now)}</span>
                        </span>
                        <span className="break-all text-fg-soft">{event.repo}</span>
                        <span className="flex items-start gap-1.5 font-semibold text-status-failure">
                            <Icon name="x-circle" size={14} className="mt-0.5 shrink-0" />
                            <span>
                                {event.error} {fix(event)}
                            </span>
                        </span>
                        <Button variant="secondary" icon="refresh" onClick={() => retry(event)} loading={retrying === event.id} className="self-start">
                            Reprocesar
                        </Button>
                    </li>
                ))}
            </ul>
            {/* Tablet and desktop: table. */}
            <div className="hidden overflow-hidden rounded-control border border-line md:block">
                <table className="w-full text-left text-[13px]">
                    <thead className="bg-ground text-xs text-fg-muted">
                        <tr>
                            <th scope="col" className="px-4 py-2.5 font-semibold">
                                Evento
                            </th>
                            <th scope="col" className="px-4 py-2.5 font-semibold">
                                Repositorio
                            </th>
                            <th scope="col" className="px-4 py-2.5 font-semibold">
                                Cuándo
                            </th>
                            <th scope="col" className="px-4 py-2.5 font-semibold">
                                Error
                            </th>
                            <th scope="col" className="px-4 py-2.5 text-right font-semibold">
                                <span className="sr-only">Acción</span>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        {events.map((event) => (
                            <tr key={event.id} className="border-t border-line align-middle">
                                <td className="px-4 py-2">
                                    <code className="font-mono text-xs font-bold">{event.type}</code>
                                </td>
                                <td className="px-4 py-2 break-all text-fg-soft">{event.repo}</td>
                                <td className="tabular px-4 py-2 whitespace-nowrap">{eventWhen(event.received_at, now)}</td>
                                <td className="px-4 py-2">
                                    <span className="flex items-start gap-1.5 font-semibold text-status-failure">
                                        <Icon name="x-circle" size={14} className="mt-0.5 shrink-0" />
                                        <span>
                                            {event.error} {fix(event)}
                                        </span>
                                    </span>
                                </td>
                                <td className="px-4 py-2 text-right">
                                    <Button variant="secondary" icon="refresh" onClick={() => retry(event)} loading={retrying === event.id}>
                                        Reprocesar
                                        <span className="sr-only"> el evento {event.type} de {event.repo}</span>
                                    </Button>
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </div>
    );
}

const PLACES = [
    { label: 'Título', example: 'GIA-123 · arreglar filtro' },
    { label: 'Descripción', example: 'Cierra GIA-123' },
    { label: 'Nombre de la rama', example: 'gia-123-arreglar-filtro' },
];

const OUTCOMES = [
    { icon: 'pull-request', when: 'PR abierto', then: 'Pasa a En revisión' },
    { icon: 'merge', when: 'PR fusionado', then: 'Pasa a Hecho' },
    { icon: 'rocket', when: 'Release', then: 'Crea versión y avisa' },
] as const;

function HowItLinks() {
    return (
        <Card title="Cómo se enlaza el trabajo">
            <p className="max-w-[72ch] text-sm leading-normal text-fg-soft">
                El Gestor busca la llave de la solicitud, como GIA-123, en tres lugares. Si la encuentra, enlaza el evento sin que nadie haga nada.
            </p>
            <div className="grid gap-4 lg:grid-cols-2">
                <dl className="flex flex-col gap-2">
                    {PLACES.map((place) => (
                        <div key={place.label} className="flex flex-wrap items-center gap-x-3 gap-y-1 rounded-control bg-ground px-3.5 py-2.5">
                            <dt className="min-w-[132px] text-[13px] font-semibold text-fg-soft">{place.label}</dt>
                            <dd>
                                <code className="font-mono text-[13px] break-all text-fg">{place.example}</code>
                            </dd>
                        </div>
                    ))}
                </dl>
                <ul className="flex flex-col gap-2">
                    {OUTCOMES.map((outcome) => (
                        <li key={outcome.when} className="flex min-h-11 items-center gap-3 rounded-control border border-line px-3.5 py-2">
                            <Icon name={outcome.icon} size={16} className="shrink-0 text-fg-soft" />
                            <span className="min-w-0 flex-1 text-[13px] font-semibold">{outcome.when}</span>
                            <span className="text-[13px] text-fg-soft">{outcome.then}</span>
                        </li>
                    ))}
                </ul>
            </div>
        </Card>
    );
}

function DangerZone() {
    const [confirming, setConfirming] = useState(false);
    const [processing, setProcessing] = useState(false);
    const disconnect = () =>
        router.delete(route('admin.integrations.github.disconnect'), {
            preserveScroll: true,
            onStart: () => setProcessing(true),
            onFinish: () => {
                setProcessing(false);
                setConfirming(false);
            },
        });

    return (
        <section aria-labelledby="github-danger" className="flex flex-col gap-3 rounded-card border border-status-failure-line p-[18px] md:flex-row md:items-center md:p-5">
            <div className="flex min-w-0 flex-1 flex-col gap-1">
                <h2 id="github-danger" className="font-display text-lg leading-tight font-bold">
                    Desconectar GitHub
                </h2>
                <p className="max-w-[72ch] text-[13px] leading-normal text-fg-soft">
                    Dejan de llegar eventos y las tareas ya no crean issues. Lo recibido hasta hoy y los enlaces existentes se conservan.
                </p>
            </div>
            <Button variant="danger" aria-haspopup="dialog" onClick={() => setConfirming(true)} className="self-start md:self-center">
                Desconectar GitHub
            </Button>
            <ConfirmDialog
                open={confirming}
                onClose={() => setConfirming(false)}
                onConfirm={disconnect}
                processing={processing}
                title="¿Desconectar GitHub?"
                description="Dejan de llegar pull requests, CI y releases, y no se podrán crear issues desde las tareas. Para volver a conectar hay que instalar la app otra vez en GitHub."
                confirmLabel="Desconectar"
            />
        </section>
    );
}

function NotConnected({ installUrl, organization }: { installUrl: string | null; organization: string }) {
    return (
        <section
            aria-labelledby="github-connect"
            className="flex flex-col gap-4 rounded-card border-[1.5px] border-dashed border-line-strong bg-surface p-[18px] md:flex-row md:items-center md:p-6"
        >
            <span className="flex size-12 shrink-0 items-center justify-center rounded-icon bg-subtle text-fg-soft">
                <Icon name="code" size={22} />
            </span>
            <div className="flex min-w-0 flex-1 flex-col gap-1">
                <h2 id="github-connect" className="font-display text-xl leading-tight font-bold">
                    GitHub no está conectado
                </h2>
                <p className="max-w-[64ch] text-sm leading-normal text-fg-soft">
                    Instala la app “Gestor TI RYR” en {organization}. Con ella las tareas crean issues y llegan los pull requests, el CI y los releases de cada
                    producto. La app solo lee el código; nunca cambia archivos.
                </p>
            </div>
            <div className="flex flex-col gap-1.5 md:items-end">
                {installUrl ? (
                    <ExternalLink href={installUrl} variant="button" buttonVariant="dark" srHint="se abre GitHub en otra pestaña">
                        Conectar GitHub
                    </ExternalLink>
                ) : (
                    <>
                        <Button variant="dark" disabled>
                            Conectar GitHub
                        </Button>
                        <span className="text-xs text-fg-muted">
                            Primero guarda la cuenta de GitHub en{' '}
                            <Link href={route('admin.integrations.index')} className="font-bold text-fg-soft underline underline-offset-[3px]">
                                Integraciones
                            </Link>
                            .
                        </span>
                    </>
                )}
            </div>
        </section>
    );
}

/** D-GitHubSetup: Administración › Integraciones › GitHub. No secret is ever shown, only whether it is configured. */
export default function AdminIntegrationsGitHub(props: PageProps<AdminIntegrationsGitHubProps>) {
    const { connection, install_url, repos, stats, failed_events, auth } = props;
    const now = new Date(props.now);
    const allowed = can(auth.user, 'ai.github.manage');

    return (
        <AdminLayout shared={props} title="GitHub · Integraciones · Administración" tab="integrations">
            <div className="flex flex-col gap-4">
                <Breadcrumb />
                {!allowed ? (
                    <NoPermission
                        title="No tienes acceso a la configuración de GitHub"
                        description="Solo quien tiene el permiso Administrar GitHub ve esta página. Si lo necesitas, pídeselo a un Súper Admin."
                    />
                ) : (
                    <>
                        <AdminPageHeader
                            title={
                                <span className="flex flex-wrap items-center gap-2.5">
                                    GitHub
                                    {connection.connected ? (
                                        <Chip tone="green" icon="check-circle" size="sm">
                                            Conectada
                                        </Chip>
                                    ) : (
                                        <Chip tone="gray" icon="minus-circle" size="sm">
                                            Sin conectar
                                        </Chip>
                                    )}
                                </span>
                            }
                            description={
                                <span className="inline-flex items-center gap-1.5">
                                    <Icon name="shield" size={13} />
                                    Solo quien tiene el permiso Administrar GitHub ve esta página
                                </span>
                            }
                        />

                        {connection.connected ? (
                            <>
                                <div className="grid gap-4 lg:grid-cols-2 lg:items-start">
                                    <ConnectionCard connection={connection} repos={repos} />
                                    <WebhookCard webhook={connection.webhook} now={now} />
                                </div>

                                <Card title={`Actividad de ${stats.month}`}>
                                    <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                                        <StatTile label="Eventos recibidos" value={String(stats.received)} note="este mes" />
                                        <StatTile
                                            label="Procesados"
                                            value={String(stats.processed)}
                                            note={stats.failed === 0 ? 'por la cola, sin errores' : 'por la cola'}
                                        />
                                        <StatTile
                                            label="Fallidos"
                                            value={String(stats.failed)}
                                            note={
                                                stats.failed > 0 ? (
                                                    <span className="inline-flex items-center gap-1 font-semibold text-status-failure">
                                                        <Icon name="alert-triangle" size={13} />
                                                        {stats.failed === 1 ? 'se puede reprocesar' : 'se pueden reprocesar'}
                                                    </span>
                                                ) : (
                                                    'ninguno'
                                                )
                                            }
                                        />
                                    </div>
                                    <FailedEvents events={failed_events} now={now} />
                                </Card>

                                <HowItLinks />
                                <DangerZone />
                            </>
                        ) : (
                            <>
                                <NotConnected installUrl={install_url} organization={connection.organization} />
                                <HowItLinks />
                            </>
                        )}
                    </>
                )}
            </div>
        </AdminLayout>
    );
}
