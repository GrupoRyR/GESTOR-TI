/**
 * Asistente TI · chat (boards C-ChatPhone, C-ChatDesktop, C-ChatNoAnswer,
 * C-ChatPerson, C-ChatPassword, C-ChatStates). Phone-first; desktop shows
 * three columns (conversations · thread · source preview), tablet the thread
 * with the conversation list in a drawer.
 *
 * Inertia component and props:
 * - `Assistant/Chat`   AssistantChatProps   (AssistantLayout tab "chat")
 *
 * Ziggy routes used:
 * - `assistant.index`                GET   `{ conversation?: id }` query `{ fuente?: key }`
 *                                          No conversation = first open (greeting + `first_open_suggestions`).
 *                                          Desktop source preview is a partial reload
 *                                          `only: ['selected_source']` with `?fuente=K-031` (preserveState,
 *                                          preserveScroll, replace); closing it drops `fuente`.
 * - `assistant.conversations.store`  POST  —                          "Nueva conversación": creates an empty one
 *                                          and redirects to `assistant.index { conversation }`.
 * - `assistant.messages.store`       POST  `{ conversation?: id }`    body `{ question: string }` (max 500).
 *                                          Composer and suggestion chips. Without `conversation` the server
 *                                          creates one and redirects to it. Answers with the updated
 *                                          `conversation` and `conversations` (no streaming, no client fetch).
 *                                          Validation error in `errors.question`.
 * - `assistant.messages.retry`       POST  `{ message: id }`          "Reintentar" on a retryable `error` message:
 *                                          asks the same question again (no duplicate user bubble).
 * - `assistant.people.pick`          POST  `{ message: id }`          body `{ option: number | null }`: person chosen
 *                                          in a `person_choice` message; `null` = "Ninguno de los dos".
 * - `assistant.credentials.reveal`   POST  `{ message: id }`          "Mostrar" on a `credential_notice` card.
 *                                          Responds with the page where `revealed_credential` carries the value
 *                                          (partial `only: ['revealed_credential']`). The response MUST use
 *                                          `Inertia::encryptHistory()` and `Cache-Control: no-store`; the server
 *                                          audits the reveal and alerts Jhon Castro by Telegram. The UI drops
 *                                          the value after `expires_in_seconds` (max 30) or on "Ocultar".
 * - `assistant.credentials.copied`   POST  `{ message: id }`          after "Copiar" (audit only; the value is never
 *                                          sent back). Partial `only: ['conversation']`.
 * - `assistant.knowledge.show`       GET   `{ card: key }`            source links and "Abrir ficha".
 * - `assistant.unanswered.index`     GET   —                          "Ver preguntas sin respuesta" (managers only).
 * - `ai.vault.index`                 GET   query `{ item: id }`       `vault_link` card: the chat never delivers vault
 *                                          credentials, it only links to the item (the vault asks to re-auth).
 * - `admin.integrations.index`       GET   —                          assistant off, admin view.
 * - `home`                           GET   —                          no-permission state "Ir al inicio".
 *
 * Permissions (checked with `can()`; the server is the authority):
 * - `assistant.use`                  the chat. Without it the page renders the no-access state naming
 *                                    `access_holders` (today Jhon Castro and Leonardo Herrera) and no composer.
 * - `assistant.knowledge.manage`     the management tabs, the "Ver preguntas sin respuesta" link and its count.
 * - `assistant.credentials` AND `credentials.view`
 *                                    password delivery. Without both the server answers a `refused` message
 *                                    ("No puedo entregar contraseñas. Pídela a TI.") and never sends a
 *                                    `credential_notice`; the UI also refuses to call reveal.
 * - `can_configure_ai` (server flag, Súper Admin level) shows the admin notice with the link to
 *   Administración › Integraciones when the assistant is off; everyone else reads "El asistente no está activo."
 * - Secrets: a credential value exists only in `revealed_credential`, never in a message, title, aria-label, log or URL.
 */
import type { PersonRef } from '@/types/ai';
import type { AssistantStatus, ChatConversation, ChatConversationSummary, KnowledgeCardSummary, RevealedCredential } from '@/types/assistant';

/** Desktop preview of a source card (`?fuente=`): the list row plus its description. */
export type ChatSourcePreview = KnowledgeCardSummary & {
    description: string | null;
};

export interface AssistantChatProps {
    /** `ready`; `unavailable` (provider failing, retry allowed); `off_no_key` / `off_cap` (composer off). */
    status: AssistantStatus;
    /** Monthly AI cap as the server formats it ("US$ 20"); shown in the admin off notice. Null when unknown. */
    monthly_cap: string | null;
    /** The person's conversations, newest first (empty without `assistant.use`). */
    conversations: ChatConversationSummary[];
    /** Open conversation; null on first open or a new one. */
    conversation: ChatConversation | null;
    /** Card of `?fuente=`; null when no preview is open (desktop only). */
    selected_source: ChatSourcePreview | null;
    /** The only secret-carrying prop, right after `assistant.credentials.reveal`; null otherwise. */
    revealed_credential: RevealedCredential | null;
    /** Greeting suggestions when the conversation is empty. */
    first_open_suggestions: string[];
    /** Tab and link counts; 0 without `assistant.knowledge.manage`. */
    unanswered_count: number;
    drafts_count: number;
    /** Admin notice when the assistant is off (Administración › Integraciones). */
    can_configure_ai: boolean;
    /** Holders of `assistant.use`, only sent to someone without it; empty otherwise. */
    access_holders: PersonRef[];
    /** Server time, ISO 8601 (day dividers, list labels, review dates). */
    now: string;
}
