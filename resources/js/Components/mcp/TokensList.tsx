import { Link } from '@inertiajs/react';
import { useId } from 'react';
import { Button, buttonClasses } from '@/Components/ui/Button';
import { Chip } from '@/Components/ui/Chip';
import { Icon } from '@/Components/ui/Icon';
import { cx } from '@/Components/ui/utils';
import { formatDate, formatShortDate } from '@/lib/format';
import { calendarIso, daysLeftText, maskToken, MCP_SCOPE_LABELS, tokenStatus, toolsFor, type TokenStatus } from '@/lib/mcp';
import type { McpToken } from '@/types/mcp';
import { activityLinkProps } from './links';
import { ScopeChip, SectionTile, SETUP_ANCHOR, tokenIcon, whenText } from './parts';

const COLUMNS = 'grid grid-cols-[minmax(0,1fr)_170px_100px_124px_120px_76px_190px] items-center gap-x-3.5 px-5';
function Last4({ last4 }: { last4: string }) {
    return (
        <code aria-label={`Termina en ${last4}`} className="rounded-[6px] bg-subtle px-1.5 py-0.5 font-mono text-[13px]">
            {maskToken(last4)}
        </code>
    );
}

function subtitle(token: McpToken, now: Date): string {
    if (token.last_client) return token.last_client;
    return now.getTime() - new Date(token.created_at).getTime() < 86_400_000 ? 'Nuevo · recién creado' : 'Sin uso todavía';
}

function Expiry({ token, status }: { token: McpToken; status: TokenStatus }) {
    if (status.kind === 'expired') {
        return (
            <Chip tone="red" icon="clock" size="sm">
                Venció {formatShortDate(calendarIso(token.expires_at))}
            </Chip>
        );
    }
    return (
        <span className="flex flex-col">
            <span>{formatDate(calendarIso(token.expires_at))}</span>
            {status.kind === 'expiring' ? (
                <span className="inline-flex items-center gap-1 text-xs font-semibold text-status-maintenance">
                    <Icon name="alert-triangle" size={12} />
                    Vence {daysLeftText(status.days)}
                </span>
            ) : status.kind === 'active' ? (
                <span className="text-xs text-fg-muted">{daysLeftText(status.days)}</span>
            ) : null}
        </span>
    );
}

function TokenName({ token, now }: { token: McpToken; now: Date }) {
    return (
        <span className="flex min-w-0 items-center gap-2.5">
            <span aria-hidden="true" className="flex size-[34px] shrink-0 items-center justify-center rounded-icon bg-subtle text-fg-soft">
                <Icon name={tokenIcon(token.name)} size={16} />
            </span>
            <span className="flex min-w-0 flex-col">
                <b className="truncate font-semibold">{token.name}</b>
                <span className="truncate text-xs text-fg-muted">{subtitle(token, now)}</span>
            </span>
        </span>
    );
}

interface RowActionsProps {
    token: McpToken;
    expired: boolean;
    onRevoke: (token: McpToken) => void;
    onReplace: (token: McpToken) => void;
    compact?: boolean;
}

function RowActions({ token, expired, onRevoke, onReplace, compact = false }: RowActionsProps) {
    const size = compact ? '' : 'h-10 px-3 text-[13px]';
    return (
        <>
            {expired ? (
                <Button variant="secondary" onClick={() => onReplace(token)} className={size}>
                    Reemplazar
                </Button>
            ) : (
                <Link {...activityLinkProps(token.id)} className={buttonClasses({ variant: 'secondary', className: cx(size, compact && 'w-full') })}>
                    {compact ? <Icon name="history" size={16} /> : null}
                    Actividad
                    <span className="sr-only"> de {token.name}</span>
                </Link>
            )}
            <button
                type="button"
                onClick={() => onRevoke(token)}
                className={cx(
                    buttonClasses({ variant: 'secondary', className: size }),
                    'text-status-failure',
                    compact && 'w-full',
                )}
            >
                {expired ? 'Quitar' : 'Revocar'}
                <span className="sr-only"> {token.name}</span>
            </button>
        </>
    );
}

/** E-McpStates "Un token se venció": one notice per expired (not revoked) token. */
function ExpiredNotice({ token, onReplace, onRevoke }: { token: McpToken; onReplace: (token: McpToken) => void; onRevoke: (token: McpToken) => void }) {
    const titleId = useId();
    return (
        <section aria-labelledby={titleId} className="flex flex-col gap-3 rounded-card border border-status-failure-line bg-surface p-4 md:p-5">
            <div className="flex items-center gap-3">
                <SectionTile icon="clock" tone="red" size={36} />
                <h3 id={titleId} className="font-display text-base font-bold">
                    Un token se venció
                </h3>
            </div>
            <div className="flex flex-wrap items-center gap-x-3 gap-y-2 rounded-[12px] bg-ground px-3.5 py-2.5">
                <Icon name={tokenIcon(token.name)} size={16} className="text-fg-soft" />
                <span className="flex min-w-0 flex-1 flex-col">
                    <b className="truncate text-sm">{token.name}</b>
                    <span className="text-xs text-fg-muted">
                        {MCP_SCOPE_LABELS[token.scope]} · {maskToken(token.last4)}
                    </span>
                </span>
                <Chip tone="red" icon="clock" size="sm">
                    Venció el {formatShortDate(calendarIso(token.expires_at))}
                </Chip>
            </div>
            <p className="text-sm leading-normal text-fg-soft">Claude recibe un error 401 al usarlo. Crea uno nuevo y cámbialo en Claude; el vencido no se puede renovar.</p>
            <div className="flex flex-wrap gap-2">
                <Button icon="plus" onClick={() => onReplace(token)}>
                    Crear token de reemplazo
                </Button>
                <Button variant="secondary" onClick={() => onRevoke(token)}>
                    Quitar de la lista
                </Button>
            </div>
        </section>
    );
}

/** E-McpStates "Todavía no tienes tokens". */
function EmptyTokens({ onCreate }: { onCreate: () => void }) {
    return (
        <div className="flex flex-col items-start gap-3 px-5 pt-2 pb-6">
            <SectionTile icon="key" size={52} />
            <h3 className="font-display text-xl leading-tight font-bold">Todavía no tienes tokens</h3>
            <p className="max-w-[520px] text-[15px] leading-normal text-fg-soft">
                Crea uno para que Claude pueda buscar solicitudes, leer su documentación y, si quieres, dejar notas y tareas en tus proyectos.
            </p>
            <div className="flex flex-wrap gap-2">
                <ScopeLine scope="read" />
                <ScopeLine scope="read_write" />
            </div>
            <div className="flex flex-wrap gap-2">
                <Button icon="plus" onClick={onCreate}>
                    Crear token
                </Button>
                <a href={`#${SETUP_ANCHOR}`} className={buttonClasses({ variant: 'secondary' })}>
                    <Icon name="book" size={16} />
                    Cómo agregarlo en Claude
                </a>
            </div>
        </div>
    );
}

function ScopeLine({ scope }: { scope: McpToken['scope'] }) {
    const count = toolsFor(scope).length;
    return (
        <Chip tone={scope === 'read' ? 'slate' : 'amber'} icon={scope === 'read' ? 'eye' : 'edit'} size="sm">
            {scope === 'read' ? `Solo lectura: ${count} herramientas` : `Lectura y escritura: ${count}`}
        </Chip>
    );
}

export interface TokensListProps {
    tokens: McpToken[];
    now: Date;
    onCreate: () => void;
    onRevoke: (token: McpToken) => void;
    onReplace: (token: McpToken) => void;
}

/** E-McpTokens "Tus tokens": table from 1024 px, cards below; revoked ones collapsed. */
export function TokensList({ tokens, now, onCreate, onRevoke, onReplace }: TokensListProps) {
    const titleId = useId();
    const live = tokens.filter((token) => !token.revoked_at);
    const revoked = tokens.filter((token) => token.revoked_at);
    const expired = live.filter((token) => tokenStatus(token, now).kind === 'expired');

    return (
        <div className="flex flex-col gap-4">
            {expired.map((token) => (
                <ExpiredNotice key={token.id} token={token} onReplace={onReplace} onRevoke={onRevoke} />
            ))}

            <section aria-labelledby={titleId} className="overflow-hidden rounded-card border border-line bg-surface">
                <div className="flex flex-wrap items-center justify-between gap-x-4 gap-y-1 px-5 py-4">
                    <span className="flex min-w-0 flex-col gap-0.5">
                        <h2 id={titleId} className="font-display text-lg leading-tight font-bold">
                            Tus tokens
                        </h2>
                        <span className="text-[13px] text-fg-muted">El valor completo se ve una sola vez, al crearlo. Aquí solo ves sus últimos 4 caracteres.</span>
                    </span>
                    {live.length > 0 ? (
                        <Link {...activityLinkProps('all')} className="flex min-h-11 items-center gap-1.5 text-sm font-semibold">
                            <Icon name="history" size={16} />
                            Ver toda la actividad
                        </Link>
                    ) : null}
                </div>

                {live.length === 0 ? (
                    <EmptyTokens onCreate={onCreate} />
                ) : (
                    <>
                        <div role="table" aria-label="Tokens del conector" className="hidden text-sm lg:block">
                            <div role="row" className={cx(COLUMNS, 'h-[38px] bg-subtle text-xs font-bold text-fg-muted')}>
                                {['Nombre', 'Permiso', 'Creado', 'Último uso', 'Vence', 'Termina en'].map((header) => (
                                    <span key={header} role="columnheader">
                                        {header}
                                    </span>
                                ))}
                                <span role="columnheader" className="text-right">
                                    Acciones
                                </span>
                            </div>
                            {live.map((token) => {
                                const status = tokenStatus(token, now);
                                return (
                                    <div key={token.id} role="row" className={cx(COLUMNS, 'min-h-[68px] border-t border-line py-2')}>
                                        <span role="cell" className="min-w-0">
                                            <TokenName token={token} now={now} />
                                        </span>
                                        <span role="cell">
                                            <ScopeChip scope={token.scope} />
                                        </span>
                                        <span role="cell" className="text-fg-soft">
                                            {whenText(token.created_at, now)}
                                        </span>
                                        <span role="cell" className="text-fg-soft">
                                            {token.last_used_at ? whenText(token.last_used_at, now) : 'Sin uso todavía'}
                                        </span>
                                        <span role="cell">
                                            <Expiry token={token} status={status} />
                                        </span>
                                        <span role="cell">
                                            <Last4 last4={token.last4} />
                                        </span>
                                        <span role="cell" className="flex justify-end gap-1.5">
                                            <RowActions token={token} expired={status.kind === 'expired'} onRevoke={onRevoke} onReplace={onReplace} />
                                        </span>
                                    </div>
                                );
                            })}
                        </div>

                        <ul aria-label="Tokens del conector" className="grid grid-cols-1 gap-3 px-4 pb-4 md:grid-cols-2 lg:hidden">
                            {live.map((token) => {
                                const status = tokenStatus(token, now);
                                return (
                                    <li key={token.id} className="flex min-w-0 flex-col gap-3 rounded-[14px] border border-line p-3.5">
                                        <div className="flex items-start gap-2.5">
                                            <span aria-hidden="true" className="flex size-[34px] shrink-0 items-center justify-center rounded-icon bg-subtle text-fg-soft">
                                                <Icon name={tokenIcon(token.name)} size={16} />
                                            </span>
                                            <span className="flex min-w-0 flex-1 flex-col">
                                                <b className="truncate text-[15px] font-semibold">{token.name}</b>
                                                <span className="truncate text-[13px] text-fg-muted">
                                                    Último uso: {token.last_used_at ? whenText(token.last_used_at, now).toLowerCase() : 'sin uso todavía'}
                                                </span>
                                            </span>
                                            <Last4 last4={token.last4} />
                                        </div>
                                        <div className="flex flex-wrap items-center gap-2 text-[13px] text-fg-soft">
                                            <ScopeChip scope={token.scope} />
                                            {status.kind === 'expired' ? (
                                                <Expiry token={token} status={status} />
                                            ) : (
                                                <span className={status.kind === 'expiring' ? 'inline-flex items-center gap-1 font-semibold text-status-maintenance' : undefined}>
                                                    {status.kind === 'expiring' ? <Icon name="alert-triangle" size={12} /> : null}
                                                    Vence {formatDate(calendarIso(token.expires_at))}
                                                </span>
                                            )}
                                        </div>
                                        <div className="grid grid-cols-2 gap-2">
                                            <RowActions token={token} expired={status.kind === 'expired'} onRevoke={onRevoke} onReplace={onReplace} compact />
                                        </div>
                                    </li>
                                );
                            })}
                        </ul>
                    </>
                )}

                {revoked.length > 0 ? (
                    <details className="group border-t border-line">
                        <summary className="flex min-h-12 cursor-pointer list-none items-center gap-2 px-5 text-sm font-semibold text-fg-soft [&::-webkit-details-marker]:hidden">
                            <Icon name="chevron-right" size={16} className="transition-transform group-open:rotate-90 motion-reduce:transition-none" />
                            Revocados ({revoked.length})
                            <span className="hidden font-normal text-fg-muted sm:inline">· ya no funcionan; se guardan para la auditoría</span>
                        </summary>
                        <ul className="pb-2">
                            {revoked.map((token) => (
                                <li
                                    key={token.id}
                                    className="flex flex-wrap items-center gap-x-3.5 gap-y-1.5 px-5 py-2 text-sm text-fg-muted lg:grid lg:min-h-[52px] lg:grid-cols-[minmax(0,1fr)_170px_100px_124px_120px_76px_190px]"
                                >
                                    <span className="min-w-0 truncate line-through max-lg:basis-full">{token.name}</span>
                                    <ScopeChip scope={token.scope} />
                                    <span className="max-lg:hidden">{formatDate(token.created_at)}</span>
                                    <span className="max-lg:hidden">{token.last_used_at ? formatDate(token.last_used_at) : '—'}</span>
                                    <Chip tone="slate" icon="blocked" size="sm">
                                        Revocado {formatShortDate(token.revoked_at as string)}
                                    </Chip>
                                    <code aria-label={`Termina en ${token.last4}`} className="font-mono text-[13px]">
                                        {maskToken(token.last4)}
                                    </code>
                                </li>
                            ))}
                        </ul>
                    </details>
                ) : null}
            </section>
        </div>
    );
}
