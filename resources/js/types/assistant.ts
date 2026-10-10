/**
 * Domain types of the Asistente TI (Stage C): knowledge cards, the chat,
 * /aprender drafts, unanswered questions and the Excel import. Page prop
 * types live in `types/pages/assistant*.ts` and compose these.
 *
 * Security rules encoded here:
 * - A credential value never appears in a chat message. `CredentialNoticeMessage`
 *   only says whose and which system; the value travels alone in the page's
 *   `revealed_credential` prop (`RevealedCredential`) and is never sent to the AI.
 * - Vault items are never delivered by the chat; `VaultLinkMessage` only links to them.
 */
import type { PersonRef, ProductRef } from './ai';

// ---- knowledge cards --------------------------------------------------------

/** Sistema · Informe · Flujo de datos · Procedimiento · Glosario. */
export type KnowledgeType = 'system' | 'report' | 'data_flow' | 'procedure' | 'glossary';

/** "Solo TI" (only knowledge managers; the assistant never cites it to others) or "Toda la empresa". */
export type KnowledgeVisibility = 'it' | 'company';

/** Derived from `review_at` and today: overdue cards carry an icon + text, never color alone. */
export type ReviewStatus = 'ok' | 'due_soon' | 'overdue';

/** Area of a card; `company_id` tells which company the area belongs to. */
export interface KnowledgeArea {
    id: number;
    name: string;
    company_id: number;
}

/** Minimal reference to a card (sources, related cards, duplicates). */
export interface KnowledgeCardRef {
    /** "K-031". */
    key: string;
    type: KnowledgeType;
    title: string;
    visibility: KnowledgeVisibility;
}

/** Sistema: what it is and how to get in. */
export interface SystemFields {
    what_is: string;
    runs_on: string | null;
    access: string | null;
    version: string | null;
}

/** Informe: who gets it and when it goes out. */
export interface ReportFields {
    shows: string;
    /** "Toma Pedidos SAP, con datos de SAP Business One". */
    source_system: string | null;
    recipients: string | null;
    schedule: string | null;
    format: string | null;
}

/** Flujo de datos: how a datum travels and what happens when it fails. */
export interface DataFlowFields {
    origin_destination: string;
    transport: string | null;
    frequency: string | null;
    on_failure: string | null;
}

/** Procedimiento: ordered steps; menu paths stay as placeholders, never invented. */
export interface ProcedureFields {
    steps: string[];
    who_can: string | null;
}

/** Glosario: a company word. */
export interface GlossaryFields {
    term: string;
    definition: string;
    also_called: string | null;
}

/** Type-specific fields, discriminated by `type`. */
export type KnowledgeCardBody =
    | { type: 'system'; fields: SystemFields }
    | { type: 'report'; fields: ReportFields }
    | { type: 'data_flow'; fields: DataFlowFields }
    | { type: 'procedure'; fields: ProcedureFields }
    | { type: 'glossary'; fields: GlossaryFields };

/** Row of the knowledge list (no type fields). */
export interface KnowledgeCardSummary extends KnowledgeCardRef {
    id: number;
    /** One or both companies ("Segurex y Derivados" = both ids). */
    company_ids: number[];
    area: KnowledgeArea | null;
    /** A person or a group ("Gerencia de cartera"). */
    owner: PersonRef | null;
    /** Next review, `YYYY-MM-DD` (6 months after the last review by default). */
    review_at: string;
    /** Computed by the server from `review_at` and today (see `lib/knowledge.ts`). */
    review_status: ReviewStatus;
    updated_at: string;
}

export interface KnowledgeChange {
    id: number;
    who: PersonRef;
    /** "agregó a los coordinadores de ventas en destinatarios". */
    what: string;
    at: string;
}

/** Full card (show / edit). */
export type KnowledgeCard = KnowledgeCardSummary &
    KnowledgeCardBody & {
        /** Free description; the assistant reads it with the fields. */
        description: string | null;
        related_cards: KnowledgeCardRef[];
        related_products: ProductRef[];
        last_reviewed_at: string | null;
        last_reviewed_by: PersonRef | null;
        /** Answers that cited this card this month. */
        usage_count_month: number;
        last_cited_at: string | null;
        /** Name of whose chat cited it last ("el chat de Jhon Castro"). */
        last_cited_by: PersonRef | null;
        recent_changes: KnowledgeChange[];
    };

/** Header counters of the knowledge base. */
export interface KnowledgeCounts {
    total: number;
    by_type: Record<KnowledgeType, number>;
    by_visibility: Record<KnowledgeVisibility, number>;
    overdue: number;
}

// ---- chat -------------------------------------------------------------------

/** Chat availability: provider failure, no AI key or monthly cap reached. */
export type AssistantStatus = 'ready' | 'unavailable' | 'off_no_key' | 'off_cap';

/** A card the answer used, shown as a link under "Fuentes". */
export type ChatSource = KnowledgeCardRef;

/** One of several people with the same name (password delivery). */
export interface PersonOption {
    id: number;
    name: string;
    job_title: string;
    area: string;
    company_id: number;
    company_name: string;
}

interface MessageBase {
    id: number;
    /** ISO 8601. */
    at: string;
}

export interface UserChatMessage extends MessageBase {
    role: 'user';
    kind: 'text';
    text: string;
}

/** Normal answer: short text (`**bold**` allowed), always with its sources. */
export interface AnswerMessage extends MessageBase {
    role: 'assistant';
    kind: 'answer';
    text: string;
    sources: ChatSource[];
    suggestions: string[];
}

/** "No lo tengo documentado." The question was registered in Preguntas sin respuesta. */
export interface NoAnswerMessage extends MessageBase {
    role: 'assistant';
    kind: 'no_answer';
    text: string;
    /** Why it did not guess ("Ninguna ficha … Prefiero no adivinar."). */
    explanation: string | null;
    /** Times this question has been asked, including this one. */
    times_asked: number;
    /** Related questions it can answer. */
    suggestions: string[];
    /** Title over the suggestions ("Sobre SAP sí tengo esto"). */
    suggestions_label: string | null;
}

/** Two or more people share the name: pick one. */
export interface PersonChoiceMessage extends MessageBase {
    role: 'assistant';
    kind: 'person_choice';
    text: string;
    options: PersonOption[];
    /** Chosen option once answered (buttons then disable). */
    resolved_option_id: number | null;
}

/**
 * Delivered by the Gestor TI, not by the AI: whose and which system, the
 * user name, never the value. The value arrives only in `RevealedCredential`.
 */
export interface CredentialNoticeMessage extends MessageBase {
    role: 'system';
    kind: 'credential_notice';
    credential_id: number;
    /** "Office". */
    system: string;
    person: PersonOption;
    username: string;
    /** Seconds the value stays visible once shown (30). */
    reveal_seconds: number;
}

/** Vault credentials are never delivered by the chat; this only links to the item. */
export interface VaultLinkMessage extends MessageBase {
    role: 'assistant';
    kind: 'vault_link';
    text: string;
    item: { id: number; name: string; product: string; environment_label: string };
}

/** Plain refusal ("No puedo entregar contraseñas. Pídela a TI."). */
export interface RefusedMessage extends MessageBase {
    role: 'assistant';
    kind: 'refused';
    text: string;
}

/** Provider failure on one question. */
export interface ErrorMessage extends MessageBase {
    role: 'assistant';
    kind: 'error';
    text: string;
    retryable: boolean;
}

export type AssistantChatMessage = AnswerMessage | NoAnswerMessage | PersonChoiceMessage | VaultLinkMessage | RefusedMessage | ErrorMessage;

export type ChatMessage = UserChatMessage | AssistantChatMessage | CredentialNoticeMessage;

export type ChatMessageKind = ChatMessage['kind'];

/** Item of the conversation list. */
export interface ChatConversationSummary {
    id: number;
    title: string;
    last_message_at: string;
}

export interface ChatConversation extends ChatConversationSummary {
    started_at: string;
    messages: ChatMessage[];
}

/**
 * The only place a credential value exists on the client, sent once in its
 * own prop right after "Mostrar". The page uses no-store and history
 * encryption; the component drops it after `expires_in_seconds` (max 30).
 */
export interface RevealedCredential {
    message_id: number;
    value: string;
    expires_in_seconds: number;
}

// ---- /aprender drafts -------------------------------------------------------

export type LearnChannel = 'telegram' | 'app';

/** Where a proposed field came from. */
export type DraftFieldOrigin = 'message' | 'assumed' | 'missing';

export interface DraftField {
    label: string;
    /** Null when missing. */
    value: string | null;
    origin: DraftFieldOrigin;
}

export interface LearnDraft {
    id: number;
    author: PersonRef;
    channel: LearnChannel;
    received_at: string;
    /** What the person wrote after /aprender. */
    message: string;
    proposed: {
        type: KnowledgeType;
        title: string;
        description: string;
        company_ids: number[];
        area: KnowledgeArea | null;
        visibility: KnowledgeVisibility;
        review_at: string;
        fields: DraftField[];
    };
    /** Probable duplicate: offer "Agregar a K-031" instead of a new card. */
    similar_to: KnowledgeCardRef | null;
    /** Card the draft is about ("enlazado a K-040"). */
    linked_card: KnowledgeCardRef | null;
}

// ---- unanswered questions ---------------------------------------------------

export interface UnansweredQuestion {
    id: number;
    text: string;
    times_asked: number;
    askers: PersonRef[];
    last_asked_at: string;
}

// ---- Excel import -----------------------------------------------------------

export interface ImportSheet {
    name: string;
    /** Data rows; null until the sheet is read. */
    rows: number | null;
    /** Header row as read. */
    columns: string[];
}

export interface ImportFile {
    id: number;
    name: string;
    /** Null while unknown. */
    size_bytes: number | null;
    uploaded_at: string;
    sheets: ImportSheet[];
    selected_sheet: string;
    /** Row 1 holds the column names. */
    header_row: boolean;
}

/** How a column got its field: by name, by hand, fixed by the sheet type, or not imported. */
export type ImportMatchKind = 'auto' | 'manual' | 'fixed' | 'skip';

export interface ImportColumn {
    /** Header as written in the Excel ("Destinatarios"). */
    name: string;
    /** Value of row 2, as an example. */
    sample: string;
    /** Card field key; null when not imported. */
    field: string | null;
    match: ImportMatchKind;
}

export interface ImportField {
    key: string;
    label: string;
    required: boolean;
}

export interface ImportMapping {
    file_id: number;
    sheet: string;
    /** Every row of the sheet enters with this type. */
    type: KnowledgeType;
    columns: ImportColumn[];
}

export type ImportRowOutcome = 'new' | 'update' | 'error';

export interface ImportFieldChange {
    label: string;
    /** Null = "vacío". */
    from: string | null;
    to: string;
}

export interface ImportRowResult {
    /** Excel row number. */
    row: number;
    title: string;
    /** "Segurex · Ventas", or the raw value when invalid ("Empresa «RYR» · Tesorería"). */
    context: string;
    outcome: ImportRowOutcome;
    /** Card it updates. */
    card: KnowledgeCardRef | null;
    changes: ImportFieldChange[];
    visibility: KnowledgeVisibility | null;
    owner_name: string | null;
    error: { message: string; fix: string } | null;
}

export interface ImportSummary {
    total: number;
    new: number;
    update: number;
    error: number;
}
