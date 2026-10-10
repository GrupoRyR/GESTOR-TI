import { Link, router } from '@inertiajs/react';
import { FilterSelect } from '@/Components/ai/triage/InboxParts';
import { buttonClasses } from '@/Components/ui/Button';
import { EmptyState } from '@/Components/ui/EmptyState';
import { Icon } from '@/Components/ui/Icon';
import { MarkdownPreview } from '@/Components/ui/MarkdownPreview';
import { cx } from '@/Components/ui/utils';
import { GestorLayout } from '@/Layouts/GestorLayout';
import { can } from '@/lib/can';
import { formatRelative, formatShortDate } from '@/lib/format';
import { route } from '@/lib/route';
import type { DocsRepoProps } from '@/types/pages/ai-knowledge';
import type { PageProps } from '@/types/shared';

const fileName = (path: string) => path.replace(/\/$/, '').split('/').pop() ?? path;

/** B-DocsRepo: Markdown files read from the product's GitHub repository, read-only. */
export default function DocsRepo(props: PageProps<DocsRepoProps>) {
    const { products, product_slug, repo, now } = props;

    return (
        <GestorLayout
            shared={props}
            title="Documentos del repositorio · Documentación"
            tab="docs"
            mode="it"
            breadcrumb={[{ label: 'Documentación', href: route('ai.docs.index') }, { label: 'Del repositorio' }]}
        >
            <div className="flex flex-col gap-4">
                <div className="flex flex-wrap items-end justify-between gap-3">
                    <h1 className="font-display text-[26px] leading-tight font-bold tracking-[-0.02em] md:text-[30px]">Documentos del repositorio</h1>
                    <FilterSelect
                        label="Producto"
                        value={product_slug}
                        options={products.map((product) => ({ value: product.slug, label: product.name }))}
                        onChange={(slug) => router.get(route('ai.docs.repo'), { product: slug }, { preserveScroll: true })}
                    />
                </div>

                <p className="-mt-2 max-w-[70ch] text-[13px] leading-normal text-fg-muted">
                    Se leen tal como están en GitHub; no se copian ni se editan desde el Gestor. Para cambiarlos, edítalos en el repositorio y aparecen aquí en la siguiente lectura.
                </p>

                {!repo.connected ? (
                    <EmptyState
                        icon="pull-request"
                        title="Este producto aún no tiene el repositorio vinculado"
                        description="Al vincularlo verás aquí el README, el CHANGELOG y la carpeta docs/, siempre en su última versión."
                        actions={
                            <>
                                {can(props.auth.user, 'ai.github.manage') ? (
                                    <a href={route('ai.products.show', { product: product_slug, vincular: 1 })} className={buttonClasses({ variant: 'dark' })}>
                                        Vincular repositorio
                                    </a>
                                ) : null}
                                <a href={route('ai.docs.index')} className={buttonClasses({ variant: 'secondary' })}>
                                    Ver la documentación del Gestor
                                </a>
                            </>
                        }
                    />
                ) : (
                    <div className="grid gap-4 lg:grid-cols-[300px_minmax(0,1fr)] lg:items-start">
                        <section aria-label="Archivos" className="flex flex-col overflow-hidden rounded-card border border-line bg-surface">
                            <div className="flex flex-col gap-1 border-b border-line px-4 py-3">
                                <span className="truncate text-sm font-bold text-status-maintenance">{repo.name}</span>
                                <span className="flex items-center gap-1.5 text-xs text-fg-muted">
                                    <Icon name="refresh" size={12} />
                                    Leído de GitHub · rama {repo.branch} · {formatRelative(repo.fetched_at, new Date(now))}
                                </span>
                            </div>
                            <ul className="flex flex-col">
                                {repo.files.map((file, index) => {
                                    const current = repo.selected?.path === file.path;
                                    const common = cx(
                                        'flex min-h-11 items-center gap-2.5 pr-4 text-sm',
                                        index > 0 && 'border-t border-subtle',
                                        current ? 'bg-brand-tint font-bold text-brand-ink' : 'font-medium text-fg',
                                    );
                                    const style = { paddingLeft: 16 + file.depth * 24 };
                                    if (file.kind === 'dir') {
                                        return (
                                            <li key={file.path} className={common} style={style}>
                                                <Icon name="archive" size={16} className="shrink-0 text-fg-muted" />
                                                <span className="truncate">{fileName(file.path)}/</span>
                                            </li>
                                        );
                                    }
                                    return (
                                        <li key={file.path}>
                                            <Link
                                                href={route('ai.docs.repo')}
                                                data={{ product: product_slug, path: file.path }}
                                                only={['repo']}
                                                preserveScroll
                                                preserveState
                                                aria-current={current ? 'page' : undefined}
                                                className={cx(common, 'no-underline', !current && 'hover:bg-ground')}
                                                style={style}
                                            >
                                                <Icon name="clipboard-check" size={16} className="shrink-0 text-fg-muted" />
                                                <span className="min-w-0 flex-1 truncate">{fileName(file.path)}</span>
                                                {file.updated_at ? <span className="shrink-0 text-xs font-normal text-fg-muted">{formatShortDate(file.updated_at)}</span> : null}
                                            </Link>
                                        </li>
                                    );
                                })}
                            </ul>
                            <span className="flex items-center gap-1.5 border-t border-line px-4 py-3 text-xs font-semibold text-fg-muted">
                                <Icon name="lock" size={12} />
                                Solo lectura
                            </span>
                        </section>

                        {repo.selected ? (
                            <article aria-labelledby="repo-file" className="flex min-w-0 flex-col overflow-hidden rounded-card border border-line bg-surface">
                                <div className="flex flex-wrap items-center justify-between gap-3 border-b border-line px-5 py-3.5">
                                    <span className="flex min-w-0 flex-col">
                                        <b id="repo-file" className="truncate font-mono text-sm">
                                            {repo.selected.path}
                                        </b>
                                        <span className="text-[13px] text-fg-muted">
                                            Último cambio en GitHub: {formatShortDate(repo.selected.updated_at)}
                                            {repo.selected.release ? ` · ${repo.selected.release}` : ''}
                                        </span>
                                    </span>
                                    <a href={repo.selected.github_url} target="_blank" rel="noopener noreferrer" className={buttonClasses({ variant: 'secondary' })}>
                                        Abrir en GitHub
                                        <Icon name="external" size={16} />
                                        <span className="sr-only">(se abre en otra pestaña)</span>
                                    </a>
                                </div>
                                <div className="px-5 py-5 md:px-7">
                                    <MarkdownPreview source={repo.selected.markdown} emptyText="Este archivo está vacío." />
                                </div>
                            </article>
                        ) : (
                            <EmptyState icon="clipboard-check" title="Elige un archivo" description="Los archivos Markdown del repositorio se leen aquí tal como están en GitHub." />
                        )}
                    </div>
                )}
            </div>
        </GestorLayout>
    );
}
