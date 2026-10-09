import { describe, expect, it } from 'vitest';
import { answeredLabel, dueState, estimateTotals, firstName, firstResponseState, mergeOutcome, paybackText, sinceLabel, teamHours } from './triage';

/** Mock "now": miércoles 7 oct 2026, 9:40 a. m. (Bogotá). */
const NOW = new Date('2026-10-07T09:40:00-05:00');

/** Times use a no-break space before "a. m." / "p. m.". */
const norm = (state: { level: string; label: string }) => ({ ...state, label: state.label.replace(/ /g, ' ') });

describe('dueState (deadline style)', () => {
    it('says how long ago an overdue deadline passed, in days', () => {
        expect(dueState('2026-10-06T08:20:00-05:00', NOW)).toEqual({ level: 'late', label: 'Vencida hace 1 día' });
        expect(dueState('2026-10-04T08:20:00-05:00', NOW)).toEqual({ level: 'late', label: 'Vencida hace 3 días' });
    });

    it('uses hours when it passed earlier today', () => {
        expect(dueState('2026-10-07T07:10:00-05:00', NOW)).toEqual({ level: 'late', label: 'Vencida hace 2 h' });
    });

    it('marks today with the time', () => {
        expect(norm(dueState('2026-10-07T17:00:00-05:00', NOW))).toEqual({ level: 'today', label: 'Vence hoy, 5:00 p. m.' });
    });

    it('names tomorrow, the weekday within a week and the date after that', () => {
        expect(dueState('2026-10-08T09:10:00-05:00', NOW)).toEqual({ level: 'ok', label: 'Vence mañana' });
        expect(dueState('2026-10-09T17:00:00-05:00', NOW)).toEqual({ level: 'ok', label: 'Vence el viernes' });
        expect(dueState('2026-10-21T17:00:00-05:00', NOW)).toEqual({ level: 'ok', label: 'Vence el 21 oct' });
    });

    it('treats plain dates as whole Bogotá days', () => {
        expect(dueState('2026-10-07', NOW)).toEqual({ level: 'today', label: 'Vence hoy' });
        expect(dueState('2026-10-01', NOW)).toEqual({ level: 'late', label: 'Vencida hace 6 días' });
    });
});

describe('dueState (agenda style)', () => {
    it('writes the day instead of a sentence', () => {
        expect(norm(dueState('2026-10-07T17:00:00-05:00', NOW, 'agenda'))).toEqual({ level: 'today', label: 'Hoy, 5:00 p. m.' });
        expect(dueState('2026-10-08', NOW, 'agenda')).toEqual({ level: 'ok', label: 'Jueves 8 oct' });
        expect(dueState('2026-10-09', NOW, 'agenda')).toEqual({ level: 'ok', label: 'Viernes 9 oct' });
        expect(dueState('2026-10-06T08:20:00-05:00', NOW, 'agenda')).toEqual({ level: 'late', label: 'Vencida hace 1 día' });
    });
});

describe('answeredLabel', () => {
    it('uses minutes, hours, "the same day" or days', () => {
        expect(answeredLabel('2026-10-07T09:20:00-05:00', '2026-10-07T09:40:00-05:00')).toBe('Respondida en 20 min');
        expect(answeredLabel('2026-10-04T10:00:00-05:00', '2026-10-04T14:00:00-05:00')).toBe('Respondida en 4 h');
        expect(answeredLabel('2026-10-02T09:00:00-05:00', '2026-10-02T15:00:00-05:00')).toBe('Respondida el mismo día');
        expect(answeredLabel('2026-09-26T10:00:00-05:00', '2026-09-27T11:00:00-05:00')).toBe('Respondida en 1 día');
        expect(answeredLabel('2026-09-26T10:00:00-05:00', '2026-09-29T11:00:00-05:00')).toBe('Respondida en 3 días');
    });
});

describe('firstResponseState', () => {
    it('is the deadline while unanswered and the answer time afterwards', () => {
        expect(firstResponseState({ due_at: '2026-10-06T08:20:00-05:00', answered_at: null }, '2026-10-05T08:20:00-05:00', NOW)).toEqual({
            level: 'late',
            label: 'Vencida hace 1 día',
        });
        expect(
            firstResponseState({ due_at: '2026-10-05T10:00:00-05:00', answered_at: '2026-10-04T14:00:00-05:00' }, '2026-10-04T10:00:00-05:00', NOW),
        ).toEqual({ level: 'answered', label: 'Respondida en 4 h' });
    });
});

describe('sinceLabel', () => {
    it('counts how long something has been waiting', () => {
        expect(sinceLabel('2026-10-07T09:10:00-05:00', NOW)).toBe('hace 30 min');
        expect(sinceLabel('2026-10-07T08:40:00-05:00', NOW)).toBe('hace 1 h');
        expect(sinceLabel('2026-10-06T16:00:00-05:00', NOW)).toBe('hace 1 día');
        expect(sinceLabel('2026-10-05T09:00:00-05:00', NOW)).toBe('hace 2 días');
    });
});

describe('teamHours', () => {
    it('multiplies hours by people, or null while unknown', () => {
        expect(teamHours(1.5, 8)).toBe(12);
        expect(teamHours(null, 8)).toBeNull();
        expect(teamHours(3, null)).toBeNull();
    });
});

describe('estimateTotals', () => {
    it('adds hours and, when every rate is known, hours × rate plus external costs', () => {
        expect(
            estimateTotals(
                [
                    { hours: 28, rate: 160_000 },
                    { hours: 12, rate: 160_000 },
                ],
                0,
            ),
        ).toEqual({ hours: 40, cost: 6_400_000 });
        expect(estimateTotals([{ hours: 10, rate: 100_000 }], 250_000)).toEqual({ hours: 10, cost: 1_250_000 });
    });

    it('has no cost when a rate is hidden and ignores invalid hours', () => {
        expect(estimateTotals([{ hours: 28, rate: null }], 0)).toEqual({ hours: 28, cost: null });
        expect(estimateTotals([{ hours: Number.NaN, rate: 1 }, { hours: -3, rate: 1 }], null)).toEqual({ hours: 0, cost: 0 });
    });
});

describe('mergeOutcome', () => {
    it('moves the votes and the requester to the request that stays', () => {
        expect(mergeOutcome({ key: 'GIA-148', votes: 3, requester: 'Paola Ríos' }, { key: 'GIA-121', votes: 4 })).toEqual({
            people: 8,
            lines: [
                'GIA-148 se cierra como duplicada de GIA-121.',
                'Sus 3 votos pasan a GIA-121, que queda con 8 personas.',
                'Paola Ríos queda como seguidora y recibe los avisos de GIA-121.',
                'Sus respuestas y adjuntos se copian a GIA-121.',
            ],
        });
    });

    it('speaks in singular for one vote and skips votes when there are none', () => {
        expect(mergeOutcome({ key: 'A', votes: 1, requester: 'X' }, { key: 'B', votes: 0 }).lines[1]).toBe('Su voto pasa a B, que queda con 2 personas.');
        expect(mergeOutcome({ key: 'A', votes: 0, requester: 'X' }, { key: 'B', votes: 2 }).lines[1]).toBe('B queda con 3 personas.');
    });
});

describe('paybackText', () => {
    it('rounds the months, or explains why there is no return', () => {
        expect(paybackText(8.1, 'Cartera')).toBe('Se paga en unos 8 meses con las horas que ahorra.');
        expect(paybackText(0.6, 'Ventas')).toBe('Se paga en menos de un mes con las horas que ahorra.');
        expect(paybackText(1.2, 'Ventas')).toBe('Se paga en cerca de un mes con las horas que ahorra.');
        expect(paybackText(null, 'Tesorería')).toBe(
            'Sin valor de hora del área. Tesorería aún no tiene valor de hora, así que no se calcula en cuántos meses se paga.',
        );
    });
});

describe('firstName', () => {
    it('keeps the first word of a person, and whole names of areas or placeholders', () => {
        expect(firstName('Paola Ríos')).toBe('Paola');
        expect(firstName('Gerencia de cartera')).toBe('Gerencia de cartera');
        expect(firstName('[SOLICITANTE CONTABILIDAD]')).toBe('[SOLICITANTE CONTABILIDAD]');
    });
});
