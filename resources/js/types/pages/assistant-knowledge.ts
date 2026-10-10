/**
 * Asistente TI · base de conocimiento (boards C-Knowledge, C-KnowledgePhone,
 * C-KnowledgeCard, C-KnowledgeForm, C-KnowledgeTypes). Desktop-first; the
 * list is phone-first too (rows become cards, filters go into a drawer). The
 * types board is not a route: it is the "Tipos de ficha" drawer reachable from
 * the list and the form.
 *
 * Inertia components and their props (AssistantLayout tab "knowledge"):
 * - `Assistant/Knowledge/Index`   KnowledgeIndexProps   C-Knowledge, C-KnowledgePhone
 * - `Assistant/Knowledge/Show`    KnowledgeShowProps    C-KnowledgeCard
 * - `Assistant/Knowledge/Create`  KnowledgeCreateProps  C-KnowledgeForm (blank or prefilled)
 * - `Assistant/Knowledge/Edit`    KnowledgeEditProps    C-KnowledgeForm (type fixed, "Eliminar ficha")
 *
 * Ziggy routes used:
 * - `assistant.knowledge.index`    GET     query `{ q?, tipo?: KnowledgeType, empresa?: company id, area?: area id,
 *                                          visibilidad?: KnowledgeVisibility, vencidas?: 1, page? }`; absent = no filter
 *                                          (see `lib/knowledgeQuery.ts`). Filter changes use preserveState,
 *                                          preserveScroll and replace; the search is debounced 300 ms.
 * - `assistant.knowledge.show`     GET     `{ card: key }`                     row link, related card, after store/update.
 * - `assistant.knowledge.create`   GET     query `{ pregunta?: unanswered id, borrador?: draft id }`
 *                                          "Nueva ficha"; with a query the server sends `prefill` (origin + values).
 * - `assistant.knowledge.store`    POST    body `KnowledgeCardPayload`         redirects to `.show`; errors Laravel-shaped
 *                                          (`title`, `fields.recipients`, `fields.steps.0`, `company_ids`, …).
 * - `assistant.knowledge.edit`     GET     `{ card: key }`                     "Editar".
 * - `assistant.knowledge.update`   PUT     `{ card: key }` body `KnowledgeCardPayload` (`type` cannot change).
 * - `assistant.knowledge.destroy`  DELETE  `{ card: key }`                     "Eliminar ficha" after ConfirmDialog;
 *                                          redirects to `.index` with a flash.
 * - `assistant.knowledge.review`   POST    `{ card: key }`                     "Marcar como revisada": `review_at` = today
 *                                          + 6 months; answers with the updated `card` (preserveScroll).
 * - `assistant.import.create`      GET     —                                   "Importar desde Excel" (T4).
 * - `assistant.index`              GET     query `{ fuente: key }`             "Preguntar al asistente" (opens the chat with
 *                                          the card as source preview).
 * - `ai.products.show`             GET     `{ product: slug }`                 related product.
 *
 * Permissions (checked with `can()`; the server is the authority):
 * - `assistant.knowledge.manage`   everything that writes: Nueva ficha, Importar desde Excel, Editar, Eliminar,
 *                                  Marcar como revisada; the visibility filter, the overdue toggle, the review
 *                                  column, usage and recent changes. Create/Edit require it.
 * - `assistant.use`                read-only list and card. The server sends only the cards this person may see
 *                                  ("Toda la empresa"); `counts` count only those.
 * - Never put passwords in a card: credentials live in Accesos or the Bóveda (`ai.vault.access`).
 */
import type { PersonRef, ProductRef } from '@/types/ai';
import type {
    KnowledgeArea,
    KnowledgeCard,
    KnowledgeCardBody,
    KnowledgeCardRef,
    KnowledgeCardSummary,
    KnowledgeCounts,
    KnowledgeType,
    KnowledgeVisibility,
    LearnChannel,
    UnansweredQuestion,
} from '@/types/assistant';
import type { FormErrors, Paginated } from '@/types/shared';

// ---- list -------------------------------------------------------------------

/** Current list filters as the server read them from the query. */
export interface KnowledgeFilters {
    /** Free text on title, key and fields. */
    q: string | null;
    type: KnowledgeType | null;
    company_id: number | null;
    area_id: number | null;
    /** Managers only. */
    visibility: KnowledgeVisibility | null;
    /** "Revisión vencida" toggle (managers only). */
    overdue: boolean;
}

/** A sample card per type for the "Tipos de ficha" drawer; null when none is visible. */
export type KnowledgeTypeExamples = Record<KnowledgeType, KnowledgeCardRef | null>;

export interface KnowledgeIndexProps {
    /** After the filters, last edited first, 8 per page (as on the board). */
    cards: Paginated<KnowledgeCardSummary>;
    filters: KnowledgeFilters;
    /** Unfiltered counters of the cards this person may see (header, type chips, overdue toggle). */
    counts: KnowledgeCounts;
    /** Areas that have cards, for the Área filter. */
    area_options: KnowledgeArea[];
    type_examples: KnowledgeTypeExamples;
    /** Bogotá date `YYYY-MM-DD` for the review labels. */
    today: string;
    /** Tab counts; 0 without `assistant.knowledge.manage`. */
    unanswered_count: number;
    drafts_count: number;
}

// ---- card -------------------------------------------------------------------

export interface KnowledgeShowProps {
    card: KnowledgeCard;
    today: string;
    /** Server time, ISO 8601 (relative dates). */
    now: string;
    unanswered_count: number;
    drafts_count: number;
}

// ---- form -------------------------------------------------------------------

/** Body of `assistant.knowledge.store` and `.update`. */
export interface KnowledgeCardPayload {
    type: KnowledgeType;
    title: string;
    description: string | null;
    /** Fields of `type` only (see `lib/knowledgeFields.ts`). Steps keep their order. */
    fields: KnowledgeCardBody['fields'];
    /** One or both companies. */
    company_ids: number[];
    area_id: number | null;
    /** A person or a group ("Gerencia de cartera"). */
    owner_id: number | null;
    visibility: KnowledgeVisibility;
    /** `YYYY-MM-DD`; 6 months from today by default. */
    review_at: string;
    related_card_keys: string[];
    related_product_slugs: string[];
    /** Create only: the unanswered question it answers (the server marks it documented). */
    unanswered_id: number | null;
    /** Create only: the /aprender draft it comes from (the server marks it approved). */
    draft_id: number | null;
}

/** Where a prefilled card comes from (shown under the title). */
export type KnowledgeOrigin =
    | { kind: 'unanswered'; question: UnansweredQuestion }
    | { kind: 'draft'; draft_id: number; author: PersonRef; channel: LearnChannel; message: string };

export interface KnowledgePrefill {
    origin: KnowledgeOrigin;
    /** Values the form starts with (title from the question, a draft's fields, …). */
    values: Partial<Omit<KnowledgeCardPayload, 'unanswered_id' | 'draft_id'>>;
}

/** Item of the "Relacionadas" search. */
export type KnowledgeRelatedOption = { kind: 'card'; card: KnowledgeCardRef } | { kind: 'product'; product: ProductRef };

interface KnowledgeFormProps {
    area_options: KnowledgeArea[];
    /** People and groups that can own a card. */
    owner_options: PersonRef[];
    /** Cards and products to relate (the card itself excluded on Edit). */
    related_options: KnowledgeRelatedOption[];
    /** Holders of `assistant.knowledge.manage` ("Solo TI" explanation). */
    knowledge_managers: PersonRef[];
    type_examples: KnowledgeTypeExamples;
    type_counts: Record<KnowledgeType, number>;
    today: string;
    unanswered_count: number;
    drafts_count: number;
    /** Validation errors from store/update (Laravel shape). */
    errors?: FormErrors;
}

export interface KnowledgeCreateProps extends KnowledgeFormProps {
    /** From `?pregunta=` or `?borrador=`; null for a blank card. */
    prefill: KnowledgePrefill | null;
}

export interface KnowledgeEditProps extends KnowledgeFormProps {
    card: KnowledgeCard;
}
