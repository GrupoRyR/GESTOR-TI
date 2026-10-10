import { Icon, type IconName } from '@/Components/ui/Icon';
import { cx } from '@/Components/ui/utils';
import { formatRelative, formatShortDate } from '@/lib/format';
import type { PullRequest, PullRequestState } from '@/types/github';
import { CiStatusLabel } from './CiStatusLabel';
import { ExternalLink } from './ExternalLink';
import { GitHubKeyChip } from './GitHubKeyChip';

export const PR_STATE: Record<PullRequestState, { label: string; icon: IconName; tone: string }> = {
    open: { label: 'Abierto', icon: 'pull-request', tone: 'text-status-active' },
    merged: { label: 'Fusionado', icon: 'merge', tone: 'text-status-scheduled' },
    closed: { label: 'Cerrado sin fusionar', icon: 'x-circle', tone: 'text-status-local' },
};

export interface PullRequestRowProps {
    pr: PullRequest;
    /** ISO "now" for relative times (pages pass `NOW_ISO` in mocks). */
    now?: string;
    /** Show the linked `GIA-123 · T3 …` chip (default true). */
    showLinked?: boolean;
    className?: string;
}

/** One PR: title as a GitHub link, state in words, author, commits, branch, CI and linked work. */
export function PullRequestRow({ pr, now, showLinked = true, className }: PullRequestRowProps) {
    const state = PR_STATE[pr.state];
    const at = now ? new Date(now) : new Date();
    const when =
        pr.state === 'open'
            ? `Abierto por ${pr.author.name} ${formatRelative(pr.opened_at, at)}`
            : pr.state === 'merged' && pr.merged_at
              ? `Fusionado ${formatRelative(pr.merged_at, at)} · de ${pr.author.name}`
              : `Cerrado sin fusionar${pr.closed_at ? ` el ${formatShortDate(pr.closed_at)}` : ''} · de ${pr.author.name}`;

    return (
        <div className={cx('flex items-start gap-3', className)}>
            <span className={cx('mt-3', state.tone)}>
                <Icon name={state.icon} size={18} label={state.label} />
            </span>
            <div className="flex min-w-0 flex-1 flex-col gap-1">
                <ExternalLink href={pr.url} className="min-h-11 self-start text-[15px] leading-snug">
                    <span>
                        <span className="tabular">#{pr.number}</span> {pr.title}
                    </span>
                </ExternalLink>
                <p className="-mt-1.5 text-[13px] leading-snug text-fg-soft">
                    {when} · <span className="tabular">{pr.commits}</span> {pr.commits === 1 ? 'commit' : 'commits'} ·{' '}
                    <code className="rounded-[6px] bg-subtle px-1.5 py-0.5 font-mono text-xs text-fg">{pr.branch}</code> hacia {pr.base_branch}
                </p>
                <div className="flex flex-wrap items-center gap-2 pt-0.5">
                    <span className={cx('inline-flex items-center gap-1 text-[13px] font-semibold', state.tone)}>{state.label}</span>
                    <CiStatusLabel status={pr.ci} />
                    {showLinked && pr.linked ? <GitHubKeyChip linked={pr.linked} /> : null}
                </div>
            </div>
        </div>
    );
}
