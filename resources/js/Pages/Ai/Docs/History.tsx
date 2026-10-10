import { Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { compareLabel } from '@/Components/ai/docs/docs';
import { Button } from '@/Components/ui/Button';
import { ConfirmDialog } from '@/Components/ui/Dialog';
import { DiffView } from '@/Components/ui/DiffView';
import { EmptyState } from '@/Components/ui/EmptyState';
import { Icon } from '@/Components/ui/Icon';
import { MarkdownPreview } from '@/Components/ui/MarkdownPreview';
import { SegmentedControl } from '@/Components/ui/SegmentedControl';
import { cx } from '@/Components/ui/utils';
import { GestorLayout } from '@/Layouts/GestorLayout';
import { can } from '@/lib/can';
import { formatRelative, formatShortDate } from '@/lib/format';
import { route } from '@/lib/route';
import type { DocsHistoryProps } from '@/types/pages/ai-knowledge';
import type { PageProps } from '@/types/shared';

type View = 'changes' | 'full';

/** B-DocsHistory: published versions, the diff of the chosen one and "Restaurar esta versión". */
export default function DocsHistory(props: PageProps<DocsHistoryProps>) {
    const { doc, versions, compare, now, auth } = props;
    const canEdit = can(auth.user, 'ai.docs.edit');
    const [view, setView] = useState<View>('changes');
    const [confirming, setConfirming] = useState(false);
    const [restoring, setRestoring] = useState(false);
    const current = versions.find((version) => version.current)?.version ?? doc.version;
    const clock = new Date(now);

    const when = (iso: string) => {
        const relative = formatRelative(iso, clock);
        return relative.startsWith('hoy') || relative.startsWith('ayer') || relative.startsWith('hace') ? relative : formatShortDate(iso);
    };

    const restore = () => {
        if (!compare) return;
        setRestoring(true);
        router.post(
            route('ai.docs.restore', { doc: doc.id, version: compare.version }),
            {},
            {
                preserveScroll: true,
                onFinish: () => {
                    setRestoring(false);
                    setConfirming(false);
                },
            },
        );
    };

    return (
        <GestorLayout
            shared={props}
            title={`Historial · ${doc.title}`}
            tab="docs"
            mode="it"
            breadcrumb={[
                { label: 'Documentación', href: route('ai.docs.index') },
                { label: `${doc.title} · ${doc.owner.name}`, href: route('ai.docs.edit', { doc: doc.id }) },
                { label: 'Historial' },
            ]}
        >
            <div className="flex flex-col gap-4">
                <div className="flex flex-col gap-1">
                    <h1 className="font-display text-[26px] leading-tight font-bold tracking-[-0.02em] md:text-[30px]">Historial de versiones</h1>
                    <p className="text-sm text-fg-soft">
                        {versions.length} {versions.length === 1 ? 'versión' : 'versiones'} · restaurar crea una versión nueva, no borra ninguna
                    </p>
                </div>

                <div className="grid gap-4 lg:grid-cols-[340px_minmax(0,1fr)] lg:items-start">
                    <section aria-label="Versiones" className="overflow-hidden rounded-card border border-line bg-surface">
                        <ol className="flex flex-col">
                            {versions.map((version, index) => {
                                const selected = compare?.version === version.version;
                                return (
                                    <li key={version.version}>
                                        <Link
                                            href={route('ai.docs.history', { doc: doc.id })}
                                            data={{ version: version.version }}
                                            only={['compare']}
                                            preserveState
                                            preserveScroll
                                            aria-current={selected ? 'true' : undefined}
                                            className={cx(
                                                'flex flex-col gap-1 px-[18px] py-3 text-fg no-underline transition-colors duration-150',
                                                index > 0 && 'border-t border-line',
                                                selected ? 'bg-brand-tint shadow-[inset_3px_0_0_var(--color-brand-button)]' : 'hover:bg-ground',
                                            )}
                                        >
                                            <span className="flex items-baseline justify-between gap-3">
                                                <b className="text-sm">
                                                    Versión {version.version}
                                                    {version.current ? ' · actual' : ''}
                                                </b>
                                                <span className="shrink-0 text-xs text-fg-muted">{when(version.created_at)}</span>
                                            </span>
                                            <span className="text-[13px] leading-snug text-fg-soft">{version.summary}</span>
                                            <span className="text-xs text-fg-muted">{version.author.name}</span>
                                        </Link>
                                    </li>
                                );
                            })}
                        </ol>
                    </section>

                    {compare ? (
                        <section aria-label="Cambios" className="flex min-w-0 flex-col gap-3">
                            <div className="flex flex-wrap items-center justify-between gap-3">
                                <h2 className="font-display text-lg leading-tight font-bold">{compareLabel(compare.version, compare.against, current)}</h2>
                                <div className="flex flex-wrap items-center gap-2">
                                    <SegmentedControl<View>
                                        label="Forma de ver"
                                        value={view}
                                        onChange={setView}
                                        options={[
                                            { value: 'changes', label: 'Cambios' },
                                            { value: 'full', label: 'Versión completa' },
                                        ]}
                                    />
                                    {canEdit && compare.version !== current ? (
                                        <Button variant="dark" icon="refresh" onClick={() => setConfirming(true)}>
                                            Restaurar esta versión
                                        </Button>
                                    ) : null}
                                </div>
                            </div>
                            {view === 'changes' ? (
                                <DiffView lines={compare.lines} label="Diferencias línea por línea" />
                            ) : (
                                <article className="rounded-card border border-line bg-surface px-5 py-4">
                                    <MarkdownPreview source={compare.markdown} />
                                </article>
                            )}
                        </section>
                    ) : (
                        <EmptyState
                            icon="clock"
                            title="Solo hay una versión"
                            description="Cuando publiques un cambio, aquí verás qué líneas se agregaron o quitaron y podrás volver atrás."
                            actions={
                                <Button variant="secondary" href={route('ai.docs.edit', { doc: doc.id })}>
                                    <Icon name="edit" size={16} />
                                    Volver al documento
                                </Button>
                            }
                        />
                    )}
                </div>
            </div>

            {compare ? (
                <ConfirmDialog
                    open={confirming}
                    onClose={() => setConfirming(false)}
                    onConfirm={restore}
                    processing={restoring}
                    tone="primary"
                    title={`¿Restaurar la versión ${compare.version}?`}
                    description={`Se crea la versión ${current + 1} con el texto de la ${compare.version}. La versión ${current} sigue en el historial.`}
                    confirmLabel="Restaurar esta versión"
                />
            ) : null}
        </GestorLayout>
    );
}
