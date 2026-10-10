import { describe, expect, it } from 'vitest';
import type { LearnDraft, UnansweredQuestion } from '../types/assistant';
import { draftApproval, joinEs, sortUnanswered, timesLabel, unansweredTotals } from './curation';

const person = (id: number, name: string) => ({ id, name, job_title: null, avatar_url: null });

const draft = (overrides: Partial<LearnDraft['proposed']> = {}): LearnDraft => ({
    id: 1,
    author: person(1, 'Jhon Castro'),
    channel: 'telegram',
    received_at: '2026-10-07T07:40:00-05:00',
    message: 'el informe…',
    proposed: {
        type: 'report',
        title: 'Informe de ventas diario de Toma Pedidos',
        description: 'Lo envía Toma Pedidos SAP.',
        company_ids: [1],
        area: null,
        visibility: 'company',
        review_at: '2027-01-07',
        fields: [
            { label: 'Sistema de origen', value: 'Toma Pedidos SAP', origin: 'message' },
            { label: 'Dueño', value: null, origin: 'missing' },
        ],
        ...overrides,
    },
    similar_to: null,
    linked_card: null,
});

describe('joinEs', () => {
    it('joins with commas and "y"', () => {
        expect(joinEs([])).toBe('');
        expect(joinEs(['A'])).toBe('A');
        expect(joinEs(['A', 'B'])).toBe('A y B');
        expect(joinEs(['A', 'B', 'C'])).toBe('A, B y C');
    });
});

describe('draftApproval', () => {
    it('allows approving with optional fields missing and lists them', () => {
        expect(draftApproval(draft())).toEqual({ allowed: true, reason: null, missing: ['Dueño'] });
    });

    it('blocks without a title or a company and says why', () => {
        expect(draftApproval(draft({ title: '  ' }))).toEqual({
            allowed: false,
            reason: 'Falta el título. Usa “Editar antes de aprobar” para completarlo.',
            missing: ['Dueño'],
        });
        expect(draftApproval(draft({ company_ids: [] })).reason).toBe('Falta la empresa. Usa “Editar antes de aprobar” para completarla.');
    });

    it('has nothing missing when every field came through', () => {
        expect(draftApproval(draft({ fields: [{ label: 'Programa', value: 'FortiClient', origin: 'message' }] })).missing).toEqual([]);
    });
});

const question = (id: number, times: number, last: string): UnansweredQuestion => ({
    id,
    text: `Pregunta ${id}`,
    times_asked: times,
    askers: [],
    last_asked_at: last,
});

describe('sortUnanswered', () => {
    const list = [
        question(1, 1, '2026-10-06T10:00:00-05:00'),
        question(2, 3, '2026-10-01T10:00:00-05:00'),
        question(3, 3, '2026-10-05T10:00:00-05:00'),
        question(4, 2, '2026-10-07T08:00:00-05:00'),
    ];

    it('puts the most asked first, then the most recent among ties', () => {
        expect(sortUnanswered(list, 'times').map((item) => item.id)).toEqual([3, 2, 4, 1]);
    });

    it('sorts by last time asked', () => {
        expect(sortUnanswered(list, 'recent').map((item) => item.id)).toEqual([4, 1, 3, 2]);
    });

    it('does not mutate the input', () => {
        sortUnanswered(list, 'times');
        expect(list.map((item) => item.id)).toEqual([1, 2, 3, 4]);
    });
});

describe('unansweredTotals and timesLabel', () => {
    it('counts questions and total asks', () => {
        expect(unansweredTotals([question(1, 3, ''), question(2, 2, '')])).toEqual({ questions: 2, asks: 5 });
    });

    it('says "vez" or "veces"', () => {
        expect(timesLabel(1)).toBe('vez');
        expect(timesLabel(3)).toBe('veces');
    });
});
