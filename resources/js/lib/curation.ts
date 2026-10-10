/**
 * Curation helpers of the Asistente TI (boards C-Drafts, C-DraftPhone,
 * C-Unanswered): whether a /aprender draft can be approved as is, and the
 * order and counters of the unanswered questions. The server is the
 * authority; these only drive the UI and the mocks.
 */
import type { LearnDraft, UnansweredQuestion } from '../types/assistant';

/** "A", "A y B", "A, B y C". */
export function joinEs(items: readonly string[]): string {
    if (items.length <= 1) return items[0] ?? '';
    return `${items.slice(0, -1).join(', ')} y ${items[items.length - 1]}`;
}

// ---- /aprender drafts -------------------------------------------------------

export interface DraftApproval {
    allowed: boolean;
    /** Why "Aprobar" is disabled; null when allowed. */
    reason: string | null;
    /** Labels of the fields the assistant could not fill ("Falta"). */
    missing: string[];
}

/**
 * A card needs a title, a company and a visibility (the draft always has
 * one); every type field and the owner are optional (see
 * `lib/importMapping.ts`). So a "Falta" field never blocks "Aprobar": the
 * card is published and the missing field is completed later. Only a draft
 * without a title or a company must go through "Editar antes de aprobar".
 */
export function draftApproval(draft: LearnDraft): DraftApproval {
    const missing = draft.proposed.fields.filter((field) => field.origin === 'missing').map((field) => field.label);
    if (draft.proposed.title.trim() === '') {
        return { allowed: false, reason: 'Falta el título. Usa “Editar antes de aprobar” para completarlo.', missing };
    }
    if (draft.proposed.company_ids.length === 0) {
        return { allowed: false, reason: 'Falta la empresa. Usa “Editar antes de aprobar” para completarla.', missing };
    }
    return { allowed: true, reason: null, missing };
}

// ---- unanswered questions ---------------------------------------------------

/** `?orden=`: most asked (default) or most recent. */
export type UnansweredOrder = 'times' | 'recent';

const time = (iso: string) => new Date(iso).getTime();

/** Most asked first (ties: most recent first), or most recent first. Returns a new array. */
export function sortUnanswered(questions: readonly UnansweredQuestion[], order: UnansweredOrder): UnansweredQuestion[] {
    return [...questions].sort((a, b) => {
        if (order === 'times' && a.times_asked !== b.times_asked) return b.times_asked - a.times_asked;
        return time(b.last_asked_at) - time(a.last_asked_at);
    });
}

/** "7 preguntas · se hicieron 11 veces en total". */
export function unansweredTotals(questions: readonly UnansweredQuestion[]): { questions: number; asks: number } {
    return { questions: questions.length, asks: questions.reduce((sum, item) => sum + item.times_asked, 0) };
}

export function timesLabel(count: number): string {
    return count === 1 ? 'vez' : 'veces';
}
