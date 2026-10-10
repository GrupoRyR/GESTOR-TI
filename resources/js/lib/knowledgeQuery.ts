/**
 * Query string of `assistant.knowledge.index` (boards C-Knowledge,
 * C-KnowledgePhone): Spanish keys, only the active filters, page 1 omitted.
 */
import type { KnowledgeFilters } from '../types/pages/assistant-knowledge';

export const DEFAULT_KNOWLEDGE_FILTERS: KnowledgeFilters = {
    q: null,
    type: null,
    company_id: null,
    area_id: null,
    visibility: null,
    overdue: false,
};

export type KnowledgeQuery = Record<string, string | number>;

/** `{ q, tipo, empresa, area, visibilidad, vencidas: 1, page }` with only what is set. */
export function knowledgeQuery(filters: KnowledgeFilters, page = 1): KnowledgeQuery {
    const query: KnowledgeQuery = {};
    const q = filters.q?.trim();
    if (q) query.q = q;
    if (filters.type) query.tipo = filters.type;
    if (filters.company_id !== null) query.empresa = filters.company_id;
    if (filters.area_id !== null) query.area = filters.area_id;
    if (filters.visibility) query.visibilidad = filters.visibility;
    if (filters.overdue) query.vencidas = 1;
    if (page > 1) query.page = page;
    return query;
}

export function isKnowledgeFiltered(filters: KnowledgeFilters): boolean {
    return Object.keys(knowledgeQuery(filters)).length > 0;
}

/** Filters behind the phone "Filtros" button: empresa, área and visibilidad. */
export function activeFilterCount(filters: KnowledgeFilters): number {
    return [filters.company_id !== null, filters.area_id !== null, filters.visibility !== null].filter(Boolean).length;
}
