import { router } from '@inertiajs/react';
import { useState } from 'react';
import { VisibilityBadge } from '@/Components/assistant/KnowledgeBadges';
import { ChangeList, CurationHeading, CurationNoPermission, OutcomeChip } from '@/Components/assistant/curation/CurationParts';
import { Button, buttonClasses } from '@/Components/ui/Button';
import { ConfirmDialog } from '@/Components/ui/Dialog';
import { EmptyState } from '@/Components/ui/EmptyState';
import { Icon } from '@/Components/ui/Icon';
import { cx } from '@/Components/ui/utils';
import { AssistantLayout } from '@/Layouts/AssistantLayout';
import { can } from '@/lib/can';
import { joinEs } from '@/lib/curation';
import {
    changeSummary,
    importableCount,
    importButtonLabel,
    previewFilterTotal,
    previewQuery,
    shownRowsNote,
    skippedNote,
    type ImportPreviewFilter,
} from '@/lib/importPreview';
import { route } from '@/lib/route';
import type { ImportRowResult } from '@/types/assistant';
import type { ImportPreviewProps } from '@/types/pages/assistant-curation';
import type { PageProps } from '@/types/shared';

const TILES: { filter: ImportPreviewFilter; label: string }[] = [
    { filter: 'all', label: 'Todas las filas' },
    { filter: 'new', label: 'Nuevas' },
    { filter: 'update', label: 'Actualizan fichas existentes' },
    { filter: 'error', label: 'Con error' },
];

/** "Qué pasa" cell: the error and its fix, the changes of an update, or the new card's summary. */
function RowOutcome({ row, expanded, onToggle }: { row: ImportRowResult; expanded: boolean; onToggle: () => void }) {
    if (row.outcome === 'error' && row.error) {
        return (
            <span className="flex flex-col gap-1 text-sm leading-snug">
                <b className="text-status-failure">{row.error.message}</b>
                <span className="text-fg-soft">
                    <b className="text-fg">Cómo arreglarlo:</b> {row.error.fix}
                </span>
            </span>
        );
    }
    if (row.outcome === 'update') {
        const panelId = `changes-${row.row}`;
        return (
            <span className="flex flex-col gap-1.5">
                <span className="flex min-h-11 flex-wrap items-center gap-x-3 text-[13px] text-fg-soft">
                    {changeSummary(row.changes)}
                    {row.changes.length > 0 ? (
                        <button
                            type="button"
                            aria-expanded={expanded}
                            aria-controls={panelId}
                            onClick={onToggle}
                            className="ml-auto inline-flex h-11 items-center gap-1 rounded-control px-3 text-[13px] font-bold text-fg underline hover:bg-subtle"
                        >
                            {expanded ? 'Ocultar cambios' : 'Ver cambios'}
                            <Icon name="chevron-down" size={14} className={cx('transition-transform duration-150', expanded && 'rotate-180')} />
                        </button>
                    ) : null}
                </span>
                {expanded ? (
                    <span id={panelId} className="flex flex-col gap-1.5">
                        <ChangeList changes={row.changes} />
                        <span className="text-[13px] text-fg-muted">Los demás campos quedan igual.</span>
                    </span>
                ) : null}
            </span>
        );
    }
    return (
        <span className="flex flex-wrap items-center gap-x-2 gap-y-1 text-[13px] text-fg-soft">
            Ficha nueva
            {row.visibility ? <VisibilityBadge visibility={row.visibility} size="sm" /> : null}
            <span>· dueño {row.owner_name ?? 'sin dueño'}</span>
        </span>
    );
}

/** Excel import, step 3 (C-ImportPreview): outcome per row before anything is saved. */
export default function ImportPreview(props: PageProps<ImportPreviewProps>) {
    const { file, summary, filter, rows, error_rows, drafts_count, unanswered_count } = props;
    const manage = can(props.auth.user, 'assistant.knowledge.manage');
    const firstUpdate = rows.find((row) => row.outcome === 'update')?.row;
    const [expanded, setExpanded] = useState<number[]>(firstUpdate === undefined ? [] : [firstUpdate]);
    const [confirming, setConfirming] = useState(false);
    const [importing, setImporting] = useState(false);

    const importable = importableCount(summary);
    const sheet = file.sheets.find((item) => item.name === file.selected_sheet);
    const note = shownRowsNote(rows, summary, filter);
    const updatedKeys = rows.filter((row) => row.outcome === 'update' && row.card).map((row) => row.card?.key ?? '');

    const pick = (next: ImportPreviewFilter) => {
        router.get(route('assistant.import.preview', { import: file.id }), previewQuery(next), {
            only: ['rows', 'filter'],
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    };
    const toggle = (row: number) => setExpanded((current) => (current.includes(row) ? current.filter((item) => item !== row) : [...current, row]));
    const confirmImport = () => {
        router.post(route('assistant.import.store', { import: file.id }), {}, {
            onStart: () => setImporting(true),
            onFinish: () => {
                setImporting(false);
                setConfirming(false);
            },
        });
    };

    const content = !manage ? (
        <CurationNoPermission what="Importar fichas" />
    ) : (
        <div className="flex min-w-0 flex-col gap-5">
            <CurationHeading
                title="Revisa antes de importar"
                subtitle={`${file.name} · hoja ${file.selected_sheet} · ${sheet?.rows ?? summary.total} filas. Todavía no se ha guardado nada.`}
                step={3}
            />

            <div role="group" aria-label="Filtrar por resultado" className="grid grid-cols-2 gap-2.5 lg:grid-cols-4 lg:gap-3">
                {TILES.map((tile) => {
                    const pressed = filter === tile.filter;
                    const count = previewFilterTotal(summary, tile.filter);
                    return (
                        <button
                            key={tile.filter}
                            type="button"
                            aria-pressed={pressed}
                            onClick={() => pick(tile.filter)}
                            className={cx(
                                'flex min-h-[68px] items-center gap-3 rounded-card bg-surface px-4 py-2.5 text-left transition-colors duration-150',
                                pressed ? 'border-2 border-fg' : 'border border-line hover:border-line-strong',
                            )}
                        >
                            <span
                                className={cx(
                                    'tabular font-display text-[28px] leading-none font-bold',
                                    tile.filter === 'error' && count > 0 && 'text-status-failure',
                                )}
                            >
                                {count}
                            </span>
                            <span className="flex items-center gap-1.5 text-sm leading-tight font-semibold text-fg-soft">
                                {tile.filter === 'error' && count > 0 ? <Icon name="x-circle" size={14} className="shrink-0 text-status-failure" /> : null}
                                {tile.label}
                            </span>
                        </button>
                    );
                })}
            </div>

            <section aria-label="Filas del Excel" className="-mx-4 overflow-hidden border-y border-line bg-surface md:mx-0 md:rounded-card md:border">
                <div
                    aria-hidden="true"
                    className="grid grid-cols-[52px_minmax(0,1fr)_180px_minmax(0,1.25fr)] gap-x-4 bg-subtle px-4 py-2.5 text-[13px] font-semibold text-fg-soft max-lg:hidden"
                >
                    <span>Fila</span>
                    <span>Ficha</span>
                    <span>Resultado</span>
                    <span>Qué pasa</span>
                </div>
                {rows.length === 0 ? (
                    <EmptyState bordered={false} className="p-6" icon="check-circle" title="Ninguna fila con este resultado" description="Elige otro resultado arriba para ver sus filas." />
                ) : (
                    <ul className="flex flex-col">
                        {rows.map((row) => (
                            <li
                                key={row.row}
                                className={cx(
                                    'grid grid-cols-[minmax(0,1fr)_auto] gap-x-3 gap-y-2 border-t border-line px-4 py-3.5 first:border-t-0 lg:first:border-t',
                                    'lg:grid-cols-[52px_minmax(0,1fr)_180px_minmax(0,1.25fr)] lg:items-start lg:gap-x-4',
                                    row.outcome === 'error' && 'bg-status-failure-bg/50 shadow-[inset_3px_0_0_var(--color-status-failure)]',
                                )}
                            >
                                <b className="tabular pt-0.5 text-sm max-lg:hidden">{row.row}</b>
                                <span className="flex min-w-0 flex-col gap-0.5">
                                    <b className="text-[15px] leading-snug">{row.title}</b>
                                    <span className="text-[13px] text-fg-muted">
                                        <span className="lg:hidden">Fila {row.row} · </span>
                                        {row.context}
                                    </span>
                                </span>
                                <span className="justify-self-end lg:justify-self-start lg:pt-0.5">
                                    <OutcomeChip row={row} />
                                </span>
                                <span className="col-span-2 min-w-0 lg:col-span-1">
                                    <RowOutcome row={row} expanded={expanded.includes(row.row)} onToggle={() => toggle(row.row)} />
                                </span>
                            </li>
                        ))}
                    </ul>
                )}
                {note ? <p className="border-t border-line bg-subtle px-4 py-3 text-[13px] text-fg-muted">{note}</p> : null}
            </section>

            <div className="flex flex-wrap items-center gap-3 max-md:flex-col max-md:items-stretch">
                <Button variant="secondary" href={route('assistant.import.mapping', { import: file.id })} className="max-md:order-4">
                    Atrás
                </Button>
                <p role="note" className="flex items-start gap-2 text-sm text-fg-soft md:ml-auto">
                    {error_rows.length > 0 ? (
                        <span className="mt-0.5 text-status-failure">
                            <Icon name="alert-triangle" size={16} />
                        </span>
                    ) : null}
                    {skippedNote(error_rows, importable)}
                </p>
                {error_rows.length > 0 ? (
                    <a href={route('assistant.import.errors', { import: file.id })} download className={buttonClasses({ variant: 'secondary', size: 'lg' })}>
                        <Icon name="download" size={16} />
                        Descargar errores
                    </a>
                ) : null}
                <Button size="lg" disabled={importable === 0} onClick={() => setConfirming(true)}>
                    {importButtonLabel(importable)}
                </Button>
            </div>

            <ConfirmDialog
                open={confirming}
                onClose={() => setConfirming(false)}
                onConfirm={confirmImport}
                processing={importing}
                tone="primary"
                title={`¿${importButtonLabel(importable)}?`}
                confirmLabel={importButtonLabel(importable)}
                description={
                    <>
                        Se crean {summary.new} {summary.new === 1 ? 'ficha nueva' : 'fichas nuevas'} y se{' '}
                        {summary.update === 1 ? 'actualiza 1 existente' : `actualizan ${summary.update} existentes`}
                        {updatedKeys.length > 0 ? ` (${joinEs(updatedKeys)})` : ''}.{' '}
                        {error_rows.length > 0 ? `${error_rows.length === 1 ? 'La fila' : 'Las filas'} ${joinEs(error_rows.map(String))} no se importa${error_rows.length === 1 ? '' : 'n'}. ` : ''}
                        El asistente usa las fichas desde que terminen de importarse.
                    </>
                }
            />
        </div>
    );

    return (
        <AssistantLayout
            shared={props}
            title="Vista previa · Importar desde Excel"
            tab="import"
            counts={manage ? { drafts: drafts_count, unanswered: unanswered_count } : undefined}
        >
            {content}
        </AssistantLayout>
    );
}
