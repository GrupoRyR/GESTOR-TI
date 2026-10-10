import { Icon } from '@/Components/ui/Icon';
import { cx } from '@/Components/ui/utils';
import { formatRelative, formatShortDate } from '@/lib/format';
import type { GitHubIssue } from '@/types/github';
import { ExternalLink } from './ExternalLink';

export interface IssueRowProps {
    issue: GitHubIssue;
    now?: string;
    /** Marks the issue just created from the Gestor ("nuevo"). */
    isNew?: boolean;
    className?: string;
}

function issueMeta(issue: GitHubIssue, at: Date): string {
    if (issue.state === 'closed') {
        const by = issue.closed_by_pr ? `Cerrado con el PR #${issue.closed_by_pr}` : 'Cerrado';
        return issue.closed_at ? `${by} · ${formatShortDate(issue.closed_at)}` : by;
    }
    const origin = issue.created_from_gestor ? 'Creado desde el Gestor' : 'Abierto en GitHub';
    const who = issue.created_by ? ` por ${issue.created_by.name}` : '';
    return `${origin}${who} · ${formatRelative(issue.created_at, at)}`;
}

/** One issue: GitHub link, origin and state in words (open/closed icon + text). */
export function IssueRow({ issue, now, isNew = false, className }: IssueRowProps) {
    const open = issue.state === 'open';
    return (
        <div className={cx('flex items-start gap-3', className)}>
            <span className={cx('mt-3', open ? 'text-status-active' : 'text-status-local')}>
                <Icon name={open ? 'issue' : 'check-circle'} size={18} />
            </span>
            <div className="flex min-w-0 flex-1 flex-col gap-1">
                <ExternalLink href={issue.url} className="min-h-11 self-start text-[15px] leading-snug">
                    <span>
                        <span className="tabular">#{issue.number}</span> {issue.title}
                    </span>
                </ExternalLink>
                <p className="-mt-1.5 text-[13px] leading-snug text-fg-soft">{issueMeta(issue, now ? new Date(now) : new Date())}</p>
            </div>
            <span className="mt-3 flex shrink-0 items-center gap-2">
                {isNew ? <span className="rounded-chip bg-subtle px-2 py-0.5 text-xs font-bold text-fg-soft">nuevo</span> : null}
                <span className={cx('text-[13px] font-semibold', open ? 'text-status-active' : 'text-status-local')}>{open ? 'Abierto' : 'Cerrado'}</span>
            </span>
        </div>
    );
}
