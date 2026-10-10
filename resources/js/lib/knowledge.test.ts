import { describe, expect, it } from 'vitest';
import {
    KNOWLEDGE_TYPE_ORDER,
    KNOWLEDGE_TYPES,
    REVIEW_SOON_DAYS,
    companyScopeLabel,
    daysUntil,
    knowledgeTypeLabel,
    reviewLabel,
    reviewSentence,
    reviewStatus,
    visibilityLabel,
} from './knowledge';

const TODAY = '2026-10-07';

describe('knowledge types', () => {
    it('lists the five types in the approved order with Spanish labels', () => {
        expect(KNOWLEDGE_TYPE_ORDER).toEqual(['system', 'report', 'data_flow', 'procedure', 'glossary']);
        expect(KNOWLEDGE_TYPE_ORDER.map(knowledgeTypeLabel)).toEqual(['Sistema', 'Informe', 'Flujo de datos', 'Procedimiento', 'Glosario']);
    });

    it('gives every type a neutral icon and a purpose line', () => {
        for (const type of KNOWLEDGE_TYPE_ORDER) {
            expect(KNOWLEDGE_TYPES[type].icon).toMatch(/^kb-/);
            expect(KNOWLEDGE_TYPES[type].hint.length).toBeGreaterThan(10);
        }
    });

    it('labels visibility', () => {
        expect(visibilityLabel('it')).toBe('Solo TI');
        expect(visibilityLabel('company')).toBe('Toda la empresa');
    });
});

describe('daysUntil', () => {
    it('counts calendar days from today, negative when past', () => {
        expect(daysUntil('2027-01-15', TODAY)).toBe(100);
        expect(daysUntil('2026-10-07', TODAY)).toBe(0);
        expect(daysUntil('2026-09-30', TODAY)).toBe(-7);
    });
});

describe('reviewStatus', () => {
    it('is overdue once the review date has passed', () => {
        expect(reviewStatus('2026-09-30', TODAY)).toBe('overdue');
        expect(reviewStatus('2026-10-01', TODAY)).toBe('overdue');
    });

    it('is due soon from today up to the warning window', () => {
        expect(REVIEW_SOON_DAYS).toBe(30);
        expect(reviewStatus('2026-10-07', TODAY)).toBe('due_soon');
        expect(reviewStatus('2026-11-06', TODAY)).toBe('due_soon');
    });

    it('is ok beyond the window', () => {
        expect(reviewStatus('2026-11-30', TODAY)).toBe('ok');
        expect(reviewStatus('2027-01-15', TODAY)).toBe('ok');
    });
});

describe('reviewLabel', () => {
    it('says "Vencida" with the short date when overdue', () => {
        expect(reviewLabel('2026-09-30', TODAY)).toBe('Vencida 30 sep');
        expect(reviewLabel('2026-10-01', TODAY)).toBe('Vencida 1 oct');
    });

    it('says "Revisar" and adds the year only outside the current one', () => {
        expect(reviewLabel('2026-11-30', TODAY)).toBe('Revisar 30 nov');
        expect(reviewLabel('2027-01-15', TODAY)).toBe('Revisar 15 ene 2027');
    });
});

describe('reviewSentence', () => {
    it('counts the days left or late', () => {
        expect(reviewSentence('2027-01-15', TODAY)).toBe('Faltan 100 días');
        expect(reviewSentence('2026-10-08', TODAY)).toBe('Falta 1 día');
        expect(reviewSentence('2026-10-07', TODAY)).toBe('Vence hoy');
        expect(reviewSentence('2026-10-06', TODAY)).toBe('Venció hace 1 día');
        expect(reviewSentence('2026-09-30', TODAY)).toBe('Venció hace 7 días');
    });
});

describe('companyScopeLabel', () => {
    const names = { 1: 'Segurex', 2: 'Derivados' };
    it('names one company or both', () => {
        expect(companyScopeLabel([1], names)).toBe('Segurex');
        expect(companyScopeLabel([1, 2], names)).toBe('Segurex y Derivados');
        expect(companyScopeLabel([2, 1], names)).toBe('Segurex y Derivados');
        expect(companyScopeLabel([], names)).toBe('Sin empresa');
    });
});
