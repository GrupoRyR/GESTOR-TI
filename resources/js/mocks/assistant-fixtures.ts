/**
 * Asistente TI (Stage C) entities for the gallery mocks, built once from the
 * facts of `docs/design/briefs/stage-c-mockups-brief.md` so every board
 * agrees. Page mocks (`mocks/assistant*.ts`) compose these; they never
 * redefine an entity. Unknown facts stay as bracket placeholders.
 *
 * Today is miércoles 7 de octubre de 2026 (`NOW_ISO`, 9:40 a. m.).
 */
import { reviewStatus } from '@/lib/knowledge';
import { fieldsFor } from '@/lib/importMapping';
import type { PersonRef } from '@/types/ai';
import type {
    AnswerMessage,
    ChatConversation,
    ChatConversationSummary,
    ChatMessage,
    CredentialNoticeMessage,
    ImportColumn,
    ImportField,
    ImportFile,
    ImportMapping,
    ImportRowResult,
    ImportSummary,
    KnowledgeArea,
    KnowledgeCard,
    KnowledgeCardRef,
    KnowledgeCardSummary,
    KnowledgeCounts,
    LearnDraft,
    NoAnswerMessage,
    PersonChoiceMessage,
    PersonOption,
    RevealedCredential,
    UnansweredQuestion,
    UserChatMessage,
} from '@/types/assistant';
import { AREAS, NOW_ISO, PEOPLE, PRODUCT_REFS, TODAY } from './ai-fixtures';
import { DERIVADOS, SEGUREX } from './shared';

export { NOW_ISO, TODAY };

// ---- people and areas ---------------------------------------------------------

/** The namesake of Juan Pérez (Segurex) used for disambiguation. */
export const JUAN_PEREZ_DERIVADOS: PersonRef = { id: 12, name: 'Juan Pérez', job_title: 'Auxiliar de bodega', avatar_url: null };

/** People who have `assistant.use` today (copy of the no-permission state). */
export const ASSISTANT_HOLDERS: PersonRef[] = [PEOPLE.jhon, PEOPLE.leonardo];

const karea = (ref: { id: number; name: string; company_id: number }): KnowledgeArea => ({ id: ref.id, name: ref.name, company_id: ref.company_id });

export const KNOWLEDGE_AREAS = {
    ventas: karea(AREAS.ventas),
    cartera: karea(AREAS.cartera),
    tesoreria: karea(AREAS.tesoreria),
    comprasD: karea(AREAS.compras),
    ti: { id: 18, name: 'TI', company_id: SEGUREX.id },
    tiD: { id: 28, name: 'TI', company_id: DERIVADOS.id },
    logistica: { id: 27, name: 'Logística', company_id: DERIVADOS.id },
} satisfies Record<string, KnowledgeArea>;

// ---- knowledge cards ----------------------------------------------------------

/** Header counters: 48 cards, 31 for the whole company, 17 IT only, 5 overdue. */
export const KNOWLEDGE_COUNTS: KnowledgeCounts = {
    total: 48,
    by_type: { system: 9, report: 14, data_flow: 7, procedure: 12, glossary: 6 },
    by_visibility: { company: 31, it: 17 },
    overdue: 5,
};

type Defaulted =
    | 'related_cards'
    | 'related_products'
    | 'last_reviewed_at'
    | 'last_reviewed_by'
    | 'usage_count_month'
    | 'last_cited_at'
    | 'last_cited_by'
    | 'recent_changes';

/** Omit that keeps the `type` / `fields` discriminated union intact. */
type CardSeed = KnowledgeCard extends infer C ? (C extends KnowledgeCard ? Omit<C, Defaulted | 'review_status'> & Partial<Pick<C, Defaulted>> : never) : never;

const card = (seed: CardSeed): KnowledgeCard =>
    ({
        related_cards: [],
        related_products: [],
        last_reviewed_at: null,
        last_reviewed_by: null,
        usage_count_month: 0,
        last_cited_at: null,
        last_cited_by: null,
        recent_changes: [],
        ...seed,
        review_status: reviewStatus(seed.review_at, TODAY),
    }) as KnowledgeCard;

const both = [SEGUREX.id, DERIVADOS.id];

export const CARD_K031 = card({
    id: 31,
    key: 'K-031',
    type: 'report',
    title: 'Informe de ventas diario',
    company_ids: [SEGUREX.id],
    area: KNOWLEDGE_AREAS.ventas,
    visibility: 'company',
    owner: PEOPLE.paola,
    review_at: '2027-01-15',
    updated_at: '2026-10-05T16:20:00-05:00',
    description:
        'Lo envía Toma Pedidos SAP a las 6:00 a. m. a gerencia y a los coordinadores de ventas. Datos de SAP B1 (pedidos del día anterior). Llega en Excel por correo.',
    fields: {
        shows: 'Los pedidos del día anterior, tomados de SAP B1',
        source_system: 'Toma Pedidos SAP, con datos de SAP Business One',
        recipients: 'Gerencia y coordinadores de ventas de Segurex',
        schedule: 'Todos los días a las 6:00 a. m.',
        format: 'Excel adjunto por correo',
    },
    related_products: [PRODUCT_REFS.tomaPedidos],
    last_reviewed_at: '2026-07-15T10:00:00-05:00',
    last_reviewed_by: PEOPLE.jhon,
    usage_count_month: 12,
    last_cited_at: NOW_ISO,
    last_cited_by: PEOPLE.jhon,
    recent_changes: [
        { id: 3, who: PEOPLE.paola, what: 'agregó a los coordinadores de ventas en destinatarios', at: '2026-10-05T16:20:00-05:00' },
        { id: 2, who: PEOPLE.jhon, what: 'la marcó como revisada', at: '2026-07-15T10:00:00-05:00' },
        { id: 1, who: PEOPLE.jhon, what: 'creó la ficha', at: '2026-06-02T11:30:00-05:00' },
    ],
});

export const CARD_K014 = card({
    id: 14,
    key: 'K-014',
    type: 'system',
    title: 'SAP Business One',
    company_ids: both,
    area: KNOWLEDGE_AREAS.ti,
    visibility: 'it',
    owner: PEOPLE.leonardo,
    review_at: '2026-09-30',
    updated_at: '2026-10-02T09:10:00-05:00',
    description: 'ERP de Segurex y Derivados. Corre en un servidor local y se entra por VPN.',
    fields: { what_is: 'ERP de Segurex y Derivados', runs_on: 'Servidor local', access: 'Por VPN, con usuario de SAP', version: '[VERSIÓN]' },
    usage_count_month: 7,
});

export const CARD_K027 = card({
    id: 27,
    key: 'K-027',
    type: 'data_flow',
    title: 'Pedidos de Toma Pedidos a SAP B1',
    company_ids: [SEGUREX.id],
    area: KNOWLEDGE_AREAS.ventas,
    visibility: 'it',
    owner: PEOPLE.jhon,
    review_at: '2026-11-30',
    updated_at: '2026-10-01T15:00:00-05:00',
    description: 'Toma Pedidos envía cada pedido a SAP B1 por el Service Layer cada [FRECUENCIA].',
    fields: {
        origin_destination: 'Toma Pedidos a SAP B1',
        transport: 'Service Layer de SAP B1',
        frequency: '[FRECUENCIA]',
        on_failure: 'El pedido queda “pendiente de sincronizar”',
    },
    related_products: [PRODUCT_REFS.tomaPedidos],
    usage_count_month: 9,
});

export const CARD_K040 = card({
    id: 40,
    key: 'K-040',
    type: 'system',
    title: 'Cámaras / robotcamara',
    company_ids: [SEGUREX.id],
    area: KNOWLEDGE_AREAS.ti,
    visibility: 'it',
    owner: PEOPLE.jhon,
    review_at: '2026-10-01',
    updated_at: '2026-09-29T17:45:00-05:00',
    description: null,
    fields: { what_is: '[QUÉ HACE ROBOTCAMARA]', runs_on: '[DÓNDE CORRE]', access: '[ACCESO]', version: PRODUCT_REFS.camaras.version },
    related_products: [PRODUCT_REFS.camaras],
    usage_count_month: 2,
});

export const CARD_K022 = card({
    id: 22,
    key: 'K-022',
    type: 'procedure',
    title: 'Cómo sacar el informe de antigüedad de cartera en SAP B1',
    company_ids: [SEGUREX.id],
    area: KNOWLEDGE_AREAS.cartera,
    visibility: 'company',
    owner: PEOPLE.cartera,
    review_at: '2026-12-20',
    updated_at: '2026-09-28T10:00:00-05:00',
    description: null,
    fields: { steps: ['Abrir SAP Business One.', 'Ir a [RUTA EN SAP].', '[PASOS SIGUIENTES]'], who_can: 'Gerencia de cartera y asesores' },
    usage_count_month: 5,
});

export const CARD_K018 = card({
    id: 18,
    key: 'K-018',
    type: 'procedure',
    title: 'Conectarse a la VPN',
    company_ids: both,
    area: KNOWLEDGE_AREAS.ti,
    visibility: 'company',
    owner: PEOPLE.leonardo,
    review_at: '2027-02-10',
    updated_at: '2026-09-25T08:30:00-05:00',
    description: null,
    fields: { steps: ['[PASOS PARA CONECTARSE]'], who_can: '[QUIÉN LO PUEDE HACER]' },
    usage_count_month: 6,
});

export const CARD_K035 = card({
    id: 35,
    key: 'K-035',
    type: 'system',
    title: 'Visualizador de cartera',
    company_ids: [SEGUREX.id],
    area: KNOWLEDGE_AREAS.cartera,
    visibility: 'company',
    owner: PEOPLE.cartera,
    review_at: '2027-02-28',
    updated_at: '2026-09-22T14:00:00-05:00',
    description: null,
    fields: { what_is: '[QUÉ ES]', runs_on: '[DÓNDE CORRE]', access: '[ACCESO]', version: PRODUCT_REFS.visualizador.version },
    related_products: [PRODUCT_REFS.visualizador],
    usage_count_month: 3,
});

export const CARD_K009 = card({
    id: 9,
    key: 'K-009',
    type: 'glossary',
    title: 'Pedido bloqueado por cartera',
    company_ids: [SEGUREX.id],
    area: KNOWLEDGE_AREAS.ventas,
    visibility: 'company',
    owner: PEOPLE.paola,
    review_at: '2027-03-05',
    updated_at: '2026-09-20T09:00:00-05:00',
    description: null,
    fields: {
        term: 'Pedido bloqueado por cartera',
        definition: 'Pedido que SAP retiene porque el cliente superó su cupo o tiene facturas vencidas',
        also_called: '[SINÓNIMOS]',
    },
    usage_count_month: 4,
});

/** Updated by the Excel import (row 9). */
export const CARD_K024 = card({
    id: 24,
    key: 'K-024',
    type: 'report',
    title: 'Informe de antigüedad de cartera',
    company_ids: [SEGUREX.id],
    area: KNOWLEDGE_AREAS.cartera,
    visibility: 'company',
    owner: PEOPLE.cartera,
    review_at: '2027-01-20',
    updated_at: '2026-08-14T10:00:00-05:00',
    description: '[DESCRIPCIÓN ACTUAL]',
    fields: { shows: '[QUÉ MUESTRA]', source_system: 'SAP Business One', recipients: '[DESTINATARIOS]', schedule: '[HORARIO]', format: '[FORMATO]' },
});

/** Updated by the Excel import (row 24). */
export const CARD_K029 = card({
    id: 29,
    key: 'K-029',
    type: 'report',
    title: 'Informe de compras pendientes por proveedor',
    company_ids: [DERIVADOS.id],
    area: KNOWLEDGE_AREAS.comprasD,
    visibility: 'company',
    owner: null,
    review_at: '2027-02-15',
    updated_at: '2026-08-20T10:00:00-05:00',
    description: '[DESCRIPCIÓN ACTUAL]',
    fields: { shows: '[QUÉ MUESTRA]', source_system: 'SAP Business One', recipients: '[DESTINATARIOS]', schedule: '[HORARIO]', format: '[FORMATO]' },
});

/** Order of the list board (last edited first): the 8 visible rows, then the import targets. */
export const KNOWLEDGE_CARDS: KnowledgeCard[] = [CARD_K031, CARD_K014, CARD_K027, CARD_K040, CARD_K022, CARD_K018, CARD_K035, CARD_K009, CARD_K024, CARD_K029];

// Related cards need the refs, so they are linked after creation.
CARD_K031.related_cards = [cardRef(CARD_K027)];
CARD_K027.related_cards = [cardRef(CARD_K031), cardRef(CARD_K014)];
CARD_K022.related_cards = [cardRef(CARD_K014)];
CARD_K040.related_cards = [];

export function cardRef(source: KnowledgeCardRef): KnowledgeCardRef {
    return { key: source.key, type: source.type, title: source.title, visibility: source.visibility };
}

export function cardSummary(source: KnowledgeCard): KnowledgeCardSummary {
    const { id, key, type, title, visibility, company_ids, area, owner, review_at, review_status, updated_at } = source;
    return { id, key, type, title, visibility, company_ids, area, owner, review_at, review_status, updated_at };
}

/** The 8 rows of page 1 of the list (C-Knowledge, C-KnowledgePhone). */
export const KNOWLEDGE_PAGE_1: KnowledgeCardSummary[] = KNOWLEDGE_CARDS.slice(0, 8).map(cardSummary);

/** The overdue cards among the fixtures (K-014, K-040); the other 3 of the 5 are not modeled. */
export const KNOWLEDGE_OVERDUE: KnowledgeCardSummary[] = KNOWLEDGE_CARDS.filter((item) => item.review_status === 'overdue').map(cardSummary);

export const cardByKey = (key: string): KnowledgeCard => {
    const found = KNOWLEDGE_CARDS.find((item) => item.key === key);
    if (!found) throw new Error(`Unknown card ${key}`);
    return found;
};

// ---- chat -------------------------------------------------------------------

const at = (time: string, day = TODAY) => `${day}T${time}:00-05:00`;

/** Juan Pérez of Segurex and his namesake of Derivados. */
export const PERSON_OPTIONS: PersonOption[] = [
    { id: PEOPLE.juan.id, name: 'Juan Pérez', job_title: 'Asesor comercial', area: 'Ventas', company_id: SEGUREX.id, company_name: 'Segurex' },
    { id: JUAN_PEREZ_DERIVADOS.id, name: 'Juan Pérez', job_title: 'Auxiliar de bodega', area: 'Logística', company_id: DERIVADOS.id, company_name: 'Derivados' },
];

/** First-open greeting suggestions (C-ChatStates "Primera vez"). */
export const FIRST_OPEN_SUGGESTIONS = [
    '¿Quién recibe el informe de ventas diario?',
    '¿Cómo me conecto a la VPN?',
    '¿Qué es un pedido bloqueado por cartera?',
    '¿Cómo saco la antigüedad de cartera?',
];

const user = (id: number, time: string, text: string): UserChatMessage => ({ id, at: at(time), role: 'user', kind: 'text', text });

export const MSG_SALES_QUESTION = user(1001, '09:40', '¿Quién recibe el informe de ventas diario y a qué hora sale?');

export const MSG_SALES_ANSWER: AnswerMessage = {
    id: 1002,
    at: at('09:40'),
    role: 'assistant',
    kind: 'answer',
    text: 'Sale a las **6:00 a. m.** desde Toma Pedidos SAP y llega por correo a gerencia y a los coordinadores de ventas de Segurex. Trae los pedidos del día anterior desde SAP B1.',
    sources: [cardRef(CARD_K031), cardRef(CARD_K027)],
    suggestions: ['¿Quién es el dueño del informe?', '¿Qué pasa si un pedido no sincroniza?', 'Ver la ficha de Toma Pedidos SAP'],
};

export const MSG_BACKUP_QUESTION = user(1101, '08:55', '¿Cada cuánto se respalda el servidor de SAP?');

export const MSG_BACKUP_NO_ANSWER: NoAnswerMessage = {
    id: 1102,
    at: at('08:55'),
    role: 'assistant',
    kind: 'no_answer',
    text: '**No lo tengo documentado.** Registré la pregunta para que TI la documente.',
    explanation: 'Ninguna ficha de la base de conocimiento habla de respaldos del servidor de SAP. Prefiero no adivinar.',
    times_asked: 3,
    suggestions: ['¿Cómo me conecto a SAP Business One?', '¿Cómo me conecto a la VPN?'],
    suggestions_label: 'Sobre SAP sí tengo esto',
};

export const MSG_PASSWORD_QUESTION = user(1201, '10:05', 'Dame la contraseña de Office de Juan Pérez');

export const MSG_PERSON_CHOICE: PersonChoiceMessage = {
    id: 1202,
    at: at('10:05'),
    role: 'assistant',
    kind: 'person_choice',
    text: 'Hay dos personas con ese nombre. ¿De cuál?',
    options: PERSON_OPTIONS,
    resolved_option_id: null,
};

export const MSG_PERSON_PICKED = user(1203, '10:05', 'Juan Pérez · Asesor comercial · Ventas · Segurex');

/** The card never carries the value; it arrives only in `REVEALED_CREDENTIAL_SAMPLE`. */
export const MSG_CREDENTIAL: CredentialNoticeMessage = {
    id: 1204,
    at: at('10:05'),
    role: 'system',
    kind: 'credential_notice',
    credential_id: 501,
    system: 'Office',
    person: PERSON_OPTIONS[0] as PersonOption,
    username: 'juan.perez@segurex.com',
    reveal_seconds: 30,
};

export const MSG_VAULT_QUESTION = user(1205, '10:07', 'Dame la llave de API de DeepSeek');

export const MSG_VAULT_LINK: ChatMessage = {
    id: 1206,
    at: at('10:07'),
    role: 'assistant',
    kind: 'vault_link',
    text: 'Esa credencial está en la bóveda del proyecto. Ábrela ahí; te pedirá confirmar tu identidad.',
    item: { id: 1, name: 'DEEPSEEK_API_KEY', product: 'Gestor TI', environment_label: 'producción' },
};

export const MSG_REFUSED_QUESTION = user(1301, '11:15', 'Dame la contraseña de Office de Juan Pérez');

export const MSG_REFUSED: ChatMessage = { id: 1302, at: at('11:15'), role: 'assistant', kind: 'refused', text: 'No puedo entregar contraseñas. Pídela a TI.' };

export const MSG_PROVIDER_ERROR: ChatMessage = {
    id: 1003,
    at: at('09:40'),
    role: 'assistant',
    kind: 'error',
    text: 'El asistente no está disponible en este momento, intenta en unos minutos.',
    retryable: true,
};

/** Question waiting for its answer ("Buscando en la base de conocimiento…"). */
export const MSG_SEARCHING_QUESTION = user(1401, '09:58', '¿Qué es un pedido bloqueado por cartera?');

/** Fake sample value from the board, revealed for 30 s. Never a real password. */
export const REVEALED_CREDENTIAL_SAMPLE: RevealedCredential = { message_id: MSG_CREDENTIAL.id, value: 'Tq8#vL2m-Rx4p', expires_in_seconds: 30 };

export const CONVERSATIONS: ChatConversationSummary[] = [
    { id: 301, title: 'Informe de ventas diario', last_message_at: at('09:40') },
    { id: 302, title: 'Respaldo del servidor de SAP', last_message_at: at('08:55') },
    { id: 303, title: 'Pedido bloqueado por cartera', last_message_at: at('08:20') },
    { id: 304, title: 'Antigüedad de cartera en SAP B1', last_message_at: at('16:10', '2026-10-06') },
    { id: 305, title: 'Conectarse a la VPN', last_message_at: at('11:00', '2026-10-05') },
    { id: 306, title: 'Cámaras / robotcamara', last_message_at: at('15:30', '2026-09-30') },
];

const conversation = (summaryId: number, messages: ChatMessage[]): ChatConversation => {
    const summary = CONVERSATIONS.find((item) => item.id === summaryId) ?? { id: summaryId, title: 'Nueva conversación', last_message_at: NOW_ISO };
    return { ...summary, started_at: messages[0]?.at ?? NOW_ISO, messages };
};

/** C-ChatPhone / C-ChatDesktop. */
export const CONVERSATION_SALES = conversation(301, [MSG_SALES_QUESTION, MSG_SALES_ANSWER]);
/** C-ChatNoAnswer. */
export const CONVERSATION_BACKUP = conversation(302, [MSG_BACKUP_QUESTION, MSG_BACKUP_NO_ANSWER]);
/** C-ChatPerson: waiting for the pick. */
export const CONVERSATION_PERSON = conversation(307, [MSG_PASSWORD_QUESTION, MSG_PERSON_CHOICE]);
/** C-ChatPassword: picked, credential delivered, then the vault case. */
export const CONVERSATION_PASSWORD = conversation(307, [
    MSG_PASSWORD_QUESTION,
    { ...MSG_PERSON_CHOICE, resolved_option_id: PEOPLE.juan.id },
    MSG_PERSON_PICKED,
    MSG_CREDENTIAL,
    MSG_VAULT_QUESTION,
    MSG_VAULT_LINK,
]);
/** C-ChatStates "Contraseña negada" (Paola). */
export const CONVERSATION_REFUSED = conversation(308, [MSG_REFUSED_QUESTION, MSG_REFUSED]);
/** C-ChatStates "Asistente no disponible". */
export const CONVERSATION_ERROR = conversation(301, [MSG_SALES_QUESTION, MSG_PROVIDER_ERROR]);
/** C-ChatStates "Escribiendo": the question is sent, the answer is pending. */
export const CONVERSATION_SEARCHING = conversation(303, [MSG_SEARCHING_QUESTION]);

// ---- /aprender drafts ---------------------------------------------------------

export const LEARN_DRAFTS: LearnDraft[] = [
    {
        id: 1,
        author: PEOPLE.jhon,
        channel: 'telegram',
        received_at: at('07:40'),
        message: 'el informe de ventas diario lo envía Toma Pedidos a las 6 a. m. a gerencia',
        proposed: {
            type: 'report',
            title: 'Informe de ventas diario de Toma Pedidos',
            description: 'Lo envía Toma Pedidos SAP a las 6:00 a. m. a gerencia.',
            company_ids: [SEGUREX.id],
            area: KNOWLEDGE_AREAS.ventas,
            visibility: 'company',
            review_at: '2027-01-07',
            fields: [
                { label: 'Empresa', value: 'Segurex', origin: 'assumed' },
                { label: 'Área', value: 'Ventas', origin: 'assumed' },
                { label: 'Sistema de origen', value: 'Toma Pedidos SAP', origin: 'message' },
                { label: 'Horario de envío', value: '6:00 a. m.', origin: 'message' },
                { label: 'Quién lo recibe', value: 'Gerencia', origin: 'message' },
                { label: 'Dueño', value: null, origin: 'missing' },
            ],
        },
        similar_to: cardRef(CARD_K031),
        linked_card: null,
    },
    {
        id: 2,
        author: PEOPLE.leonardo,
        channel: 'telegram',
        received_at: at('16:20', '2026-10-06'),
        message: 'la VPN de Derivados usa FortiClient y la cuenta la crea Leonardo',
        proposed: {
            type: 'procedure',
            title: 'Conectarse a la VPN de Derivados',
            description: 'La VPN de Derivados usa FortiClient. La cuenta la crea Leonardo Herrera.',
            company_ids: [DERIVADOS.id],
            area: KNOWLEDGE_AREAS.tiD,
            visibility: 'company',
            review_at: '2027-04-06',
            fields: [
                { label: 'Empresa', value: 'Derivados', origin: 'message' },
                { label: 'Programa', value: 'FortiClient', origin: 'message' },
                { label: 'Quién crea la cuenta', value: 'Leonardo Herrera', origin: 'message' },
                { label: 'Pasos numerados', value: null, origin: 'missing' },
            ],
        },
        similar_to: null,
        linked_card: null,
    },
    {
        id: 3,
        author: PEOPLE.jhon,
        channel: 'app',
        received_at: at('10:15', '2026-10-04'),
        message: 'robotcamara revisa las cámaras cada 15 minutos y avisa por Telegram si una no responde',
        proposed: {
            type: 'system',
            title: 'Cámaras / robotcamara',
            description: 'robotcamara revisa las cámaras cada 15 minutos y avisa por Telegram si una no responde.',
            company_ids: [SEGUREX.id],
            area: KNOWLEDGE_AREAS.ti,
            visibility: 'it',
            review_at: '2027-04-04',
            fields: [
                { label: 'Qué hace', value: 'Revisa las cámaras cada 15 minutos', origin: 'message' },
                { label: 'Aviso', value: 'Por Telegram si una cámara no responde', origin: 'message' },
                { label: 'Empresa', value: 'Segurex', origin: 'assumed' },
            ],
        },
        similar_to: null,
        linked_card: cardRef(CARD_K040),
    },
];

// ---- unanswered questions -----------------------------------------------------

export const UNANSWERED: UnansweredQuestion[] = [
    { id: 1, text: '¿Cada cuánto se respalda el servidor de SAP?', times_asked: 3, askers: [PEOPLE.jhon, PEOPLE.leonardo], last_asked_at: at('08:55') },
    { id: 2, text: '¿Qué informe muestra las ventas por zona?', times_asked: 2, askers: [PEOPLE.jhon], last_asked_at: at('15:10', '2026-10-06') },
    { id: 3, text: '¿Quién aprueba las órdenes de compra de Derivados?', times_asked: 2, askers: [PEOPLE.leonardo], last_asked_at: at('11:30', '2026-10-05') },
    { id: 4, text: '¿Cómo se agrega una impresora en la bodega?', times_asked: 1, askers: [PEOPLE.leonardo], last_asked_at: at('09:00', '2026-10-04') },
    { id: 5, text: '¿Qué hace robotcamara cuando una cámara no responde?', times_asked: 1, askers: [PEOPLE.jhon], last_asked_at: at('16:45', '2026-10-03') },
    { id: 6, text: '¿Dónde están los manuales del Portafolio?', times_asked: 1, askers: [PEOPLE.jhon], last_asked_at: at('10:20', '2026-10-01') },
    { id: 7, text: '¿Qué IP tiene el NVR de la bodega?', times_asked: 1, askers: [PEOPLE.leonardo], last_asked_at: at('08:10', '2026-09-30') },
];

/** Tab counters: Borradores de /aprender (3) · Preguntas sin respuesta (7). */
export const ASSISTANT_TAB_COUNTS = { drafts: LEARN_DRAFTS.length, unanswered: UNANSWERED.length };

// ---- Excel import -------------------------------------------------------------

export const IMPORT_HEADERS = ['Nombre', 'Tipo', 'Empresa', 'Área', 'Descripción', 'Sistema origen', 'Destinatarios', 'Horario', 'Visibilidad', 'Dueño', 'Notas'];

export const IMPORT_FILE: ImportFile = {
    id: 1,
    name: 'mapa-de-sistemas.xlsx',
    size_bytes: null,
    uploaded_at: NOW_ISO,
    sheets: [
        { name: 'Informes', rows: 32, columns: IMPORT_HEADERS },
        { name: 'Sistemas', rows: null, columns: [] },
        { name: 'Procedimientos', rows: null, columns: [] },
    ],
    selected_sheet: 'Informes',
    header_row: true,
};

/** Fields a column of the Informes sheet can map to. */
export const IMPORT_FIELDS: ImportField[] = fieldsFor('report');

/** As on C-ImportMapping: 8 automatic, Destinatarios picked by hand, Tipo fixed, Notas skipped. */
export const IMPORT_COLUMNS: ImportColumn[] = [
    { name: 'Nombre', sample: 'Informe de ventas diario', field: 'title', match: 'auto' },
    { name: 'Tipo', sample: 'Informe', field: 'type', match: 'fixed' },
    { name: 'Empresa', sample: 'Segurex', field: 'company', match: 'auto' },
    { name: 'Área', sample: 'Ventas', field: 'area', match: 'auto' },
    { name: 'Descripción', sample: 'Pedidos del día anterior desde SAP B1', field: 'description', match: 'auto' },
    { name: 'Sistema origen', sample: 'Toma Pedidos SAP', field: 'source_system', match: 'auto' },
    { name: 'Destinatarios', sample: 'Gerencia; coordinadores de ventas', field: 'recipients', match: 'manual' },
    { name: 'Horario', sample: '6:00 a. m.', field: 'schedule', match: 'auto' },
    { name: 'Visibilidad', sample: 'Toda la empresa', field: 'visibility', match: 'auto' },
    { name: 'Dueño', sample: 'Paola Ríos', field: 'owner', match: 'auto' },
    { name: 'Notas', sample: 'Confirmar destinatarios con Paola', field: null, match: 'skip' },
];

export const IMPORT_MAPPING: ImportMapping = { file_id: IMPORT_FILE.id, sheet: 'Informes', type: 'report', columns: IMPORT_COLUMNS };

export const IMPORT_SUMMARY: ImportSummary = { total: 32, new: 28, update: 3, error: 1 };

/** The 6 rows of the preview board: the error first, the 3 updates, 2 of the 28 new ones. */
export const IMPORT_ROWS: ImportRowResult[] = [
    {
        row: 17,
        title: 'Informe de flujo de caja semanal',
        context: 'Empresa «RYR» · Tesorería',
        outcome: 'error',
        card: null,
        changes: [],
        visibility: null,
        owner_name: null,
        error: {
            message: 'La empresa «RYR» no existe en el Gestor.',
            fix: 'En la celda C17 escribe Segurex, Derivados o Segurex y Derivados, guarda y vuelve a subir el archivo.',
        },
    },
    {
        row: 2,
        title: 'Informe de ventas diario',
        context: 'Segurex · Ventas',
        outcome: 'update',
        card: cardRef(CARD_K031),
        changes: [
            { label: 'Quién lo recibe', from: 'Gerencia', to: 'Gerencia y coordinadores de ventas' },
            { label: 'Sistema de origen', from: null, to: 'Toma Pedidos SAP' },
        ],
        visibility: 'company',
        owner_name: 'Paola Ríos',
        error: null,
    },
    {
        row: 9,
        title: 'Informe de antigüedad de cartera',
        context: 'Segurex · Cartera',
        outcome: 'update',
        card: cardRef(CARD_K024),
        changes: [{ label: 'Descripción', from: '[DESCRIPCIÓN ACTUAL]', to: '[DESCRIPCIÓN NUEVA]' }],
        visibility: 'company',
        owner_name: 'Gerencia de cartera',
        error: null,
    },
    {
        row: 24,
        title: 'Informe de compras pendientes por proveedor',
        context: 'Derivados · Compras',
        outcome: 'update',
        card: cardRef(CARD_K029),
        changes: [
            { label: 'Descripción', from: '[DESCRIPCIÓN ACTUAL]', to: '[DESCRIPCIÓN NUEVA]' },
            { label: 'Dueño', from: null, to: 'Andrés Méndez' },
        ],
        visibility: 'company',
        owner_name: 'Andrés Méndez',
        error: null,
    },
    {
        row: 3,
        title: 'Informe de recaudo diario',
        context: 'Segurex · Cartera',
        outcome: 'new',
        card: null,
        changes: [],
        visibility: 'company',
        owner_name: '[DUEÑO]',
        error: null,
    },
    {
        row: 4,
        title: 'Informe de compras del mes',
        context: 'Derivados · Compras',
        outcome: 'new',
        card: null,
        changes: [],
        visibility: 'it',
        owner_name: 'Andrés Méndez',
        error: null,
    },
];
