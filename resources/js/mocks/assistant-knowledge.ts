/**
 * Mocks for the Asistente TI knowledge base (boards C-Knowledge,
 * C-KnowledgePhone, C-KnowledgeCard, C-KnowledgeForm, C-KnowledgeTypes).
 * Cards come from `assistant-fixtures.ts`. The read-only variants use a
 * generic user with `assistant.use` only (today nobody outside IT has it);
 * that user's per-type counts are invented to add up to the 31 "Toda la
 * empresa" cards.
 */
import type { KnowledgeCardSummary, KnowledgeCounts } from '@/types/assistant';
import type {
    KnowledgeCreateProps,
    KnowledgeEditProps,
    KnowledgeFilters,
    KnowledgeIndexProps,
    KnowledgeRelatedOption,
    KnowledgeShowProps,
    KnowledgeTypeExamples,
} from '@/types/pages/assistant-knowledge';
import type { AuthUser, PageProps, Paginated, Permission, SharedProps } from '@/types/shared';
import { PEOPLE, PRODUCT_REFS } from './ai-fixtures';
import {
    ASSISTANT_HOLDERS,
    ASSISTANT_TAB_COUNTS,
    CARD_K009,
    CARD_K014,
    CARD_K018,
    CARD_K022,
    CARD_K027,
    CARD_K031,
    CARD_K035,
    cardRef,
    cardSummary,
    KNOWLEDGE_AREAS,
    KNOWLEDGE_CARDS,
    KNOWLEDGE_COUNTS,
    KNOWLEDGE_OVERDUE,
    KNOWLEDGE_PAGE_1,
    NOW_ISO,
    TODAY,
    UNANSWERED,
} from './assistant-fixtures';
import { DERIVADOS, SEGUREX, sharedFor, type MockVariant } from './shared';

type IndexPage = PageProps<KnowledgeIndexProps>;
type ShowPage = PageProps<KnowledgeShowProps>;
type CreatePage = PageProps<KnowledgeCreateProps>;
type EditPage = PageProps<KnowledgeEditProps>;

const PER_PAGE = 8;

/** Laravel-style page `page` of `total` rows; links point at the preview's inert route. */
function paginate<T>(rows: T[], total = rows.length, page = 1): Paginated<T> {
    const lastPage = Math.max(1, Math.ceil(total / PER_PAGE));
    const pageUrl = (n: number) => `#assistant.knowledge.index?page=${n}`;
    return {
        data: rows,
        links: [
            { url: page > 1 ? pageUrl(page - 1) : null, label: '&laquo; Anterior', active: false },
            ...Array.from({ length: lastPage }, (_, index) => ({ url: pageUrl(index + 1), label: String(index + 1), active: index + 1 === page })),
            { url: page < lastPage ? pageUrl(page + 1) : null, label: 'Siguiente &raquo;', active: false },
        ],
        meta: { current_page: page, last_page: lastPage, per_page: PER_PAGE, total },
    };
}

const NO_FILTERS: KnowledgeFilters = { q: null, type: null, company_id: null, area_id: null, visibility: null, overdue: false };

const EXAMPLES: KnowledgeTypeExamples = {
    system: cardRef(CARD_K014),
    report: cardRef(CARD_K031),
    data_flow: cardRef(CARD_K027),
    procedure: cardRef(CARD_K022),
    glossary: cardRef(CARD_K009),
};

/** Only "Toda la empresa" cards; the only data flow (K-027) is IT-only. */
const EXAMPLES_READONLY: KnowledgeTypeExamples = { ...EXAMPLES, system: cardRef(CARD_K035), data_flow: null };

const AREA_OPTIONS = Object.values(KNOWLEDGE_AREAS);

// ---- generic read-only user ---------------------------------------------------

/** Hypothetical user with `assistant.use` only (no management, no credentials). */
const READER: AuthUser = {
    id: 90,
    name: '[USUARIO CON ASISTENTE]',
    email: '[CORREO]',
    avatar_url: null,
    company_id: SEGUREX.id,
    roles: ['[ROL]'],
    permissions: ['assistant.use' satisfies Permission],
};

function readerShared(): SharedProps {
    const base = sharedFor('leonardo');
    return {
        ...base,
        auth: { user: READER },
        companies: base.companies.filter((company) => company.id === SEGUREX.id),
        current_company_id: SEGUREX.id,
        notifications_unread: 1,
    };
}

/** Invented split of the 31 "Toda la empresa" cards. */
const READER_COUNTS: KnowledgeCounts = {
    total: 31,
    by_type: { system: 4, report: 11, data_flow: 0, procedure: 10, glossary: 6 },
    by_visibility: { company: 31, it: 0 },
    overdue: 2,
};

const READER_PAGE_1: KnowledgeCardSummary[] = KNOWLEDGE_CARDS.filter((card) => card.visibility === 'company' && card.company_ids.includes(SEGUREX.id))
    .slice(0, PER_PAGE)
    .map(cardSummary);

// ---- list ---------------------------------------------------------------------

const index: IndexPage = {
    ...sharedFor('jhon'),
    cards: paginate(KNOWLEDGE_PAGE_1, KNOWLEDGE_COUNTS.total),
    filters: NO_FILTERS,
    counts: KNOWLEDGE_COUNTS,
    area_options: AREA_OPTIONS,
    type_examples: EXAMPLES,
    today: TODAY,
    unanswered_count: ASSISTANT_TAB_COUNTS.unanswered,
    drafts_count: ASSISTANT_TAB_COUNTS.drafts,
};

const systems = KNOWLEDGE_CARDS.filter((card) => card.type === 'system').map(cardSummary);

const EMPTY_COUNTS: KnowledgeCounts = {
    total: 0,
    by_type: { system: 0, report: 0, data_flow: 0, procedure: 0, glossary: 0 },
    by_visibility: { company: 0, it: 0 },
    overdue: 0,
};

// ---- card -----------------------------------------------------------------------

const show = (card: typeof CARD_K031): ShowPage => ({
    ...sharedFor('jhon'),
    card,
    today: TODAY,
    now: NOW_ISO,
    unanswered_count: ASSISTANT_TAB_COUNTS.unanswered,
    drafts_count: ASSISTANT_TAB_COUNTS.drafts,
});

// ---- form -------------------------------------------------------------------------

const RELATED_OPTIONS: KnowledgeRelatedOption[] = [
    ...KNOWLEDGE_CARDS.map((card) => ({ kind: 'card' as const, card: cardRef(card) })),
    ...Object.values(PRODUCT_REFS).map((product) => ({ kind: 'product' as const, product: { ...product } })),
];

const OWNER_OPTIONS = [PEOPLE.jhon, PEOPLE.leonardo, PEOPLE.paola, PEOPLE.juan, PEOPLE.andres, PEOPLE.monica, PEOPLE.cartera];

const formBase = {
    ...sharedFor('jhon', { currentCompanyId: SEGUREX.id }),
    area_options: AREA_OPTIONS,
    owner_options: OWNER_OPTIONS,
    related_options: RELATED_OPTIONS,
    knowledge_managers: ASSISTANT_HOLDERS,
    type_examples: EXAMPLES,
    type_counts: KNOWLEDGE_COUNTS.by_type,
    today: TODAY,
    unanswered_count: ASSISTANT_TAB_COUNTS.unanswered,
    drafts_count: ASSISTANT_TAB_COUNTS.drafts,
};

/** "¿Qué informe muestra las ventas por zona?" (2 veces). */
const SALES_BY_ZONE = UNANSWERED[1] ?? UNANSWERED[0];

const createPrefilled: CreatePage = {
    ...formBase,
    prefill: SALES_BY_ZONE
        ? {
              origin: { kind: 'unanswered', question: SALES_BY_ZONE },
              values: {
                  type: 'report',
                  title: 'Informe de ventas por zona',
                  fields: {
                      shows: 'Ventas del mes agrupadas por zona comercial y asesor',
                      source_system: 'SAP Business One',
                      recipients: null,
                      schedule: null,
                      format: 'Excel por correo',
                  },
                  company_ids: [SEGUREX.id],
                  area_id: KNOWLEDGE_AREAS.ventas.id,
                  owner_id: PEOPLE.paola.id,
                  visibility: 'company',
                  related_card_keys: [CARD_K031.key, CARD_K014.key],
              },
          }
        : null,
    errors: { 'fields.recipients': 'Escribe quién recibe el informe. El asistente lo usa para responder “¿a quién le llega?”.' },
};

const edit: EditPage = {
    ...formBase,
    card: CARD_K031,
    related_options: RELATED_OPTIONS.filter((option) => option.kind !== 'card' || option.card.key !== CARD_K031.key),
};

export const variants: Record<string, MockVariant<IndexPage | ShowPage | CreatePage | EditPage>> = {
    index: {
        page: 'Assistant/Knowledge/Index',
        label: 'Base de conocimiento · Jhon, 48 fichas (página 1 de 6)',
        props: index,
    },
    index_overdue: {
        page: 'Assistant/Knowledge/Index',
        label: 'Filtro “Revisión vencida (5)” activo',
        props: { ...index, filters: { ...NO_FILTERS, overdue: true }, cards: paginate(KNOWLEDGE_OVERDUE) },
    },
    index_phone: {
        page: 'Assistant/Knowledge/Index',
        label: 'Teléfono (C-KnowledgePhone) · Leonardo filtrando Sistema y Segurex · ver a 390 px',
        props: {
            ...index,
            ...sharedFor('leonardo'),
            filters: { ...NO_FILTERS, type: 'system', company_id: SEGUREX.id },
            cards: paginate(systems.filter((card) => card.company_ids.includes(SEGUREX.id))),
        },
    },
    index_readonly: {
        page: 'Assistant/Knowledge/Index',
        label: 'Solo consulta · usuario con assistant.use sin administrar (genérico)',
        props: {
            ...index,
            ...readerShared(),
            cards: paginate(READER_PAGE_1, READER_COUNTS.total),
            counts: READER_COUNTS,
            area_options: AREA_OPTIONS.filter((area) => area.company_id === SEGUREX.id),
            type_examples: EXAMPLES_READONLY,
            unanswered_count: 0,
            drafts_count: 0,
        },
    },
    index_empty: {
        page: 'Assistant/Knowledge/Index',
        label: 'Sin fichas todavía · importar desde Excel',
        props: {
            ...index,
            cards: paginate([], 0),
            counts: EMPTY_COUNTS,
            area_options: [],
            type_examples: { system: null, report: null, data_flow: null, procedure: null, glossary: null },
            unanswered_count: 0,
            drafts_count: 0,
        },
    },
    index_no_results: {
        page: 'Assistant/Knowledge/Index',
        label: 'Búsqueda sin resultados · “ventas por zona” en Derivados',
        props: { ...index, filters: { ...NO_FILTERS, q: 'ventas por zona', company_id: DERIVADOS.id }, cards: paginate([], 0) },
    },
    show_report: {
        page: 'Assistant/Knowledge/Show',
        label: 'Ficha de informe · K-031 Informe de ventas diario',
        props: show(CARD_K031),
    },
    show_procedure: {
        page: 'Assistant/Knowledge/Show',
        label: 'Ficha de procedimiento · K-022 con pasos y ruta por completar',
        props: show(CARD_K022),
    },
    show_glossary: {
        page: 'Assistant/Knowledge/Show',
        label: 'Ficha de glosario · K-009 Pedido bloqueado por cartera',
        props: show(CARD_K009),
    },
    show_data_flow: {
        page: 'Assistant/Knowledge/Show',
        label: 'Ficha de flujo de datos · K-027 (Solo TI)',
        props: show(CARD_K027),
    },
    show_overdue: {
        page: 'Assistant/Knowledge/Show',
        label: 'Ficha de sistema con revisión vencida · K-014 SAP Business One',
        props: show(CARD_K014),
    },
    show_readonly: {
        page: 'Assistant/Knowledge/Show',
        label: 'Ficha en solo consulta · K-018 para un usuario sin administrar (genérico)',
        props: { ...show(CARD_K018), ...readerShared(), unanswered_count: 0, drafts_count: 0 },
    },
    create: {
        page: 'Assistant/Knowledge/Create',
        label: 'Nueva ficha en blanco · Segurex, revisión en 6 meses',
        props: { ...formBase, prefill: null },
    },
    create_prefilled: {
        page: 'Assistant/Knowledge/Create',
        label: 'Nueva ficha desde “¿Qué informe muestra las ventas por zona?” · error en Destinatarios',
        props: createPrefilled,
    },
    edit: {
        page: 'Assistant/Knowledge/Edit',
        label: 'Editar K-031 · tipo fijo y “Eliminar ficha”',
        props: edit,
    },
};
