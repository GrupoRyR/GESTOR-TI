import { describe, expect, it } from 'vitest';
import { autoMap, fieldsFor, mappingSummary, missingRequired, normalizeHeader, setColumnField } from './importMapping';

const HEADERS = ['Nombre', 'Tipo', 'Empresa', 'Área', 'Descripción', 'Sistema origen', 'Destinatarios', 'Horario', 'Visibilidad', 'Dueño', 'Notas'];
const SAMPLES = ['Informe de ventas diario', 'Informe', 'Segurex', 'Ventas', 'Pedidos del día anterior', 'Toma Pedidos SAP', 'Gerencia', '6:00 a. m.', 'Toda la empresa', 'Paola Ríos', 'Confirmar'];

const byName = (mapping: ReturnType<typeof autoMap>, name: string) => mapping.find((column) => column.name === name);

describe('normalizeHeader', () => {
    it('drops accents, case, punctuation and extra spaces', () => {
        expect(normalizeHeader('  Área ')).toBe('area');
        expect(normalizeHeader('DUEÑO')).toBe('dueno');
        expect(normalizeHeader('Sistema  origen:')).toBe('sistema origen');
        expect(normalizeHeader('Quién lo recibe?')).toBe('quien lo recibe');
        expect(normalizeHeader('Horario_de-envío')).toBe('horario de envio');
    });
});

describe('fieldsFor', () => {
    it('gives every type the common fields plus its own', () => {
        const report = fieldsFor('report').map((field) => field.key);
        expect(report).toEqual(expect.arrayContaining(['title', 'type', 'company', 'area', 'description', 'visibility', 'owner']));
        expect(report).toEqual(expect.arrayContaining(['shows', 'source_system', 'recipients', 'schedule', 'format']));
        expect(fieldsFor('glossary').map((field) => field.key)).toEqual(expect.arrayContaining(['term', 'definition', 'also_called']));
    });

    it('marks title, company and visibility as required', () => {
        const required = fieldsFor('system')
            .filter((field) => field.required)
            .map((field) => field.key);
        expect(required).toEqual(['title', 'company', 'visibility']);
    });
});

describe('autoMap', () => {
    const mapping = autoMap(HEADERS, 'report', SAMPLES);

    it('matches headers to fields by name and synonyms, ignoring accents and case', () => {
        expect(byName(mapping, 'Nombre')).toMatchObject({ field: 'title', match: 'auto', sample: 'Informe de ventas diario' });
        expect(byName(mapping, 'Área')).toMatchObject({ field: 'area', match: 'auto' });
        expect(byName(mapping, 'Descripción')).toMatchObject({ field: 'description', match: 'auto' });
        expect(byName(mapping, 'Sistema origen')).toMatchObject({ field: 'source_system', match: 'auto' });
        expect(byName(mapping, 'Destinatarios')).toMatchObject({ field: 'recipients', match: 'auto' });
        expect(byName(mapping, 'Horario')).toMatchObject({ field: 'schedule', match: 'auto' });
        expect(byName(mapping, 'Dueño')).toMatchObject({ field: 'owner', match: 'auto' });
    });

    it('fixes the type column to the sheet type', () => {
        expect(byName(mapping, 'Tipo')).toMatchObject({ field: 'type', match: 'fixed' });
    });

    it('skips unknown columns', () => {
        expect(byName(mapping, 'Notas')).toMatchObject({ field: null, match: 'skip' });
    });

    it('gives a field to one column only (the first)', () => {
        const twice = autoMap(['Nombre', 'Título'], 'report');
        expect(twice[0]).toMatchObject({ field: 'title', match: 'auto' });
        expect(twice[1]).toMatchObject({ field: null, match: 'skip' });
    });

    it('only uses the fields of the sheet type', () => {
        const system = autoMap(['Destinatarios', 'Versión'], 'system');
        expect(system[0]).toMatchObject({ field: null, match: 'skip' });
        expect(system[1]).toMatchObject({ field: 'version', match: 'auto' });
    });

    it('defaults samples to empty strings', () => {
        expect(autoMap(['Nombre'], 'report')[0]?.sample).toBe('');
    });
});

describe('setColumnField', () => {
    const mapping = autoMap(HEADERS, 'report', SAMPLES);

    it('marks a hand-picked field as manual and frees it from any other column', () => {
        const next = setColumnField(mapping, 'Notas', 'description');
        expect(byName(next, 'Notas')).toMatchObject({ field: 'description', match: 'manual' });
        expect(byName(next, 'Descripción')).toMatchObject({ field: null, match: 'skip' });
        expect(byName(mapping, 'Notas')).toMatchObject({ field: null, match: 'skip' });
    });

    it('skips a column when set to null', () => {
        expect(byName(setColumnField(mapping, 'Horario', null), 'Horario')).toMatchObject({ field: null, match: 'skip' });
    });

    it('never changes the fixed type column', () => {
        expect(byName(setColumnField(mapping, 'Tipo', 'title'), 'Tipo')).toMatchObject({ field: 'type', match: 'fixed' });
    });
});

describe('summary and required fields', () => {
    it('counts columns by match kind', () => {
        const mapping = setColumnField(autoMap(HEADERS, 'report', SAMPLES), 'Destinatarios', 'recipients');
        expect(mappingSummary(mapping)).toEqual({ total: 11, auto: 8, manual: 1, fixed: 1, skip: 1 });
    });

    it('lists required fields without a column', () => {
        expect(missingRequired(autoMap(HEADERS, 'report'), 'report')).toEqual([]);
        expect(missingRequired(autoMap(['Nombre', 'Notas'], 'report'), 'report').map((field) => field.key)).toEqual(['company', 'visibility']);
    });
});
