import { describe, expect, it } from 'vitest';
import type { RequestSuggestions, Suggestion } from '../../types/suggestions';
import {
    addCriteriaToDraft,
    countSuggestions,
    criteriaPayload,
    sizeSuggestionIdOnSave,
    suggestionsMode,
    usedSuggestionItemIds,
    visibleDuplicateSuggestions,
    type CriteriaDraftItem,
} from './requestSuggestions';

const base = {
    provider: 'DeepSeek',
    created_at: '2026-10-07T09:38:00-05:00',
    accepted: null,
    discarded: null,
    stale: null,
    error: null,
};

const duplicate = (id: number, key: string | null, state: Suggestion['state'] = 'ready'): Suggestion<'duplicate'> => ({
    ...base,
    id,
    kind: 'duplicate',
    state,
    payload: key
        ? { request: { key, title: key, status: 'backlog', people: 1 }, level: 'alto', reason_summary: 'misma app', reasons: [], differences: [] }
        : null,
    discarded: state === 'discarded' ? { by: 'Jhon Castro', at: base.created_at, reason: null } : null,
});

describe('visibleDuplicateSuggestions', () => {
    it('drops AI duplicates that point to a request the rules already flagged', () => {
        const result = visibleDuplicateSuggestions([duplicate(1, 'GIA-121'), duplicate(2, 'GIA-130')], ['GIA-121']);
        expect(result.map((item) => item.id)).toEqual([2]);
    });

    it('keeps generating or failed duplicates without a target', () => {
        const result = visibleDuplicateSuggestions([duplicate(3, null, 'generating')], ['GIA-121']);
        expect(result.map((item) => item.id)).toEqual([3]);
    });

    it('lists discarded suggestions after the open ones', () => {
        const result = visibleDuplicateSuggestions([duplicate(4, 'GIA-117', 'discarded'), duplicate(5, 'GIA-121')], []);
        expect(result.map((item) => item.id)).toEqual([5, 4]);
    });
});

describe('addCriteriaToDraft', () => {
    const first = { id: 1, text: 'El asesor ve un aviso.' };
    const items = [first, { id: 2, text: 'El aviso llega al correo.' }];

    it('appends suggested items as Jhon’s own, remembering where they came from', () => {
        const draft = addCriteriaToDraft([], items);
        expect(draft).toEqual([
            { id: null, text: 'El asesor ve un aviso.', suggestion_item_id: 1 },
            { id: null, text: 'El aviso llega al correo.', suggestion_item_id: 2 },
        ]);
        expect(usedSuggestionItemIds(draft)).toEqual([1, 2]);
    });

    it('skips items already used or already written with the same text', () => {
        const existing: CriteriaDraftItem[] = [
            { id: 7, text: '  el aviso llega al CORREO. ', suggestion_item_id: null },
            { id: null, text: 'El asesor ve un aviso.', suggestion_item_id: 1 },
        ];
        expect(addCriteriaToDraft(existing, items)).toEqual(existing);
    });

    it('fills an empty line instead of leaving it behind', () => {
        const draft = addCriteriaToDraft([{ id: null, text: '   ', suggestion_item_id: null }], [first]);
        expect(draft).toEqual([{ id: null, text: 'El asesor ve un aviso.', suggestion_item_id: 1 }]);
    });
});

describe('criteriaPayload', () => {
    it('trims, drops empty lines and links the suggestion only when one of its items is kept', () => {
        const own: CriteriaDraftItem = { id: 7, text: ' Uno ', suggestion_item_id: null };
        const draft: CriteriaDraftItem[] = [
            own,
            { id: null, text: '', suggestion_item_id: null },
            { id: null, text: 'Dos', suggestion_item_id: 2 },
        ];
        expect(criteriaPayload(draft, 9003)).toEqual({ criteria: [{ id: 7, text: 'Uno' }, { id: null, text: 'Dos' }], suggestion_id: 9003 });
        expect(criteriaPayload([own], 9003)).toEqual({ criteria: [{ id: 7, text: 'Uno' }], suggestion_id: null });
        expect(criteriaPayload(draft, null).suggestion_id).toBeNull();
    });
});

describe('sizeSuggestionIdOnSave', () => {
    const used = { id: 9004, size: 'M' as const };

    it('credits the suggestion only when the saved size is the one Jhon used', () => {
        expect(sizeSuggestionIdOnSave('M', used)).toBe(9004);
        expect(sizeSuggestionIdOnSave('L', used)).toBeNull();
        expect(sizeSuggestionIdOnSave('M', null)).toBeNull();
        expect(sizeSuggestionIdOnSave(null, used)).toBeNull();
    });
});

describe('suggestionsMode', () => {
    it('shows blocks when ready, one note for admins when off, and nothing when hidden', () => {
        expect(suggestionsMode('ready')).toBe('blocks');
        expect(suggestionsMode('off_no_key')).toBe('note');
        expect(suggestionsMode('off_cap')).toBe('note');
        expect(suggestionsMode('hidden')).toBe('none');
        expect(suggestionsMode(undefined)).toBe('none');
    });
});

describe('countSuggestions', () => {
    it('counts every suggestion shown except discarded ones and duplicates the rules flagged', () => {
        const suggestions: RequestSuggestions = {
            summary: { ...base, id: 1, kind: 'summary', state: 'accepted', payload: { text: 'x' } },
            summary_text: 'x',
            duplicates: [duplicate(2, 'GIA-121'), duplicate(3, 'GIA-117', 'discarded'), duplicate(4, 'GIA-130')],
            criteria: { ...base, id: 5, kind: 'criteria', state: 'ready', payload: { items: [] } },
            size: null,
        };
        expect(countSuggestions(suggestions, ['GIA-130'])).toBe(3);
        expect(countSuggestions(null, [])).toBe(0);
    });
});
