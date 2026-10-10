import { Link } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { Button } from '@/Components/ui/Button';
import { Icon, type IconName } from '@/Components/ui/Icon';
import { Timeline } from '@/Components/ui/Timeline';
import { cx } from '@/Components/ui/utils';
import { formatRelative } from '@/lib/format';
import { route } from '@/lib/route';
import type { StatusFamily } from '@/lib/status';
import { reviewReason } from '@/lib/taskGitHub';
import type { KanbanColumnKey } from '@/types/ai';
import type { PullRequestState } from '@/types/github';
import type { TaskGitHub, TaskGitHubEventKind } from '@/types/pages/ai-github';
import { CiStatusLabel } from './CiStatusLabel';
import { ExternalLink } from './ExternalLink';
import { IssueDialog } from './IssueDialog';
import { PR_STATE } from './PullRequestRow';

const sentence = (text: string) => `${text.charAt(0).toUpperCase()}${text.slice(1)}`;

/** GitHub link state of a PR as the board's `GithubLink` sees it. */
const linkState = (state: PullRequestState) => (state === 'open' ? 'open' : state);

export interface TaskGitHubBlockProps {
    github: TaskGitHub;
    task: { key: string; title: string; description: string; labels: string[]; column: KanbanColumnKey };
    projectKey: string;
    now: string;
    /** The issue was created in this visit (marks it "nuevo"). */
    issueIsNew?: boolean;
    className?: string;
}

/**
 * GitHub facts of a task for the drawer aside and the phone page: the
 * issue, the PR with its CI and why the task moved, and "Crear issue en
 * GitHub" (D-TaskIssue) when the viewer may create one.
 */
export function TaskGitHubBlock({ github, task, projectKey, now, issueIsNew = false, className }: TaskGitHubBlockProps) {
    const form = github.issue_form;
    const [open, setOpen] = useState(false);
    const openOnLoad = form?.open ?? false;

    // Opened on load (back from a failed store): wait one tick so the dialog stacks above a drawer that opens in the same render.
    useEffect(() => {
        if (!openOnLoad) return;
        const id = window.setTimeout(() => setOpen(true), 0);
        return () => window.clearTimeout(id);
    }, [openOnLoad]);
    const at = new Date(now);
    const pr = github.pull_request;
    const reason = pr ? reviewReason(task.column, { number: pr.number, state: linkState(pr.state) }, github.request_key) : null;
    const repo = github.repo;

    return (
        <div className={cx('flex flex-col gap-2.5', className)}>
            {github.issue ? (
                <div className="flex flex-col gap-0.5">
                    <span className="flex flex-wrap items-center gap-x-2">
                        <Icon name="issue" size={15} className={github.issue.state === 'open' ? 'text-status-active' : 'text-status-local'} />
                        <ExternalLink href={github.issue.url} className="text-sm">
                            <span className="tabular">Issue #{github.issue.number}</span>
                        </ExternalLink>
                        <span className="text-[13px] font-semibold text-fg-soft">{github.issue.state === 'open' ? 'Abierto' : 'Cerrado'}</span>
                        {issueIsNew ? <span className="rounded-chip bg-subtle px-2 py-0.5 text-xs font-bold text-fg-soft">nuevo</span> : null}
                    </span>
                    <span className="text-xs leading-snug text-fg-muted">
                        {github.issue.created_from_gestor ? 'Creado desde el Gestor' : 'Abierto en GitHub'}
                        {github.issue.created_by ? ` por ${github.issue.created_by.name}` : ''} · {formatRelative(github.issue.created_at, at)}
                    </span>
                </div>
            ) : null}

            {pr ? (
                <div className="flex flex-col gap-1">
                    <span className="flex flex-wrap items-center gap-x-2">
                        <Icon name={PR_STATE[pr.state].icon} size={15} className={PR_STATE[pr.state].tone} />
                        <ExternalLink href={pr.url} className="text-sm">
                            <span className="tabular">PR #{pr.number}</span>
                        </ExternalLink>
                        <span className={cx('text-[13px] font-semibold', PR_STATE[pr.state].tone)}>{PR_STATE[pr.state].label}</span>
                    </span>
                    <CiStatusLabel status={pr.ci} className="self-start" />
                    {reason ? (
                        <p className="flex items-start gap-1.5 text-xs leading-snug font-semibold text-status-maintenance">
                            <Icon name="pull-request" size={13} className="mt-px shrink-0" />
                            {reason}
                        </p>
                    ) : null}
                </div>
            ) : null}

            {!github.issue && !pr ? <p className="text-[13px] text-fg-muted">Sin issue ni PR.</p> : null}

            {!github.issue && repo && form ? (
                <>
                    <Button variant="secondary" icon="issue" aria-haspopup="dialog" onClick={() => setOpen(true)} className="self-start">
                        Crear issue en GitHub
                    </Button>
                    <IssueDialog
                        open={open}
                        onClose={() => setOpen(false)}
                        action={route('ai.tasks.issue.store', { project: projectKey, task: task.key })}
                        github={{ ...github, repo }}
                        form={form}
                        task={task}
                        projectKey={projectKey}
                    />
                </>
            ) : null}

            {!github.issue && !repo ? (
                <p className="flex items-start gap-1.5 text-xs leading-snug text-fg-muted">
                    <Icon name="lock" size={12} className="mt-0.5 shrink-0" />
                    <span>
                        Para crear issues, vincula el repositorio en el{' '}
                        <Link href={route('ai.projects.show', { project: projectKey })} className="font-bold text-fg-soft underline underline-offset-[3px]">
                            resumen del proyecto
                        </Link>
                        .
                    </span>
                </p>
            ) : null}
        </div>
    );
}

// ---- phone: PR card and history ----------------------------------------------

/** D-GitHubPhone: the open PR of the task as a card. */
export function TaskPullRequestCard({ github, now }: { github: TaskGitHub; now: string }) {
    const pr = github.pull_request;
    if (!pr) return null;
    const state = PR_STATE[pr.state];
    const at = new Date(now);
    const when = pr.state === 'merged' && pr.merged_at ? `Fusionado ${formatRelative(pr.merged_at, at)}` : `Abierto por ${pr.author.name} ${formatRelative(pr.opened_at, at)}`;

    return (
        <section aria-labelledby="task-pr-title" className="flex flex-col gap-3 rounded-card border border-line bg-surface p-4">
            <div className="flex items-center justify-between gap-2">
                <span className="inline-flex items-center gap-1.5 text-[13px] font-semibold text-fg-soft">
                    <Icon name="pull-request" size={15} />
                    <span className="tabular">Pull request #{pr.number}</span>
                </span>
                <span className={cx('inline-flex items-center gap-1 text-[13px] font-bold', state.tone)}>
                    <Icon name={state.icon} size={14} />
                    {state.label}
                </span>
            </div>
            <h2 id="task-pr-title" className="font-display text-lg leading-snug font-bold">
                {pr.title}
            </h2>
            <dl className="grid grid-cols-2 gap-3">
                <div className="flex flex-col gap-1 rounded-control bg-ground px-3 py-2.5">
                    <dt className="text-xs text-fg-muted">CI</dt>
                    <dd>
                        <CiStatusLabel status={pr.ci} short />
                    </dd>
                </div>
                <div className="flex flex-col gap-1 rounded-control bg-ground px-3 py-2.5">
                    <dt className="text-xs text-fg-muted">Commits</dt>
                    <dd className="tabular text-[13px] font-bold">{pr.commits}</dd>
                </div>
            </dl>
            <p className="text-[13px] leading-snug text-fg-soft">
                {when} · rama <code className="rounded-[6px] bg-subtle px-1.5 py-0.5 font-mono text-xs break-all text-fg">{pr.branch}</code> hacia {pr.base_branch}
            </p>
            <ExternalLink href={pr.url} variant="button" srHint="se abre en otra pestaña" className="w-full">
                Ver en GitHub
            </ExternalLink>
        </section>
    );
}

const EVENT_LOOK: Record<TaskGitHubEventKind, { icon: IconName; family: StatusFamily }> = {
    ci_passed: { icon: 'check-circle', family: 'green' },
    ci_failed: { icon: 'x-circle', family: 'red' },
    moved_review: { icon: 'pull-request', family: 'amber' },
    moved_done: { icon: 'check', family: 'green' },
    moved_progress: { icon: 'move', family: 'blue' },
    issue_created: { icon: 'issue', family: 'gray' },
    pr_opened: { icon: 'pull-request', family: 'gray' },
    pr_merged: { icon: 'merge', family: 'green' },
};

/** "Historial" of the task with what GitHub moved, newest first. */
export function TaskGitHubHistory({ github, now }: { github: TaskGitHub; now: string }) {
    if (github.events.length === 0) return null;
    const at = new Date(now);
    const open = github.pull_request && github.pull_request.state === 'open';
    return (
        <section aria-labelledby="task-history" className="flex flex-col gap-3 rounded-card border border-line bg-surface p-4">
            <h2 id="task-history" className="text-sm font-bold">
                Historial
            </h2>
            <Timeline
                label="Historial de la tarea"
                items={github.events.map((event) => ({
                    id: event.id,
                    icon: EVENT_LOOK[event.kind].icon,
                    family: EVENT_LOOK[event.kind].family,
                    title: event.title,
                    meta: [sentence(formatRelative(event.at, at)), event.note].filter(Boolean).join(' · '),
                }))}
            />
            {open ? <p className="text-[13px] text-fg-muted">Cuando se fusione el PR, la tarea pasa sola a Hecho.</p> : null}
        </section>
    );
}
