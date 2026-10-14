import type { ReactNode } from 'react';
import { Icon } from '@/Components/ui/Icon';
import { cx } from '@/Components/ui/utils';
import { formatRelative } from '@/lib/format';
import type { GitHubRepo } from '@/types/github';
import { ExternalLink } from './ExternalLink';

export interface RepoHeaderProps {
    repo: GitHubRepo;
    /** "vinculado al proyecto P-07". */
    linkedLabel?: string | null;
    /** When the server last read GitHub data ("datos de hace 2 min"). */
    fetchedAt?: string | null;
    now?: string;
    /** Extra buttons before "Ver en GitHub" (e.g. Cambiar repositorio). */
    actions?: ReactNode;
    className?: string;
}

/** `GrupoRyR/repo` with privacy, main branch, link note, data age and "Ver en GitHub". */
export function RepoHeader({ repo, linkedLabel, fetchedAt, now, actions, className }: RepoHeaderProps) {
    const at = now ? new Date(now) : new Date();
    return (
        <div className={cx('flex flex-wrap items-center gap-3', className)}>
            <span className="flex size-10 shrink-0 items-center justify-center rounded-icon bg-subtle text-fg-soft">
                <Icon name="code" size={18} />
            </span>
            <div className="flex min-w-0 flex-1 flex-col gap-0.5">
                <span className="truncate text-[15px] font-bold text-fg">{repo.full_name}</span>
                <span className="text-[13px] leading-snug text-fg-soft">
                    {repo.private ? 'Privado' : 'Público'} · rama principal{' '}
                    <code className="rounded-[6px] bg-subtle px-1.5 py-0.5 font-mono text-xs text-fg">{repo.default_branch}</code>
                    {linkedLabel ? ` · ${linkedLabel}` : ''}
                    {fetchedAt ? ` · datos de ${formatRelative(fetchedAt, at)}` : ''}
                </span>
            </div>
            <div className="flex flex-wrap items-center gap-2">
                {actions}
                <ExternalLink href={repo.url} variant="button" srHint="se abre en otra pestaña">
                    Ver en GitHub
                </ExternalLink>
            </div>
        </div>
    );
}
