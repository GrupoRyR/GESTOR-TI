import { Link } from '@inertiajs/react';
import { useId, useState } from 'react';
import { Icon } from '@/Components/ui/Icon';
import { cx } from '@/Components/ui/utils';
import { route } from '@/lib/route';
import type { DocListItem } from '@/types/pages/ai-knowledge';
import { groupDocs, type DocOwnerLike } from './docs';

/** "P-07 · Visualizador de cartera" for projects, the product name otherwise. */
export function ownerLabel(owner: DocOwnerLike): string {
    return owner.kind === 'project' ? `${owner.key} · ${owner.name}` : owner.name;
}

export function ownerHref(owner: DocOwnerLike): string {
    return owner.kind === 'project' ? route('ai.projects.show', { project: owner.key }) : route('ai.products.show', { product: owner.key });
}

function TreeGroup({ owner, docs, currentId, defaultOpen }: { owner: DocOwnerLike; docs: DocListItem[]; currentId: number | null; defaultOpen: boolean }) {
    const [open, setOpen] = useState(defaultOpen);
    const listId = useId();
    return (
        <li className="flex flex-col">
            <button
                type="button"
                aria-expanded={open}
                aria-controls={listId}
                onClick={() => setOpen((value) => !value)}
                className="mt-1.5 flex min-h-11 w-full items-center gap-1.5 rounded-control px-2 text-left text-[13px] font-bold text-fg hover:bg-subtle"
            >
                <Icon name={open ? 'chevron-down' : 'chevron-right'} size={14} className="shrink-0 text-fg-muted" />
                <span className="min-w-0 flex-1 truncate">{ownerLabel(owner)}</span>
                <span className="shrink-0 text-xs font-semibold text-fg-muted">{open ? (owner.kind === 'project' ? 'proyecto' : 'producto') : docs.length}</span>
            </button>
            {open ? (
                <ul id={listId} className="flex flex-col">
                    {docs.map((doc) => {
                        const current = doc.id === currentId;
                        return (
                            <li key={doc.id}>
                                <Link
                                    href={route('ai.docs.edit', { doc: doc.id })}
                                    aria-current={current ? 'page' : undefined}
                                    className={cx(
                                        'flex min-h-11 items-center gap-2 rounded-control py-1 pr-2 pl-[30px] text-[13px] no-underline',
                                        current ? 'bg-brand-tint font-bold text-brand-ink' : 'font-medium text-fg-soft hover:bg-subtle hover:text-fg',
                                    )}
                                >
                                    <span className="min-w-0 flex-1 truncate">{doc.title}</span>
                                    <span className={cx('tabular shrink-0 text-xs', current ? 'text-brand-ink' : 'text-fg-muted')}>
                                        {doc.not_applicable ? 'n/a' : `v${doc.version}`}
                                    </span>
                                </Link>
                            </li>
                        );
                    })}
                </ul>
            ) : null}
        </li>
    );
}

/**
 * Left tree of B-Docs: docs by project and product. The group of the current
 * doc and the first three groups start open; the rest show their count.
 */
export function DocTree({ docs, currentId, canCreate, className }: { docs: DocListItem[]; currentId: number | null; canCreate: boolean; className?: string }) {
    const groups = groupDocs(docs);
    return (
        <nav aria-label="Documentos por proyecto y producto" className={cx('flex min-w-0 flex-col gap-1', className)}>
            <div className="flex items-center gap-2">
                {canCreate ? (
                    <Link
                        href={route('ai.docs.create')}
                        className="flex h-11 min-w-0 flex-1 items-center justify-center gap-1.5 rounded-control bg-brand-button px-3 text-sm font-bold text-brand-button-ink no-underline hover:brightness-[0.93]"
                    >
                        <Icon name="plus" size={16} />
                        Nuevo documento
                    </Link>
                ) : (
                    <span className="flex-1 text-sm font-bold">Documentos</span>
                )}
                <Link
                    href={route('ai.search', { type: 'docs' })}
                    aria-label="Buscar en la documentación"
                    title="Buscar en la documentación"
                    className="flex size-11 shrink-0 items-center justify-center rounded-control border border-line-strong text-fg-soft hover:bg-subtle hover:text-fg"
                >
                    <Icon name="search" size={16} />
                </Link>
            </div>
            <ul className="flex flex-col">
                {groups.map((group, index) => (
                    <TreeGroup
                        key={`${group.owner.kind}:${group.owner.key}`}
                        owner={group.owner}
                        docs={group.docs as DocListItem[]}
                        currentId={currentId}
                        defaultOpen={index < 3 || group.docs.some((doc) => doc.id === currentId)}
                    />
                ))}
            </ul>
            <Link
                href={route('ai.docs.repo')}
                className="mt-2 flex min-h-11 items-center gap-2 rounded-control border-t border-line px-2 pt-2 text-[13px] font-semibold text-fg-soft no-underline hover:text-fg"
            >
                <Icon name="pull-request" size={16} />
                Documentos del repositorio
            </Link>
        </nav>
    );
}

export const VISIBILITY_LABELS = {
    company: (company: string) => `Todo ${company}`,
    it: () => 'Solo TI',
    project: () => 'El equipo del proyecto',
} as const;
