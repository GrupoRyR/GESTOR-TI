import { useId } from 'react';
import { Semaphore, type SemaphoreLevel } from '@/Components/ui/Semaphore';
import { cx } from '@/Components/ui/utils';
import { formatRelative } from '@/lib/format';
import type { HomeMonitoring, MonitoredApp } from '@/types/pages/home';

const LEVELS: Record<MonitoredApp['status'], { level: SemaphoreLevel; label: string } | null> = {
    up: { level: 'ok', label: 'En línea' },
    degraded: { level: 'attention', label: 'Lenta' },
    down: { level: 'late', label: 'Caída' },
    unknown: null,
};

function AppTile({ app }: { app: HomeMonitoring['apps'][number] }) {
    const live = 'status' in app ? app : null;
    const state = live ? LEVELS[live.status] : null;
    return (
        <li className="flex min-h-[88px] flex-col justify-between gap-3.5 rounded-control border border-line p-3 text-[13px] text-fg-muted">
            <b className={cx('font-bold', live ? 'text-fg' : 'text-fg-soft')}>{app.name}</b>
            {app.kind === 'local' ? (
                <span>App local</span>
            ) : state ? (
                <span className="flex flex-col gap-0.5">
                    <Semaphore level={state.level}>{state.label}</Semaphore>
                    {live?.last_heartbeat_at ? <span>Latido {formatRelative(live.last_heartbeat_at)}</span> : null}
                </span>
            ) : (
                <span>Sin latido aún</span>
            )}
        </li>
    );
}

/**
 * "Estado de las apps". Until the Monitoreo module ships, a dashed,
 * reserved block with the apps that will report heartbeats.
 */
export function MonitoringBlock({ monitoring, className }: { monitoring: HomeMonitoring; className?: string }) {
    const titleId = useId();
    const reserved = !monitoring.enabled;

    return (
        <section
            aria-labelledby={titleId}
            className={cx(
                'flex flex-col gap-3.5 rounded-card p-5 lg:min-h-[230px] lg:p-[22px]',
                reserved ? 'border-[1.5px] border-dashed border-line-strong' : 'border border-line bg-surface',
                className,
            )}
        >
            <div className="flex flex-wrap items-center justify-between gap-x-3 gap-y-1">
                <h2 id={titleId} className={cx('text-lg font-bold', reserved && 'text-fg-soft')}>
                    Estado de las apps
                </h2>
                {reserved ? <span className="text-[13px] text-fg-muted">Monitoreo aún no activo</span> : null}
            </div>
            {monitoring.apps.length === 0 ? (
                <p className="text-sm text-fg-muted">Todavía no hay apps registradas.</p>
            ) : (
                <ul className="grid flex-1 grid-cols-2 gap-2.5 md:grid-cols-3 lg:grid-cols-[repeat(auto-fit,minmax(112px,1fr))]">
                    {monitoring.apps.map((app) => (
                        <AppTile key={app.name} app={app} />
                    ))}
                </ul>
            )}
        </section>
    );
}

/** Phone: the same block folded into one dashed row with a dot per app. */
export function MonitoringRow({ monitoring }: { monitoring: HomeMonitoring }) {
    const down = monitoring.enabled ? monitoring.apps.filter((app) => app.status === 'down').length : 0;
    const summary = !monitoring.enabled
        ? 'Monitoreo aún no activo'
        : down > 0
          ? `${down} ${down === 1 ? 'app caída' : 'apps caídas'}`
          : 'Todas en línea';

    return (
        <section
            aria-label="Estado de las apps"
            className={cx(
                'flex items-center justify-between gap-3 rounded-card p-4',
                monitoring.enabled ? 'border border-line bg-surface' : 'border-[1.5px] border-dashed border-line-strong',
            )}
        >
            <span className="flex flex-col gap-0.5 text-sm">
                <b className={cx('font-bold', !monitoring.enabled && 'text-fg-soft')}>Estado de las apps</b>
                <span className="text-[13px] text-fg-muted">{summary}</span>
            </span>
            <span aria-hidden="true" className="flex flex-wrap justify-end gap-1">
                {monitoring.apps.map((app) => (
                    <span
                        key={app.name}
                        className={cx(
                            'size-2.5 rounded-full',
                            !('status' in app) || app.status === 'unknown'
                                ? 'border-[1.5px] border-line-strong'
                                : app.status === 'up'
                                  ? 'bg-status-active-dot'
                                  : app.status === 'degraded'
                                    ? 'bg-status-maintenance-dot'
                                    : 'bg-status-failure',
                        )}
                    />
                ))}
            </span>
        </section>
    );
}
