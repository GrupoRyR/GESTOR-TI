import { describe, expect, it } from 'vitest';
import { activeFilterCount, DEFAULT_KNOWLEDGE_FILTERS, isKnowledgeFiltered, knowledgeQuery } from './knowledgeQuery';

describe('knowledgeQuery', () => {
    it('sends nothing for the default list', () => {
        expect(knowledgeQuery(DEFAULT_KNOWLEDGE_FILTERS)).toEqual({});
    });

    it('maps every active filter to its Spanish query key', () => {
        expect(
            knowledgeQuery({ q: 'ventas', type: 'report', company_id: 1, area_id: 11, visibility: 'it', overdue: true }, 3),
        ).toEqual({ q: 'ventas', tipo: 'report', empresa: 1, area: 11, visibilidad: 'it', vencidas: 1, page: 3 });
    });

    it('trims the search and drops it when blank', () => {
        expect(knowledgeQuery({ ...DEFAULT_KNOWLEDGE_FILTERS, q: '  SAP  ' })).toEqual({ q: 'SAP' });
        expect(knowledgeQuery({ ...DEFAULT_KNOWLEDGE_FILTERS, q: '   ' })).toEqual({});
    });

    it('omits page 1 so a filter change goes back to the first page', () => {
        expect(knowledgeQuery({ ...DEFAULT_KNOWLEDGE_FILTERS, overdue: true }, 1)).toEqual({ vencidas: 1 });
    });
});

describe('isKnowledgeFiltered', () => {
    it('is false only for the default filters', () => {
        expect(isKnowledgeFiltered(DEFAULT_KNOWLEDGE_FILTERS)).toBe(false);
        expect(isKnowledgeFiltered({ ...DEFAULT_KNOWLEDGE_FILTERS, q: ' ' })).toBe(false);
        expect(isKnowledgeFiltered({ ...DEFAULT_KNOWLEDGE_FILTERS, q: 'vpn' })).toBe(true);
        expect(isKnowledgeFiltered({ ...DEFAULT_KNOWLEDGE_FILTERS, type: 'glossary' })).toBe(true);
        expect(isKnowledgeFiltered({ ...DEFAULT_KNOWLEDGE_FILTERS, overdue: true })).toBe(true);
    });
});

describe('activeFilterCount', () => {
    it('counts the filters behind the phone "Filtros" button (empresa, área, visibilidad)', () => {
        expect(activeFilterCount(DEFAULT_KNOWLEDGE_FILTERS)).toBe(0);
        expect(activeFilterCount({ ...DEFAULT_KNOWLEDGE_FILTERS, company_id: 2, visibility: 'company', type: 'report', overdue: true })).toBe(2);
    });
});
