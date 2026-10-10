import { Link } from '@inertiajs/react';
import { useId } from 'react';
import { Button } from '@/Components/ui/Button';
import { Icon } from '@/Components/ui/Icon';
import { cx } from '@/Components/ui/utils';
import { MCP_TOOLS } from '@/lib/mcp';
import { route } from '@/lib/route';
import type { McpActivity, McpCall, McpToken } from '@/types/mcp';
import { ACTIVITY_ANCHOR, ResultChip, whenText } from './parts';
import { activityLinkProps } from './links';

const COLUMNS = 'lg:grid lg:grid-cols-[minmax(0,1.1fr)_minmax(0,1.1fr)_140px_160px_minmax(260px,1.2fr)] lg:items-center lg:gap-x-3.5';

function toolLabel(key: McpCall['tool']): string {
    return MCP_TOOLS.find((tool) => tool.key === key)?.label ?? key;
}

function CallRows({ calls, now, label, tinted = false }: { calls: McpCall[]; now: Date; label: string; tinted?: boolean }) {
    return (
        <div role="table" aria-label={label} className="text-sm">
            {tinted ? null : (
                <div role="row" className={cx('hidden h-[38px] bg-subtle px-5 text-xs font-bold text-fg-muted', COLUMNS)}>
                    {['Herramienta', 'Sobre qué', 'Cuándo', 'Token', 'Resultado'].map((header) => (
                        <span key={header} role="columnheader">
                            {header}
                        </span>
                    ))}
                </div>
            )}
            {calls.map((call, index) => (
                <div
                    key={call.id}
                    role="row"
                    className={cx(
                        'flex flex-col gap-1.5 px-4 py-3 lg:min-h-[60px] lg:px-5 lg:py-2',
                        COLUMNS,
                        (index > 0 || !tinted) && 'border-t border-line',
                    )}
                >
                    <span role="cell" className="flex min-w-0 flex-col">
                        <b className="truncate font-semibold">{toolLabel(call.tool)}</b>
                        <code className="truncate font-mono text-[11px] text-fg-muted">{call.tool}</code>
                    </span>
                    <span role="cell" className="min-w-0 text-fg-soft">
                        {call.summary}
                    </span>
                    <span role="cell" className="text-fg-soft max-lg:text-[13px]">
                        {whenText(call.at, now)}
                        <span className="lg:hidden"> · {call.token.name}</span>
                    </span>
                    <span role="cell" className="truncate text-fg-soft max-lg:hidden">
                        {call.token.name}
                    </span>
                    <span role="cell" className="min-w-0">
                        <ResultChip call={call} />
                    </span>
                </div>
            ))}
        </div>
    );
}

export interface ActivityPanelProps {
    activity: McpActivity;
    selected: McpToken | null;
    /** Live (not revoked) tokens, for the filter. */
    tokens: McpToken[];
    now: Date;
    onRevoke: (token: McpToken) => void;
}

/** E-McpActivity: audited calls of one token (or all), with the rejected calls of the other tokens. */
export function ActivityPanel({ activity, selected, tokens, now, onRevoke }: ActivityPanelProps) {
    const titleId = useId();
    const title = selected ? `Actividad de ${selected.name}` : 'Actividad de todos tus tokens';
    const rejected = activity.rejected_elsewhere;

    return (
        <section id={ACTIVITY_ANCHOR} tabIndex={-1} aria-labelledby={titleId} className="flex scroll-mt-28 flex-col gap-3.5 outline-none">
            <div className="flex flex-wrap items-end justify-between gap-3">
                <div className="flex min-w-0 flex-col gap-1">
                    <h2 id={titleId} className="font-display text-[22px] leading-tight font-bold md:text-[24px]">
                        {title}
                    </h2>
                    <p className="text-sm text-fg-soft">Cada llamada queda registrada. El registro no guarda el token ni lo que el Gestor respondió.</p>
                </div>
                <div className="flex flex-wrap gap-2">
                    {selected && !selected.revoked_at ? (
                        <Button variant="secondary" icon="blocked" onClick={() => onRevoke(selected)} className="text-status-failure">
                            Revocar este token
                        </Button>
                    ) : null}
                    <Link
                        href={route('profile.mcp')}
                        only={['activity', 'selected_token']}
                        preserveScroll
                        preserveState
                        className="inline-flex h-11 items-center gap-1.5 rounded-control px-3 text-sm font-semibold text-fg-soft hover:bg-subtle"
                    >
                        <Icon name="x" size={16} />
                        Cerrar actividad
                    </Link>
                </div>
            </div>

            <div role="group" aria-label="Filtrar por token" className="flex flex-wrap items-center gap-2">
                {[{ id: 'all' as const, name: 'Todos mis tokens' }, ...tokens.map((token) => ({ id: token.id, name: token.name }))].map((option) => {
                    const current = option.id === 'all' ? activity.token_id === null : activity.token_id === option.id;
                    return (
                        <Link
                            key={option.id}
                            {...activityLinkProps(option.id)}
                            aria-current={current ? 'true' : undefined}
                            className={cx(
                                'inline-flex min-h-11 items-center rounded-chip px-3.5 text-sm font-semibold no-underline',
                                current ? 'bg-fg text-on-fg' : 'bg-surface text-fg ring-1 ring-line-strong ring-inset hover:bg-subtle',
                            )}
                        >
                            {option.name}
                        </Link>
                    );
                })}
                {activity.retention ? <span className="ml-auto text-xs text-fg-muted">Se guarda {activity.retention}</span> : null}
            </div>

            <div className="overflow-hidden rounded-card border border-line bg-surface">
                {activity.calls.length === 0 ? (
                    <p className="flex items-center gap-2 px-5 py-5 text-sm text-fg-soft">
                        <Icon name="history" size={16} />
                        {selected ? 'Claude todavía no ha usado este token.' : 'Claude todavía no ha usado tus tokens.'}
                    </p>
                ) : (
                    <CallRows calls={activity.calls} now={now} label="Llamadas del conector" />
                )}
            </div>

            {selected && rejected.length > 0 ? (
                <div className="overflow-hidden rounded-card border border-status-failure-line bg-surface">
                    <div className="flex flex-wrap items-center gap-x-3 gap-y-1 bg-status-failure-bg px-4 py-2.5 text-sm text-status-failure lg:px-5">
                        <Icon name="blocked" size={16} />
                        <span className="min-w-0 flex-1">
                            <b>
                                {rejected.length} {rejected.length === 1 ? 'llamada rechazada' : 'llamadas rechazadas'}
                            </b>{' '}
                            en tus otros tokens esta semana
                        </span>
                        <Link {...activityLinkProps('all')} className="flex min-h-11 items-center text-[13px] font-bold text-status-failure underline underline-offset-2">
                            Ver en Todos mis tokens
                        </Link>
                    </div>
                    <CallRows calls={rejected} now={now} label={rejected.length === 1 ? 'Llamada rechazada' : 'Llamadas rechazadas'} tinted />
                </div>
            ) : null}
        </section>
    );
}
