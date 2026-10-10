import { Link } from '@inertiajs/react';
import { Icon } from '@/Components/ui/Icon';
import { cx } from '@/Components/ui/utils';
import { formatShortDate } from '@/lib/format';
import type { PlannedRelease, ReleaseSummary } from '@/types/github';

export interface ReleaseListProps {
    releases: ReleaseSummary[];
    /** Next version announced but not released yet. */
    planned?: PlannedRelease | null;
    /** Gestor release page for a tag (`Ai/Releases/Show`); without it the tag is plain text. */
    releaseHref?: (release: ReleaseSummary) => string | null;
    /** Text when there are no releases. */
    emptyText?: string;
    className?: string;
}

function meta(release: ReleaseSummary): string {
    const parts = [formatShortDate(release.published_at)];
    if (release.request_keys.length > 0) parts.push(release.request_keys.join(', '));
    if (release.notified_count > 0) parts.push(`${release.notified_count} ${release.notified_count === 1 ? 'solicitante avisado' : 'solicitantes avisados'}`);
    return parts.join(' · ');
}

/** Product versions from GitHub releases, newest first, with the planned one on top. */
export function ReleaseList({ releases, planned = null, releaseHref, emptyText = 'Aún no hay releases. La versión se crea sola con el primero.', className }: ReleaseListProps) {
    if (releases.length === 0 && !planned) return <p className={cx('text-[13px] text-fg-muted', className)}>{emptyText}</p>;

    return (
        <ul className={cx('flex flex-col', className)}>
            {planned ? (
                <li className="flex items-start gap-3 rounded-control border border-dashed border-line-strong px-3 py-2.5">
                    <span className="tabular mt-0.5 min-w-14 text-sm font-bold text-fg-soft">{planned.tag}</span>
                    <span className="flex min-w-0 flex-col gap-0.5">
                        <span className="text-[13px] font-semibold text-fg">Planeada · {planned.summary}</span>
                        <span className="text-xs text-fg-muted">Se crea sola cuando se publique el release en GitHub</span>
                    </span>
                </li>
            ) : null}
            {releases.map((release, index) => {
                const href = releaseHref?.(release) ?? null;
                return (
                    <li key={release.tag} className={cx('flex items-center gap-3 py-1.5', (index > 0 || planned) && 'border-t border-line')}>
                        {href ? (
                            <Link href={href} className="tabular flex min-h-11 min-w-14 items-center text-sm font-bold text-fg underline-offset-[3px] hover:underline">
                                {release.tag}
                            </Link>
                        ) : (
                            <span className="tabular min-w-14 text-sm font-bold text-fg">{release.tag}</span>
                        )}
                        <span className="flex min-w-0 flex-1 flex-col gap-0.5">
                            <span className="truncate text-[13px] font-semibold text-fg">{release.summary}</span>
                            <span className="tabular text-xs text-fg-muted">{meta(release)}</span>
                        </span>
                        <a
                            href={release.url}
                            target="_blank"
                            rel="noopener noreferrer"
                            aria-label={`Ver ${release.tag} en GitHub (abre en GitHub)`}
                            title={`Ver ${release.tag} en GitHub`}
                            className="inline-flex size-11 shrink-0 items-center justify-center rounded-control text-fg-soft hover:bg-subtle hover:text-fg"
                        >
                            <Icon name="external" size={16} />
                        </a>
                    </li>
                );
            })}
        </ul>
    );
}
