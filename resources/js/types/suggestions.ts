/**
 * AI suggestions (Stage D). Only users with `ai.manage` receive them;
 * requesters never do. A suggestion is never applied by itself: the page
 * shows it in a neutral block with Usar / Editar / Descartar and Jhon's
 * final values stay separate.
 *
 * The AI never receives credentials, vault content, rates, costs, salaries,
 * client data, internal notes or attachments.
 */
import type { RequestStatusKey } from '@/lib/status';
import type { EffortSize } from '@/lib/priority';

export type SuggestionState = 'generating' | 'ready' | 'accepted' | 'discarded' | 'stale' | 'error';

export type SuggestionKind = 'summary' | 'duplicate' | 'criteria' | 'size' | 'release_notes';

/**
 * Whether suggestion blocks show for this viewer:
 * - `ready`: blocks show.
 * - `off_no_key` / `off_cap`: blocks hidden; `ai.manage` sees one short note.
 * - `hidden`: nothing at all (no `ai.manage`, or the requester).
 */
export type AiAvailability = 'ready' | 'off_no_key' | 'off_cap' | 'hidden';

export type DuplicateLevel = 'alto' | 'medio';

export interface SuggestionPayloads {
    summary: { text: string };
    duplicate: {
        request: { key: string; title: string; status: RequestStatusKey; people: number };
        level: DuplicateLevel;
        /** One line for the card: "mismo problema y misma app". */
        reason_summary: string;
        /** Full reasons for the comparison page. */
        reasons: string[];
        /** What only this request asks for. */
        differences: string[];
    };
    criteria: { items: { id: number; text: string }[] };
    size: { size: EffortSize; reason: string };
    release_notes: {
        title: string;
        /** User-facing Markdown, no technical jargon. */
        body: string;
        /** "Hecha con el título y los criterios de GIA-118. No usa costos ni notas internas." */
        source_note: string;
    };
}

export interface SuggestionError {
    /** "No se pudo generar la sugerencia." / "No hay suficiente texto para resumir." */
    message: string;
    detail: string | null;
    /** `ai_timeout`, `too_short`… */
    code: string;
    at: string;
}

export interface Suggestion<K extends SuggestionKind = SuggestionKind> {
    id: number;
    kind: K;
    state: SuggestionState;
    /** "DeepSeek". */
    provider: string;
    created_at: string;
    /** Null only while `generating` or after an `error`. */
    payload: SuggestionPayloads[K] | null;
    /** Set when `accepted`: who used it and when ("Basado en una sugerencia"). */
    accepted: { by: string; at: string } | null;
    /** Set when `discarded`. */
    discarded: { by: string; at: string; reason: string | null } | null;
    /** Set when `stale`: what changed after the suggestion. */
    stale: { reason: string; at: string } | null;
    error: SuggestionError | null;
}

/** Any suggestion, discriminated by `kind`. */
export type AnySuggestion = { [K in SuggestionKind]: Suggestion<K> }[SuggestionKind];

/** Usage line for admins when the monthly cap is reached. */
export interface AiUsage {
    /** e.g. "US$ 10,00". */
    spent_label: string;
    /** e.g. "US$ 10" (monthly AI cap). */
    cap_label: string;
    /** "1 de noviembre". */
    resumes_on: string;
}

/**
 * AI suggestions of one request (IT view of `Ai/Requests/Show`). Sent only
 * with `ai.manage`; the requester view never has them. A null kind means
 * nothing was suggested yet ("Sugerir" asks for it).
 */
export interface RequestSuggestions {
    summary: Suggestion<'summary'> | null;
    /** Jhon's "Resumen para TI" once a summary was used (it may be his edited text); null otherwise. */
    summary_text: string | null;
    /** Newest first; discarded ones stay as a collapsed row. */
    duplicates: Suggestion<'duplicate'>[];
    criteria: Suggestion<'criteria'> | null;
    size: Suggestion<'size'> | null;
}
