import { describe, expect, it } from 'vitest';
import type { ImportRowResult, ImportSummary } from '../types/assistant';
import {
    changeSummary,
    filterPreviewRows,
    importableCount,
    importButtonLabel,
    previewFilterTotal,
    previewQuery,
    shownRowsNote,
    skippedNote,
} from './importPreview';

const row = (number: number, outcome: ImportRowResult['outcome']): ImportRowResult => ({
    row: number,
    title: `Fila ${number}`,
    context: 'Segurex · Ventas',
    outcome,
    card: null,
    changes: [],
    visibility: outcome === 'error' ? null : 'company',
    owner_name: null,
    error: outcome === 'error' ? { message: 'Mal', fix: 'Arreglar' } : null,
});

const ROWS = [row(17, 'error'), row(2, 'update'), row(9, 'update'), row(24, 'update'), row(3, 'new'), row(4, 'new')];
const SUMMARY: ImportSummary = { total: 32, new: 28, update: 3, error: 1 };

describe('filterPreviewRows', () => {
    it('keeps every row for "all" and only the outcome otherwise, in order', () => {
        expect(filterPreviewRows(ROWS, 'all')).toHaveLength(6);
        expect(filterPreviewRows(ROWS, 'update').map((item) => item.row)).toEqual([2, 9, 24]);
        expect(filterPreviewRows(ROWS, 'error').map((item) => item.row)).toEqual([17]);
    });
});

describe('previewQuery', () => {
    it('omits the default filter and sends the outcome as "resultado"', () => {
        expect(previewQuery('all')).toEqual({});
        expect(previewQuery('error')).toEqual({ resultado: 'error' });
    });
});

describe('importableCount and the import button', () => {
    it('counts new and updated rows, never the errors', () => {
        expect(importableCount(SUMMARY)).toBe(31);
        expect(importableCount({ total: 2, new: 0, update: 0, error: 2 })).toBe(0);
    });

    it('labels the button with the singular or plural', () => {
        expect(importButtonLabel(31)).toBe('Importar 31 fichas');
        expect(importButtonLabel(1)).toBe('Importar 1 ficha');
    });
});

describe('previewFilterTotal', () => {
    it('returns the summary count of the filter', () => {
        expect(previewFilterTotal(SUMMARY, 'all')).toBe(32);
        expect(previewFilterTotal(SUMMARY, 'new')).toBe(28);
    });
});

describe('changeSummary', () => {
    it('names the changed fields with the right verb', () => {
        expect(changeSummary([{ label: 'Descripción', from: 'a', to: 'b' }])).toBe('1 campo cambia: Descripción');
        expect(
            changeSummary([
                { label: 'Descripción', from: 'a', to: 'b' },
                { label: 'Dueño', from: null, to: 'Andrés Méndez' },
            ]),
        ).toBe('2 campos cambian: Descripción y Dueño');
        expect(
            changeSummary([
                { label: 'A', from: null, to: '1' },
                { label: 'B', from: null, to: '2' },
                { label: 'C', from: null, to: '3' },
            ]),
        ).toBe('3 campos cambian: A, B y C');
    });

    it('says when nothing changes', () => {
        expect(changeSummary([])).toBe('Sin cambios: la ficha queda igual');
    });
});

describe('skippedNote', () => {
    it('names the skipped rows and how many still import', () => {
        expect(skippedNote([17], 31)).toBe('La fila 17 se salta. Las otras 31 sí se importan.');
        expect(skippedNote([17, 20], 30)).toBe('Las filas 17 y 20 se saltan. Las otras 30 sí se importan.');
        expect(skippedNote([17], 1)).toBe('La fila 17 se salta. La otra sí se importa.');
    });

    it('handles no errors and nothing importable', () => {
        expect(skippedNote([], 32)).toBe('Las 32 filas se importan.');
        expect(skippedNote([], 1)).toBe('La fila se importa.');
        expect(skippedNote([3, 4], 0)).toBe('Las filas 3 y 4 se saltan. No queda nada para importar.');
    });
});

describe('shownRowsNote', () => {
    it('is null when every row of the filter is shown', () => {
        expect(shownRowsNote(filterPreviewRows(ROWS, 'update'), SUMMARY, 'update')).toBeNull();
    });

    it('explains that the hidden rows are plain new cards', () => {
        expect(shownRowsNote(ROWS, SUMMARY, 'all')).toBe('Mostrando 6 de 32 filas · las otras 26 son fichas nuevas sin problemas');
        expect(shownRowsNote(filterPreviewRows(ROWS, 'new'), SUMMARY, 'new')).toBe(
            'Mostrando 2 de 28 filas · las otras 26 son fichas nuevas sin problemas',
        );
    });

    it('stays neutral when hidden rows are not all new', () => {
        expect(shownRowsNote([row(3, 'new')], SUMMARY, 'all')).toBe('Mostrando 1 de 32 filas');
    });
});
