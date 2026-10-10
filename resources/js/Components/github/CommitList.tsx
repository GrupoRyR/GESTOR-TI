import { cx } from '@/Components/ui/utils';
import { formatRelative } from '@/lib/format';
import type { CommitSummary } from '@/types/github';

export interface CommitListProps {
    commits: CommitSummary[];
    /** Total in the period; shows "Mostrando 5 de 11" when larger than the list. */
    total?: number;
    now?: string;
    className?: string;
}

/** Recent commits on the main branch: short SHA (GitHub link), message, author and when. */
export function CommitList({ commits, total, now, className }: CommitListProps) {
    const at = now ? new Date(now) : new Date();
    if (commits.length === 0) return <p className={cx('text-[13px] text-fg-muted', className)}>Sin commits en main estos 7 días.</p>;

    return (
        <div className={cx('flex flex-col', className)}>
            <ul className="flex flex-col">
                {commits.map((commit, index) => (
                    <li key={commit.sha} className={cx('flex items-center gap-3 py-1', index > 0 && 'border-t border-line')}>
                        <a
                            href={commit.url}
                            target="_blank"
                            rel="noopener noreferrer"
                            className="inline-flex min-h-11 shrink-0 items-center font-mono text-xs font-semibold text-fg-soft underline-offset-[3px] hover:text-fg hover:underline"
                        >
                            {commit.sha}
                            <span className="sr-only"> (abre en GitHub)</span>
                        </a>
                        <span className="flex min-w-0 flex-1 flex-col gap-0.5">
                            <span className="truncate text-[13px] font-semibold text-fg">{commit.message}</span>
                            <span className="text-xs text-fg-muted">
                                {commit.author.name} · {formatRelative(commit.committed_at, at)}
                            </span>
                        </span>
                    </li>
                ))}
            </ul>
            {total !== undefined && total > commits.length ? (
                <p className="tabular pt-1 text-xs text-fg-muted">
                    Mostrando {commits.length} de {total}
                </p>
            ) : null}
        </div>
    );
}
