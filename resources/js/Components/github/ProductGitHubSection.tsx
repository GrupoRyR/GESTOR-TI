import type { ReactNode } from 'react';
import { Button } from '@/Components/ui/Button';
import { Icon } from '@/Components/ui/Icon';
import { cx } from '@/Components/ui/utils';
import { formatRelative, formatShortDate } from '@/lib/format';
import { route } from '@/lib/route';
import type { ReleaseSummary } from '@/types/github';
import type { ProductGitHub } from '@/types/pages/ai-github';
import { CiStatusLabel } from './CiStatusLabel';
import { CommitList } from './CommitList';
import { ExternalLink } from './ExternalLink';
import { IssueRow } from './IssueRow';
import { PullRequestRow } from './PullRequestRow';
import { ReleaseList } from './ReleaseList';
import { RepoHeader } from './RepoHeader';

const sentence = (text: string) => `${text.charAt(0).toUpperCase()}${text.slice(1)}`;

function Panel({ title, count, href, children, className }: { title: string; count?: number; href?: string; children: ReactNode; className?: string }) {
    return (
        <section aria-label={title} className={cx('flex min-w-0 flex-col overflow-hidden rounded-card border border-line bg-surface', className)}>
            <div className="flex flex-wrap items-center justify-between gap-x-3 gap-y-1 px-5 pt-3 pb-1">
                <h3 className="flex items-center gap-2 font-display text-base leading-tight font-bold">
                    {title}
                    {count !== undefined ? <span className="tabular rounded-chip bg-subtle px-2 py-0.5 font-sans text-xs font-bold text-fg-soft">{count}</span> : null}
                </h3>
                {href ? (
                    <ExternalLink href={href} className="text-[13px]" srHint="se abre en otra pestaña">
                        Ver en GitHub
                    </ExternalLink>
                ) : null}
            </div>
            <div className="px-5 pb-4">{children}</div>
        </section>
    );
}

function Fact({ label, children, note }: { label: string; children: ReactNode; note: ReactNode }) {
    return (
        <div className="flex min-w-0 flex-col gap-1.5 rounded-card border border-line bg-surface p-4">
            <span className="text-[13px] font-semibold text-fg-soft">{label}</span>
            <span className="text-[17px] font-bold">{children}</span>
            <span className="text-[13px] leading-snug text-fg-muted">{note}</span>
        </div>
    );
}

const Sha = ({ sha }: { sha: string }) => <code className="rounded-[6px] bg-subtle px-1.5 py-0.5 font-mono text-xs text-fg">{sha}</code>;

export interface ProductGitHubSectionProps {
    github: ProductGitHub;
    productSlug: string;
    now: string;
}

/**
 * D-ProductGitHub: what GitHub says about a product, from stored webhook
 * events: CI on main, last deploy, commits, PRs, issues and releases.
 */
export function ProductGitHubSection({ github, productSlug, now }: ProductGitHubSectionProps) {
    const at = new Date(now);
    const { link, ci_main: ci, deploy } = github;
    const repoUrl = link.repo.url;
    const lastClosed = github.last_closed_pull_request;
    const releaseHref = (release: ReleaseSummary) => route('ai.releases.show', { product: productSlug, release: release.tag });

    return (
        <section id="github" aria-labelledby="github-title" className="flex scroll-mt-24 flex-col gap-3">
            <h2 id="github-title" className="sr-only">
                Actividad en GitHub
            </h2>
            <div className="rounded-card border border-line bg-surface p-4 md:px-5">
                <RepoHeader
                    repo={{ ...link.repo, default_branch: link.default_branch }}
                    linkedLabel={github.project ? `vinculado al proyecto ${github.project.key}` : null}
                    fetchedAt={github.fetched_at}
                    now={now}
                />
            </div>

            <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                <Fact
                    label={`CI en ${link.default_branch}`}
                    note={
                        ci.status === 'none' ? (
                            'Este repositorio no tiene GitHub Actions. Sin CI no se muestra estado.'
                        ) : (
                            <>
                                Última corrida {ci.run_at ? formatRelative(ci.run_at, at) : ''}
                                {ci.commit_sha ? (
                                    <>
                                        {' '}
                                        · commit <Sha sha={ci.commit_sha} />
                                    </>
                                ) : null}
                            </>
                        )
                    }
                >
                    <CiStatusLabel status={ci.status} short />
                </Fact>
                <Fact
                    label="Último despliegue"
                    note={
                        deploy ? (
                            <>
                                {deploy.provider} · push a {deploy.branch} · commit <Sha sha={deploy.commit_sha} />
                            </>
                        ) : (
                            `Aún no llega un push a ${link.default_branch}.`
                        )
                    }
                >
                    {deploy ? (
                        <span className="inline-flex items-center gap-1.5">
                            <Icon name="rocket" size={16} className="text-fg-soft" />
                            {sentence(formatRelative(deploy.deployed_at, at))}
                        </span>
                    ) : (
                        <span className="text-fg-muted">Sin despliegues</span>
                    )}
                </Fact>
                <Fact
                    label="Commits últimos 7 días"
                    note={`${github.commits_period} · ${github.commits_authors} ${github.commits_authors === 1 ? 'persona' : 'personas'}`}
                >
                    <span className="tabular font-display text-[24px] leading-none">{github.commits_total}</span>
                </Fact>
            </div>

            <div className="grid gap-3 lg:grid-cols-2 lg:items-start">
                <div className="flex min-w-0 flex-col gap-3">
                    <Panel title="Pull requests abiertos" count={github.open_pull_requests.length} href={`${repoUrl}/pulls`}>
                        {github.open_pull_requests.length === 0 ? (
                            <p className="py-2 text-[13px] text-fg-muted">
                                No hay pull requests abiertos. Los que mencionen una llave GIA aparecen aquí solos.
                            </p>
                        ) : (
                            <ul className="flex flex-col">
                                {github.open_pull_requests.map((pr, index) => (
                                    <li key={pr.number} className={cx('py-2', index > 0 && 'border-t border-line')}>
                                        <PullRequestRow pr={pr} now={now} />
                                    </li>
                                ))}
                            </ul>
                        )}
                        {lastClosed ? (
                            <p className="mt-1 border-t border-line pt-2.5 text-[13px] text-fg-muted">
                                Último cerrado: #{lastClosed.number}
                                {lastClosed.state === 'merged' ? ', fusionado' : ', sin fusionar'}
                                {lastClosed.ci === 'failing' && lastClosed.closed_at ? ` · su CI falló el ${formatShortDate(lastClosed.closed_at)}` : ''}
                            </p>
                        ) : null}
                    </Panel>

                    <Panel title="Issues recientes" href={`${repoUrl}/issues`}>
                        {github.issues.length === 0 ? (
                            <p className="py-2 text-[13px] text-fg-muted">Sin issues todavía. Se crean desde las tareas con “Crear issue en GitHub”.</p>
                        ) : (
                            <ul className="flex flex-col">
                                {github.issues.map((issue, index) => (
                                    <li key={issue.number} className={cx('py-1.5', index > 0 && 'border-t border-line')}>
                                        <IssueRow issue={issue} now={now} />
                                    </li>
                                ))}
                            </ul>
                        )}
                    </Panel>
                </div>

                <div className="flex min-w-0 flex-col gap-3">
                    <Panel title="Releases" href={`${repoUrl}/releases`}>
                        <ReleaseList releases={github.releases} planned={github.planned_release} releaseHref={releaseHref} className="pt-1" />
                    </Panel>
                    <Panel title={`Commits en ${link.default_branch} · últimos 7 días`} href={`${repoUrl}/commits/${link.default_branch}`}>
                        <CommitList commits={github.commits} total={github.commits_total} now={now} />
                    </Panel>
                </div>
            </div>
        </section>
    );
}

export interface ProductGitHubEmptyProps {
    /** Shows "Vincular repositorio" (`ai.github.manage`). */
    canLink: boolean;
    onLink: () => void;
}

/** Product without a repository: what linking brings, and the button or a note. */
export function ProductGitHubEmpty({ canLink, onLink }: ProductGitHubEmptyProps) {
    return (
        <section
            id="github"
            aria-labelledby="github-empty-title"
            className="flex scroll-mt-24 flex-col gap-3 rounded-card border-2 border-dashed border-line-strong p-5 sm:flex-row sm:items-center"
        >
            <span className="flex size-11 shrink-0 items-center justify-center rounded-icon bg-subtle text-fg-soft">
                <Icon name="code" size={20} />
            </span>
            <div className="flex min-w-0 flex-1 flex-col gap-0.5">
                <h2 id="github-empty-title" className="font-display text-lg leading-tight font-bold">
                    Actividad en GitHub
                </h2>
                <p className="text-[13px] leading-snug text-fg-soft">
                    {canLink
                        ? 'Vincula el repositorio para ver CI, pull requests, releases y la documentación del código.'
                        : 'Sin repositorio vinculado. Pídele a quien administra GitHub que lo vincule.'}
                </p>
            </div>
            {canLink ? (
                <Button variant="dark" icon="link" aria-haspopup="dialog" onClick={onLink} className="self-start sm:self-center">
                    Vincular repositorio
                </Button>
            ) : null}
        </section>
    );
}
