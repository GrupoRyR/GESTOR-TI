/**
 * Excel import, step 3 (board C-ImportPreview): the outcome filter, counts
 * and the sentences around the rows. The server filters with `?resultado=`
 * and decides each outcome; these only word the result.
 */
import type { ImportFieldChange, ImportRowOutcome, ImportRowResult, ImportSummary } from '../types/assistant';
import { joinEs } from './curation';

/** Summary tile pressed: every row or one outcome. */
export type ImportPreviewFilter = 'all' | ImportRowOutcome;

export function filterPreviewRows(rows: readonly ImportRowResult[], filter: ImportPreviewFilter): ImportRowResult[] {
    return filter === 'all' ? [...rows] : rows.filter((row) => row.outcome === filter);
}

/** Query of `assistant.import.preview`: nothing for "all". */
export function previewQuery(filter: ImportPreviewFilter): { resultado?: ImportRowOutcome } {
    return filter === 'all' ? {} : { resultado: filter };
}

/** Rows of the filter according to the summary. */
export function previewFilterTotal(summary: ImportSummary, filter: ImportPreviewFilter): number {
    return filter === 'all' ? summary.total : summary[filter];
}

/** New plus updated rows; error rows are skipped. */
export function importableCount(summary: ImportSummary): number {
    return summary.new + summary.update;
}

export function importButtonLabel(count: number): string {
    return `Importar ${count} ${count === 1 ? 'ficha' : 'fichas'}`;
}

/** "1 campo cambia: Descripción", "2 campos cambian: Descripción y Dueño". */
export function changeSummary(changes: readonly ImportFieldChange[]): string {
    if (changes.length === 0) return 'Sin cambios: la ficha queda igual';
    const labels = joinEs(changes.map((change) => change.label));
    return changes.length === 1 ? `1 campo cambia: ${labels}` : `${changes.length} campos cambian: ${labels}`;
}

/** "La fila 17 se salta. Las otras 31 sí se importan." */
export function skippedNote(errorRows: readonly number[], importable: number): string {
    if (errorRows.length === 0) return importable === 1 ? 'La fila se importa.' : `Las ${importable} filas se importan.`;
    const skipped = errorRows.length === 1 ? `La fila ${errorRows[0]} se salta.` : `Las filas ${joinEs(errorRows.map(String))} se saltan.`;
    if (importable === 0) return `${skipped} No queda nada para importar.`;
    return importable === 1 ? `${skipped} La otra sí se importa.` : `${skipped} Las otras ${importable} sí se importan.`;
}

/**
 * The server sends the rows worth reviewing (errors, updates) and a sample of
 * the new ones. Null when every row of the filter is on screen.
 */
export function shownRowsNote(shown: readonly ImportRowResult[], summary: ImportSummary, filter: ImportPreviewFilter): string | null {
    const total = previewFilterTotal(summary, filter);
    if (shown.length >= total) return null;
    const hidden = total - shown.length;
    const shownNew = shown.filter((row) => row.outcome === 'new').length;
    const hiddenNew = filter === 'all' || filter === 'new' ? summary.new - shownNew : 0;
    const base = `Mostrando ${shown.length} de ${total} filas`;
    if (hiddenNew !== hidden) return base;
    return `${base} · ${hidden === 1 ? 'la otra es una ficha nueva sin problemas' : `las otras ${hidden} son fichas nuevas sin problemas`}`;
}
