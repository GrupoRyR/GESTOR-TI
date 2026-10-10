/**
 * Pure helpers for the AI suggestions of a request (IT view): which blocks
 * show, how suggested criteria and size reach Jhon's own form, and what is
 * sent back when he saves. Nothing here saves by itself.
 */
import type { EffortSize } from '../../lib/priority';
import type { AiAvailability, RequestSuggestions, Suggestion } from '../../types/suggestions';

/**
 * AI duplicate cards to show. The rule-based duplicate keeps its amber
 * banner, so an AI suggestion pointing to a request the rules already
 * flagged is dropped. Discarded suggestions go last (collapsed rows).
 */
export function visibleDuplicateSuggestions(suggestions: Suggestion<'duplicate'>[], ruleKeys: string[]): Suggestion<'duplicate'>[] {
    const flagged = new Set(ruleKeys);
    const shown = suggestions.filter((item) => !item.payload || !flagged.has(item.payload.request.key));
    return [...shown.filter((item) => item.state !== 'discarded'), ...shown.filter((item) => item.state === 'discarded')];
}

/** One line of Jhon's criteria form. `suggestion_item_id` remembers an item that came from the AI. */
export interface CriteriaDraftItem {
    /** Saved criterion id; null for new lines. */
    id: number | null;
    text: string;
    suggestion_item_id: number | null;
}

const normalize = (text: string) => text.trim().toLocaleLowerCase('es-CO');

/**
 * Adds suggested items to the form ("Usar" / "Usar los N"). Skips items
 * already used or already written with the same text, and fills empty
 * lines first.
 */
export function addCriteriaToDraft(draft: CriteriaDraftItem[], items: { id: number; text: string }[]): CriteriaDraftItem[] {
    const next = [...draft];
    for (const item of items) {
        const exists = next.some((line) => line.suggestion_item_id === item.id || (line.text.trim() !== '' && normalize(line.text) === normalize(item.text)));
        if (exists) continue;
        const line: CriteriaDraftItem = { id: null, text: item.text, suggestion_item_id: item.id };
        const empty = next.findIndex((entry) => entry.text.trim() === '');
        const target = next[empty];
        if (target) next[empty] = { ...line, id: target.id };
        else next.push(line);
    }
    return next;
}

/** Suggestion items already copied into the form (they show "Usado"). */
export function usedSuggestionItemIds(draft: CriteriaDraftItem[]): number[] {
    return draft.flatMap((line) => (line.suggestion_item_id === null ? [] : [line.suggestion_item_id]));
}

export interface CriteriaPayload {
    criteria: { id: number | null; text: string }[];
    /** Criteria suggestion to mark as used; null when none of its items is kept. */
    suggestion_id: number | null;
}

/** Body of `ai.requests.criteria.update`. */
export function criteriaPayload(draft: CriteriaDraftItem[], suggestionId: number | null): CriteriaPayload {
    const kept = draft.map((line) => ({ ...line, text: line.text.trim() })).filter((line) => line.text !== '');
    return {
        criteria: kept.map(({ id, text }) => ({ id, text })),
        suggestion_id: suggestionId !== null && kept.some((line) => line.suggestion_item_id !== null) ? suggestionId : null,
    };
}

/**
 * The size suggestion is credited on "Guardar evaluación" only when the
 * saved size is the one Jhon took from it ("Usar M").
 */
export function sizeSuggestionIdOnSave(size: EffortSize | null, used: { id: number; size: EffortSize } | null): number | null {
    return used !== null && size === used.size ? used.id : null;
}

/** `blocks`: suggestions show; `note`: one short note for admins; `none`: nothing at all. */
export function suggestionsMode(availability: AiAvailability | undefined): 'blocks' | 'note' | 'none' {
    if (availability === 'ready') return 'blocks';
    if (availability === 'off_no_key' || availability === 'off_cap') return 'note';
    return 'none';
}

/** "4 sugerencias de IA": every suggestion shown, except discarded ones and duplicates the rules flagged. */
export function countSuggestions(suggestions: RequestSuggestions | null | undefined, ruleKeys: string[]): number {
    if (!suggestions) return 0;
    const all = [suggestions.summary, ...visibleDuplicateSuggestions(suggestions.duplicates, ruleKeys), suggestions.criteria, suggestions.size];
    return all.filter((item) => item !== null && item.state !== 'discarded').length;
}
