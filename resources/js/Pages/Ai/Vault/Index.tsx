import { Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { VaultZone } from '@/Components/ai/VaultZone';
import { useRevealedSecret } from '@/Components/ai/vault/useRevealedSecret';
import {
    CLIPBOARD_SECONDS,
    REVEAL_SECONDS,
    rotationState,
    rotationText,
    sessionLeft,
    VAULT_ACTIONS,
    VAULT_ENV_LABELS,
    VAULT_TYPE_LABELS,
    vaultAlerts,
} from '@/Components/ai/vault/vault';
import { Button, buttonClasses } from '@/Components/ui/Button';
import { CountdownRing } from '@/Components/ui/CountdownRing';
import { Icon } from '@/Components/ui/Icon';
import { SegmentedControl } from '@/Components/ui/SegmentedControl';
import { cx } from '@/Components/ui/utils';
import { GestorLayout } from '@/Layouts/GestorLayout';
import { formatCountdown, countdownSpoken } from '@/lib/countdown';
import { formatRelative, formatShortDate, formatTime } from '@/lib/format';
import { route } from '@/lib/route';
import { useClock } from '@/lib/useClock';
import type { VaultAccessLog, VaultEnvironment, VaultItem } from '@/types/ai';
import type { VaultIndexProps } from '@/types/pages/ai-value-vault';
import type { PageProps } from '@/types/shared';

type EnvFilter = VaultEnvironment | 'all';

function EnvTag({ environment }: { environment: VaultEnvironment }) {
    return (
        <span
            className={cx(
                'inline-flex h-6 items-center rounded-[8px] px-2 text-xs font-semibold',
                environment === 'production' ? 'bg-fg text-on-fg' : 'text-fg-soft ring-1 ring-line-strong ring-inset',
            )}
        >
            {VAULT_ENV_LABELS[environment]}
        </span>
    );
}

function DueText({ item, today }: { item: VaultItem; today: string }) {
    const state = rotationState(item, today);
    return (
        <span
            className={cx(
                state.level === 'soon' || state.level === 'expired' ? 'font-bold text-status-maintenance' : state.level === 'pending' ? 'text-fg-muted italic' : 'text-fg-soft',
            )}
        >
            {state.level === 'expired' ? <span className="sr-only">Vencida: </span> : null}
            {state.date}
        </span>
    );
}

function Alerts({ items, today }: { items: VaultItem[]; today: string }) {
    const alerts = vaultAlerts(items, today);
    if (alerts.length === 0) return null;
    return (
        <ul className="flex flex-col gap-2">
            {alerts.map(({ item, state }) => (
                <li key={item.id} className="flex flex-wrap items-start gap-3 rounded-control bg-status-maintenance-bg p-3.5 text-sm text-fg">
                    <Icon name="alert-triangle" size={18} className="mt-0.5 shrink-0 text-status-maintenance" />
                    <span className="min-w-0 flex-1 leading-snug">
                        <b className="text-status-maintenance">
                            {item.name}: {state.sentence.toLowerCase()}
                        </b>{' '}
                        ({state.date}). {item.product}, en {VAULT_ENV_LABELS[item.environment].toLowerCase()}.
                    </span>
                    <Link
                        href={route('ai.vault.index', { item: item.id })}
                        only={['selected', 'access_log', 'revealed']}
                        preserveScroll
                        preserveState
                        className="flex min-h-11 items-center text-[13px] font-bold text-status-maintenance underline underline-offset-2"
                    >
                        Ver cómo rotarla
                    </Link>
                </li>
            ))}
        </ul>
    );
}

function LockedList({ items, today, env, setEnv }: { items: VaultItem[]; today: string; env: EnvFilter; setEnv: (env: EnvFilter) => void }) {
    const visible = items.filter((item) => env === 'all' || item.environment === env);
    const products = new Set(items.map((item) => item.product)).size;
    const soon = vaultAlerts(items, today).length;
    return (
        <div className="flex flex-col gap-3">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <span className="text-[13px] text-fg-soft">
                    {items.length} credenciales en {products} productos{soon > 0 ? ` · ${soon} por vencer` : ''}
                </span>
                <SegmentedControl<EnvFilter>
                    label="Ambiente"
                    size="sm"
                    value={env}
                    onChange={setEnv}
                    options={[
                        { value: 'all', label: 'Todas' },
                        { value: 'production', label: 'Producción' },
                        { value: 'testing', label: 'Pruebas' },
                    ]}
                />
            </div>

            <div className="hidden overflow-x-auto rounded-[12px] border border-line md:block">
                <table className="w-full min-w-[880px] border-collapse text-sm">
                    <caption className="sr-only">Credenciales, sin valores</caption>
                    <thead>
                        <tr className="bg-surface text-left text-xs text-fg-muted">
                            {['Nombre', 'Proyecto o producto', 'Ambiente', 'Tipo', 'Vence', 'Rotación', 'Valor'].map((header) => (
                                <th key={header} scope="col" className="px-4 py-2.5 font-bold">
                                    {header}
                                </th>
                            ))}
                        </tr>
                    </thead>
                    <tbody>
                        {visible.map((item) => (
                            <tr key={item.id} className="border-t border-line">
                                <th scope="row" className="px-4 py-3 text-left font-bold">
                                    {item.name}
                                </th>
                                <td className="px-4 py-3 text-fg-soft">{item.product}</td>
                                <td className="px-4 py-3">
                                    <EnvTag environment={item.environment} />
                                </td>
                                <td className="px-4 py-3 text-fg-soft">{VAULT_TYPE_LABELS[item.type]}</td>
                                <td className="px-4 py-3">
                                    <DueText item={item} today={today} />
                                </td>
                                <td className="px-4 py-3 text-fg-soft">{rotationText(item.rotation_days)}</td>
                                <td className="px-4 py-3">
                                    <span className="inline-flex items-center gap-1.5 text-fg-muted">
                                        <Icon name="eye-off" size={14} />
                                        Oculto
                                    </span>
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>

            <ul className="flex flex-col overflow-hidden rounded-[12px] border border-line md:hidden">
                {visible.map((item, index) => (
                    <li key={item.id} className={cx('flex flex-col gap-1.5 px-4 py-3', index > 0 && 'border-t border-line')}>
                        <span className="flex items-center justify-between gap-2">
                            <b className="min-w-0 truncate text-sm">{item.name}</b>
                            <EnvTag environment={item.environment} />
                        </span>
                        <span className="text-[13px] text-fg-soft">
                            {item.product} · {VAULT_TYPE_LABELS[item.type]}
                        </span>
                        <span className="flex flex-wrap gap-x-3 text-[13px]">
                            <span>
                                Vence: <DueText item={item} today={today} />
                            </span>
                            <span className="text-fg-muted">{rotationText(item.rotation_days)}</span>
                        </span>
                    </li>
                ))}
            </ul>
        </div>
    );
}

function AccessLog({ log, now }: { log: VaultAccessLog[]; now: Date }) {
    return (
        <section aria-labelledby="vault-log" className="flex flex-col gap-2">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <h3 id="vault-log" className="text-sm font-bold">
                    Historial de accesos
                </h3>
                <span className="flex items-center gap-1.5 text-xs text-fg-muted">
                    <Icon name="telegram" size={13} />
                    Cada acceso avisa por Telegram
                </span>
            </div>
            {log.length === 0 ? (
                <p className="text-[13px] text-fg-muted">Nadie ha abierto esta credencial todavía.</p>
            ) : (
                <div className="overflow-x-auto rounded-[12px] border border-line">
                    <table className="w-full min-w-[620px] border-collapse text-[13px]">
                        <caption className="sr-only">Historial de accesos a la credencial</caption>
                        <thead>
                            <tr className="bg-surface text-left text-xs text-fg-muted">
                                {['Quién', 'Qué hizo', 'Cuándo', 'Desde'].map((header) => (
                                    <th key={header} scope="col" className="px-4 py-2 font-bold">
                                        {header}
                                    </th>
                                ))}
                            </tr>
                        </thead>
                        <tbody>
                            {log.map((entry) => {
                                const blocked = entry.action === 'blocked';
                                const action = VAULT_ACTIONS[entry.action];
                                const relative = formatRelative(entry.at, now);
                                const when = relative.startsWith('hoy') || relative.startsWith('ayer') ? relative : formatShortDate(entry.at);
                                return (
                                    <tr key={entry.id} className="border-t border-line">
                                        <td className={cx('px-4 py-2.5', blocked ? 'font-bold text-status-failure' : 'font-semibold')}>{entry.person.name}</td>
                                        <td className={cx('px-4 py-2.5', blocked && 'text-status-failure')}>
                                            <span className="inline-flex items-center gap-1.5 font-semibold">
                                                <Icon name={action.icon} size={14} />
                                                {action.label}
                                            </span>
                                        </td>
                                        <td className="px-4 py-2.5 text-fg-soft first-letter:uppercase">{when}</td>
                                        <td className="px-4 py-2.5 text-fg-soft">{entry.from}</td>
                                    </tr>
                                );
                            })}
                        </tbody>
                    </table>
                </div>
            )}
        </section>
    );
}

/** B-VaultLocked / B-VaultOpen / B-VaultPhone: credentials by product, values only after identity confirmation. */
export default function VaultIndex(props: PageProps<VaultIndexProps>) {
    const { session, items, selected, revealed, access_log, telegram_alerts, holder_name, today, now } = props;
    const [env, setEnv] = useState<EnvFilter>('all');
    const [query, setQuery] = useState('');
    const [copy, setCopy] = useState<'idle' | 'copied' | 'failed'>('idle');
    const sessionClock = useClock({ startAt: now, running: session.state === 'open' });
    const left = sessionLeft(session, sessionClock);
    const open = session.state === 'open' && left > 0;
    const expired = session.state === 'open' && left === 0;
    const { shown, secondsLeft, hide } = useRevealedSecret(revealed, open);
    const value = shown && selected && shown.itemId === selected.id ? shown : null;

    const reveal = (item: VaultItem) => {
        setCopy('idle');
        router.post(route('ai.vault.reveal', { item: item.id }), {}, { only: ['revealed', 'access_log'], preserveScroll: true, preserveState: true });
    };

    const copyValue = async (item: VaultItem) => {
        if (!value) return;
        try {
            await navigator.clipboard.writeText(value.value);
            setCopy('copied');
            router.post(route('ai.vault.copied', { item: item.id }), {}, { only: ['access_log'], preserveScroll: true, preserveState: true });
            // Best effort: browsers may refuse to write the clipboard when the tab is not focused.
            window.setTimeout(() => {
                navigator.clipboard.writeText('').catch(() => undefined);
            }, CLIPBOARD_SECONDS * 1000);
        } catch {
            setCopy('failed');
        }
    };

    const itemHref = (item: VaultItem) => route('ai.vault.index', { item: item.id });
    const filtered = items.filter((item) => `${item.name} ${item.product}`.toLowerCase().includes(query.trim().toLowerCase()));

    if (!open) {
        return (
            <GestorLayout shared={props} title="Bóveda · Gestor IA" tab="vault" mode="it">
                <VaultZone
                    titleAs="h1"
                    title="Bóveda"
                    description={`Solo ${holder_name} puede entrar. Aquí ves qué credenciales hay; los valores se abren después de confirmar tu identidad.`}
                    actions={
                        <div className="flex flex-col items-start gap-1.5 sm:items-end">
                            <a href={route('ai.vault.reauth', selected ? { item: selected.id } : undefined)} className={buttonClasses({ variant: 'vault', size: 'lg' })}>
                                <Icon name="shield" size={18} />
                                Confirmar identidad con Microsoft
                            </a>
                            <span className="flex items-center gap-1.5 text-xs text-fg-muted">
                                <Icon name="clock" size={12} />
                                Se cierra sola a los 10 minutos sin uso
                            </span>
                        </div>
                    }
                >
                    {expired ? (
                        <p role="status" className="flex items-center gap-2 rounded-control bg-subtle p-3 text-sm text-fg-soft">
                            <Icon name="lock" size={16} />
                            La bóveda se cerró por inactividad. Confirma tu identidad otra vez para ver valores.
                        </p>
                    ) : null}
                    <Alerts items={items} today={today} />
                    <LockedList items={items} today={today} env={env} setEnv={setEnv} />
                    {telegram_alerts ? (
                        <p className="flex items-start gap-2 text-xs text-fg-muted">
                            <Icon name="telegram" size={14} className="mt-px shrink-0" />
                            Permiso ai.vault.access · cada vez que alguien entra, ve o copia un valor, {holder_name.split(' ')[0]} recibe un aviso por Telegram.
                        </p>
                    ) : null}
                </VaultZone>
            </GestorLayout>
        );
    }

    const state = selected ? rotationState(selected, today) : null;

    return (
        <GestorLayout shared={props} title="Bóveda abierta · Gestor IA" tab="vault" mode="it">
            <VaultZone
                    titleAs="h1"
                title="Bóveda abierta"
                description={`Identidad confirmada con Microsoft hoy ${formatTime(session.confirmed_at)}`}
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
                        <Button variant="secondary" href={route('ai.vault.lock')} method="post">
                            Cerrar bóveda
                        </Button>
                    </>
                }
            >
                <div className="grid gap-4 lg:grid-cols-[280px_minmax(0,1fr)]">
                    <nav aria-label="Credenciales" className="order-2 flex min-w-0 flex-col gap-2 lg:order-1">
                        <Button variant="secondary" icon="plus" href={route('ai.vault.create')}>
                            Nueva credencial
                        </Button>
                        <label className="flex h-11 items-center gap-2 rounded-control bg-surface px-3 text-sm ring-1 ring-line ring-inset has-focus-visible:outline-2 has-focus-visible:outline-fg">
                            <Icon name="search" size={16} className="text-fg-muted" />
                            <input
                                type="search"
                                value={query}
                                onChange={(event) => setQuery(event.target.value)}
                                placeholder="Buscar credencial"
                                aria-label="Buscar credencial"
                                className="min-w-0 flex-1 bg-transparent text-fg outline-none placeholder:text-fg-muted"
                            />
                        </label>
                        <ul className="flex flex-col gap-0.5">
                            {filtered.map((item) => {
                                const current = selected?.id === item.id;
                                const warn = ['soon', 'expired'].includes(rotationState(item, today).level);
                                return (
                                    <li key={item.id}>
                                        <Link
                                            href={itemHref(item)}
                                            only={['selected', 'access_log', 'revealed']}
                                            preserveScroll
                                            preserveState
                                            aria-current={current ? 'page' : undefined}
                                            className={cx(
                                                'flex min-h-[50px] items-center justify-between gap-2 rounded-[10px] px-3 py-1.5 text-fg no-underline',
                                                current ? 'bg-status-maintenance-bg shadow-[inset_3px_0_0_var(--color-status-maintenance)]' : 'hover:bg-subtle',
                                            )}
                                        >
                                            <span className="flex min-w-0 flex-col">
                                                <b className="truncate text-sm">{item.name}</b>
                                                <span className="truncate text-xs text-fg-muted">
                                                    {item.product} · {VAULT_ENV_LABELS[item.environment].toLowerCase()}
                                                </span>
                                            </span>
                                            {warn ? <Icon name="alert-triangle" size={16} label="Por vencer" className="shrink-0 text-status-maintenance" /> : null}
                                        </Link>
                                    </li>
                                );
                            })}
                            {filtered.length === 0 ? <li className="px-3 py-2 text-[13px] text-fg-muted">Ninguna credencial con ese nombre.</li> : null}
                        </ul>
                    </nav>

                    <div className="order-1 flex min-w-0 flex-col gap-4 lg:order-2">
                        {selected && state ? (
                            <>
                                <div className="flex flex-wrap items-start justify-between gap-3">
                                    <div className="flex min-w-0 flex-col gap-0.5">
                                        <h2 className="font-mono text-lg font-bold break-all">{selected.name}</h2>
                                        <span className="text-[13px] text-fg-soft">
                                            {selected.product} · {VAULT_ENV_LABELS[selected.environment]} · {VAULT_TYPE_LABELS[selected.type]}
                                        </span>
                                    </div>
                                    <Button variant="secondary" icon="edit" href={route('ai.vault.edit', { item: selected.id })}>
                                        Cambiar valor
                                    </Button>
                                </div>

                                <div className="grid grid-cols-[minmax(0,1fr)_auto] items-center gap-4 rounded-[14px] bg-surface p-4 ring-1 ring-line ring-inset">
                                    {value ? (
                                        <>
                                            <div className="flex min-w-0 flex-col gap-2.5">
                                                <span className="text-xs font-semibold text-fg-muted">Valor visible por {REVEAL_SECONDS} segundos</span>
                                                <span className="rounded-[10px] bg-subtle px-3 py-2.5 font-mono text-[15px] font-semibold break-all select-all">{value.value}</span>
                                                <div className="flex flex-wrap items-center gap-2">
                                                    <Button variant="vault" icon={copy === 'copied' ? 'check' : 'copy'} onClick={() => copyValue(selected)}>
                                                        {copy === 'copied' ? 'Copiado' : 'Copiar'}
                                                    </Button>
                                                    <Button variant="secondary" icon="eye-off" onClick={hide}>
                                                        Ocultar
                                                    </Button>
                                                </div>
                                                <span role="status" className="text-xs text-fg-muted">
                                                    {copy === 'failed'
                                                        ? 'El navegador no dejó copiar. Selecciona el valor y cópialo a mano.'
                                                        : `El portapapeles se limpia solo a los ${CLIPBOARD_SECONDS} segundos de copiar.`}
                                                </span>
                                            </div>
                                            <CountdownRing seconds={secondsLeft} total={REVEAL_SECONDS} label="El valor se oculta en" size={60} className="text-status-maintenance" />
                                        </>
                                    ) : (
                                        <>
                                            <div className="flex min-w-0 flex-col gap-1">
                                                <span className="flex items-center gap-1.5 text-sm font-bold">
                                                    <Icon name="eye-off" size={16} />
                                                    Valor oculto
                                                </span>
                                                <span className="text-xs text-fg-muted">Se muestra {REVEAL_SECONDS} segundos y queda registrado en el historial.</span>
                                            </div>
                                            <Button variant="vault" icon="eye" onClick={() => reveal(selected)}>
                                                Ver valor
                                            </Button>
                                        </>
                                    )}
                                </div>

                                <dl className="grid grid-cols-1 gap-x-5 gap-y-3 text-sm sm:grid-cols-2 xl:grid-cols-4">
                                    <div className="flex min-w-0 flex-col gap-0.5">
                                        <dt className="text-xs font-semibold text-fg-muted">Usuario</dt>
                                        <dd className="break-all">{selected.username ?? '—'}</dd>
                                    </div>
                                    <div className="flex min-w-0 flex-col gap-0.5">
                                        <dt className="text-xs font-semibold text-fg-muted">URL de uso</dt>
                                        <dd className="break-all">{selected.usage_url ?? '—'}</dd>
                                    </div>
                                    <div className="flex min-w-0 flex-col gap-0.5">
                                        <dt className="text-xs font-semibold text-fg-muted">Vence</dt>
                                        <dd className={cx(state.level === 'soon' || state.level === 'expired' ? 'font-bold text-status-maintenance' : undefined)}>
                                            {state.level === 'soon' || state.level === 'expired' ? <Icon name="alert-triangle" size={14} className="mr-1 inline align-[-2px]" /> : null}
                                            {state.days !== null ? `${state.date} · ${state.days >= 0 ? `en ${state.days} ${state.days === 1 ? 'día' : 'días'}` : state.sentence.toLowerCase()}` : state.date}
                                        </dd>
                                    </div>
                                    <div className="flex min-w-0 flex-col gap-0.5">
                                        <dt className="text-xs font-semibold text-fg-muted">Rotar</dt>
                                        <dd>{rotationText(selected.rotation_days)}</dd>
                                    </div>
                                    {selected.notes ? (
                                        <div className="flex min-w-0 flex-col gap-0.5 sm:col-span-2 xl:col-span-4">
                                            <dt className="text-xs font-semibold text-fg-muted">Notas</dt>
                                            <dd className="leading-snug text-fg-soft">{selected.notes}</dd>
                                        </div>
                                    ) : null}
                                </dl>

                                <AccessLog log={access_log} now={new Date(now)} />
                            </>
                        ) : (
                            <div className="flex flex-col items-start gap-2 rounded-[14px] p-5 ring-1 ring-line ring-inset">
                                <Icon name="key" size={22} className="text-fg-soft" />
                                <b className="text-base">Elige una credencial</b>
                                <span className="text-sm text-fg-soft">Ves sus datos y su historial; el valor se abre {REVEAL_SECONDS} segundos con “Ver valor”.</span>
                            </div>
                        )}
                        {telegram_alerts ? (
                            <p className="flex items-start gap-2 text-xs text-fg-muted">
                                <Icon name="telegram" size={14} className="mt-px shrink-0" />
                                {holder_name.split(' ')[0]} recibe un aviso por Telegram al ver o copiar un valor.
                            </p>
                        ) : null}
                    </div>
                </div>
            </VaultZone>
        </GestorLayout>
    );
}
