import { describe, expect, it } from 'vitest';
import { autosaveText, compareLabel, DOC_TEMPLATES, groupDocs, templateName } from './docs';

const NOW = new Date('2026-10-07T09:53:00-05:00');

describe('DOC_TEMPLATES', () => {
    it('offers the eight approved templates with their sections', () => {
        expect(DOC_TEMPLATES).toHaveLength(8);
        expect(DOC_TEMPLATES.every((template) => template.sections.length >= 4)).toBe(true);
        expect(DOC_TEMPLATES.filter((template) => template.warning).map((template) => template.key)).toEqual(['architecture']);
        expect(templateName('user_manual')).toBe('Manual de usuario');
        expect(templateName('blank')).toBe('En blanco');
    });
});

describe('groupDocs', () => {
    it('groups by owner in first-seen order', () => {
        const p07 = { kind: 'project' as const, key: 'P-07', name: 'Visualizador' };
        const vis = { kind: 'product' as const, key: 'visualizador-cartera', name: 'Visualizador de cartera' };
        const groups = groupDocs([
            { id: 1, owner: p07 },
            { id: 4, owner: vis },
            { id: 2, owner: p07 },
        ]);
        expect(groups.map((group) => [group.owner.key, group.docs.map((doc) => doc.id)])).toEqual([
            ['P-07', [1, 2]],
            ['visualizador-cartera', [4]],
        ]);
    });

    it('keeps a project and a product with the same key apart', () => {
        const groups = groupDocs([
            { id: 1, owner: { kind: 'project' as const, key: 'x', name: 'X' } },
            { id: 2, owner: { kind: 'product' as const, key: 'x', name: 'X' } },
        ]);
        expect(groups).toHaveLength(2);
    });
});

describe('autosaveText', () => {
    it('says when it was saved and the version', () => {
        expect(autosaveText({ state: 'saved', savedAt: '2026-10-07T09:52:00-05:00', version: 7, now: NOW })).toBe('Guardado hace 1 min · versión 7');
    });

    it('says when it is saving, has unsaved changes or failed', () => {
        expect(autosaveText({ state: 'saving', savedAt: null, version: 7, now: NOW })).toBe('Guardando…');
        expect(autosaveText({ state: 'dirty', savedAt: null, version: 7, now: NOW })).toBe('Cambios sin guardar');
        expect(autosaveText({ state: 'error', savedAt: '2026-10-07T09:52:00-05:00', version: 7, now: NOW })).toBe('No se pudo guardar: tus cambios siguen aquí');
    });
});

describe('compareLabel', () => {
    it('names an older version against the current one', () => {
        expect(compareLabel(6, 7, 7)).toBe('Versión 6 comparada con la actual (7)');
    });

    it('names a version against the previous one', () => {
        expect(compareLabel(7, 6, 7)).toBe('Versión 7 (actual) comparada con la 6');
        expect(compareLabel(4, 3, 7)).toBe('Versión 4 comparada con la 3');
    });
});
