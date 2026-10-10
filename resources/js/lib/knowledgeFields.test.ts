import { describe, expect, it } from 'vitest';
import type { KnowledgeCardBody } from '../types/assistant';
import { bodyFromForm, cardFieldRows, defaultReviewDate, formValuesFromBody, isPlaceholderText, KNOWLEDGE_FIELDS } from './knowledgeFields';

const REPORT: KnowledgeCardBody = {
    type: 'report',
    fields: {
        shows: 'Los pedidos del día anterior',
        source_system: 'Toma Pedidos SAP',
        recipients: 'Gerencia',
        schedule: null,
        format: 'Excel adjunto por correo',
    },
};

describe('KNOWLEDGE_FIELDS', () => {
    it('lists the fields of each type in the board order', () => {
        expect(KNOWLEDGE_FIELDS.system.map((field) => field.key)).toEqual(['what_is', 'runs_on', 'access', 'version']);
        expect(KNOWLEDGE_FIELDS.report.map((field) => field.key)).toEqual(['shows', 'source_system', 'recipients', 'schedule', 'format']);
        expect(KNOWLEDGE_FIELDS.data_flow.map((field) => field.key)).toEqual(['origin_destination', 'transport', 'frequency', 'on_failure']);
        expect(KNOWLEDGE_FIELDS.procedure.map((field) => field.key)).toEqual(['steps', 'who_can']);
        expect(KNOWLEDGE_FIELDS.glossary.map((field) => field.key)).toEqual(['term', 'definition', 'also_called']);
    });

    it('requires exactly the non-nullable field of each type', () => {
        const required = Object.fromEntries(
            Object.entries(KNOWLEDGE_FIELDS).map(([type, fields]) => [type, fields.filter((field) => field.required).map((field) => field.key)]),
        );
        expect(required).toEqual({
            system: ['what_is'],
            report: ['shows'],
            data_flow: ['origin_destination'],
            procedure: ['steps'],
            glossary: ['term', 'definition'],
        });
    });
});

describe('cardFieldRows', () => {
    it('labels each field and keeps empty ones as null', () => {
        expect(cardFieldRows(REPORT)).toEqual([
            { key: 'shows', label: 'Qué muestra', value: 'Los pedidos del día anterior' },
            { key: 'source_system', label: 'Quién lo envía · sistema origen', value: 'Toma Pedidos SAP' },
            { key: 'recipients', label: 'Destinatarios', value: 'Gerencia' },
            { key: 'schedule', label: 'Horario', value: null },
            { key: 'format', label: 'Formato', value: 'Excel adjunto por correo' },
        ]);
    });

    it('returns the procedure steps as a list', () => {
        const rows = cardFieldRows({ type: 'procedure', fields: { steps: ['Abrir SAP', 'Ir a [RUTA EN SAP]'], who_can: null } });
        expect(rows[0]).toEqual({ key: 'steps', label: 'Pasos numerados', value: ['Abrir SAP', 'Ir a [RUTA EN SAP]'] });
        expect(rows[1]).toEqual({ key: 'who_can', label: 'Quién lo puede hacer', value: null });
    });
});

describe('formValuesFromBody / bodyFromForm', () => {
    it('round-trips a card body through the flat form values', () => {
        const { values, steps } = formValuesFromBody(REPORT);
        expect(values).toMatchObject({ shows: 'Los pedidos del día anterior', schedule: '' });
        expect(steps).toEqual(['']);
        expect(bodyFromForm('report', values, steps)).toEqual(REPORT);
    });

    it('trims text, turns empty optional fields into null and ignores other types', () => {
        const body = bodyFromForm('glossary', { term: ' Cupo ', definition: 'Límite de crédito', also_called: '  ', shows: 'x' }, ['']);
        expect(body).toEqual({ type: 'glossary', fields: { term: 'Cupo', definition: 'Límite de crédito', also_called: null } });
    });

    it('keeps the non-empty procedure steps in order', () => {
        const body = bodyFromForm('procedure', { who_can: 'Cartera' }, [' Abrir SAP ', '', 'Ir a [RUTA EN SAP]']);
        expect(body).toEqual({ type: 'procedure', fields: { steps: ['Abrir SAP', 'Ir a [RUTA EN SAP]'], who_can: 'Cartera' } });
    });

    it('starts a procedure form with its steps, or one empty step', () => {
        expect(formValuesFromBody({ type: 'procedure', fields: { steps: ['Uno', 'Dos'], who_can: null } }).steps).toEqual(['Uno', 'Dos']);
        expect(formValuesFromBody(null).steps).toEqual(['']);
    });
});

describe('defaultReviewDate', () => {
    it('is six months after today', () => {
        expect(defaultReviewDate('2026-10-07')).toBe('2027-04-07');
        expect(defaultReviewDate('2026-03-15')).toBe('2026-09-15');
    });

    it('clamps to the last day of a shorter month', () => {
        expect(defaultReviewDate('2026-08-31')).toBe('2027-02-28');
        expect(defaultReviewDate('2027-08-31')).toBe('2028-02-29');
    });

    it('accepts another number of months', () => {
        expect(defaultReviewDate('2026-10-07', 3)).toBe('2027-01-07');
    });
});

describe('isPlaceholderText', () => {
    it('spots bracket placeholders for unknown facts', () => {
        expect(isPlaceholderText('[VERSIÓN]')).toBe(true);
        expect(isPlaceholderText(' [RUTA EN SAP] ')).toBe(true);
        expect(isPlaceholderText('Ir a [RUTA EN SAP].')).toBe(false);
        expect(isPlaceholderText('Servidor local')).toBe(false);
        expect(isPlaceholderText(null)).toBe(false);
    });
});
