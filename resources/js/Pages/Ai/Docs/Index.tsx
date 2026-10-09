import { Link } from '@inertiajs/react';
import { ownerHref, ownerLabel } from '@/Components/ai/docs/DocParts';
import { groupDocs, templateName } from '@/Components/ai/docs/docs';
import { Avatar } from '@/Components/ui/Avatar';
import { Button } from '@/Components/ui/Button';
import { Chip } from '@/Components/ui/Chip';
import { EmptyState } from '@/Components/ui/EmptyState';
import { Icon } from '@/Components/ui/Icon';
import { GestorLayout } from '@/Layouts/GestorLayout';
import { can } from '@/lib/can';
import { formatRelative } from '@/lib/format';
import { route } from '@/lib/route';
import type { DocListItem, DocsIndexProps } from '@/types/pages/ai-knowledge';
import type { PageProps } from '@/types/shared';

function DocRow({ doc, now }: { doc: DocListItem; now: Date }) {
    const content = (
        <>
            <span className="flex min-w-0 flex-1 flex-col gap-0.5">
                <b className="truncate text-sm">{doc.title}</b>
                <span className="truncate text-[13px] text-fg-muted">{templateName(doc.template)}</span>
            </span>
            {doc.not_applicable ? (
                <span className="flex shrink-0 items-center gap-2 text-[13px] text-fg-muted">
                    <Chip tone="outline" size="sm">
                        n/a
                    </Chip>
                    <span className="hidden sm:inline">No aplica</span>
                </span>
            ) : (
                <span className="flex shrink-0 items-center gap-2.5 text-[13px] text-fg-muted">
                    <span className="tabular font-semibold text-fg-soft">v{doc.version}</span>
                    <span className="hidden items-center gap-1.5 sm:flex">
                        <Avatar name={doc.updated_by.name} size="xs" />
                        <span className="max-w-[180px] truncate">
                            {doc.updated_by.name} · {formatRelative(doc.updated_at, now)}
                        </span>
                    </span>
                    <Icon name="chevron-right" size={16} />
                </span>
            )}
        </>
    );
    if (doc.not_applicable) return <div className="flex min-h-14 items-center gap-3 border-t border-line px-5 py-2.5">{content}</div>;
    return (
        <Link
            href={route('ai.docs.edit', { doc: doc.id })}
            className="flex min-h-14 items-center gap-3 border-t border-line px-5 py-2.5 text-fg no-underline transition-colors duration-150 hover:bg-ground"
        >
            {content}
        </Link>
    );
}

/** Documentación: every doc by project and product, with template, version and last editor. */
export default function DocsIndex(props: PageProps<DocsIndexProps>) {
    const { docs, now, auth } = props;
    const canEdit = can(auth.user, 'ai.docs.edit');
    const groups = groupDocs(docs);
    const clock = new Date(now);

    return (
        <GestorLayout shared={props} title="Documentación · Gestor IA" tab="docs" mode="it">
            <div className="flex flex-col gap-4">
                <div className="flex flex-wrap items-end justify-between gap-3">
                    <div className="flex max-w-[640px] flex-col gap-1">
                        <h1 className="font-display text-[26px] leading-tight font-bold tracking-[-0.02em] md:text-[30px]">Documentación</h1>
                        <p className="text-sm text-fg-soft">
                            Fichas, manuales y decisiones de cada proyecto y producto. Se escriben en Markdown y cada versión publicada se puede restaurar.
                        </p>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        <Button variant="secondary" icon="search" href={route('ai.search', { type: 'docs' })}>
                            Buscar
                        </Button>
                        <Button variant="secondary" icon="pull-request" href={route('ai.docs.repo')}>
                            Del repositorio
                        </Button>
                        {canEdit ? (
                            <Button icon="plus" href={route('ai.docs.create')}>
                                Nuevo documento
                            </Button>
                        ) : null}
                    </div>
                </div>

                {groups.length === 0 ? (
                    <EmptyState
                        icon="list"
                        title="Todavía no hay documentos"
                        description="Empieza por la ficha del proyecto o el manual de usuario: las plantillas traen las secciones listas para llenar."
                        actions={
                            canEdit ? (
                                <Button icon="plus" href={route('ai.docs.create')}>
                                    Nuevo documento
                                </Button>
                            ) : undefined
                        }
                    />
                ) : (
                    <div className="grid gap-4 xl:grid-cols-2">
                        {groups.map((group) => (
                            <section
                                key={`${group.owner.kind}:${group.owner.key}`}
                                aria-label={ownerLabel(group.owner)}
                                className="flex min-w-0 flex-col overflow-hidden rounded-card border border-line bg-surface"
                            >
                                <div className="flex flex-wrap items-center justify-between gap-2 px-5 py-3.5">
                                    <div className="flex min-w-0 flex-col">
                                        <Link href={ownerHref(group.owner)} className="truncate font-display text-lg leading-tight font-bold text-fg no-underline hover:underline">
                                            {ownerLabel(group.owner)}
                                        </Link>
                                        <span className="text-[13px] text-fg-muted">
                                            {group.owner.kind === 'project' ? 'Proyecto' : 'Producto'} · {group.docs.length}{' '}
                                            {group.docs.length === 1 ? 'documento' : 'documentos'}
                                        </span>
                                    </div>
                                    {canEdit ? (
                                        <Link
                                            href={route('ai.docs.create', { owner_kind: group.owner.kind, owner_key: group.owner.key })}
                                            className="flex min-h-11 items-center gap-1 text-[13px] font-bold text-fg"
                                        >
                                            <Icon name="plus" size={14} />
                                            Agregar
                                            <span className="sr-only">documento a {ownerLabel(group.owner)}</span>
                                        </Link>
                                    ) : null}
                                </div>
                                {group.docs.map((doc) => (
                                    <DocRow key={doc.id} doc={doc} now={clock} />
                                ))}
                            </section>
                        ))}
                    </div>
                )}
            </div>
        </GestorLayout>
    );
}
