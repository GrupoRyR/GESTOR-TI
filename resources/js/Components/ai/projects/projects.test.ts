import { describe, expect, it } from 'vitest';
import {
    addDays,
    budgetLines,
    burnRate,
    costsTotal,
    dropHint,
    entryDate,
    hoursByPerson,
    moveTask,
    projectedEnd,
    reapprovalReasons,
    reapprovalSentence,
    recipientCosts,
    recipientsOf,
    requirementState,
    respondDeadline,
    weekBounds,
    weekLabel,
    wipText,
} from './projects';

/** Currency uses a no-break space after "$". */
const plain = <T,>(value: T): T => JSON.parse(JSON.stringify(value).replace(/ /g, ' ')) as T;

const jhon = { id: 1, name: 'Jhon Castro' };
const leonardo = { id: 2, name: 'Leonardo Herrera' };

describe('dates', () => {
    it('adds calendar days to a plain date, across months', () => {
        expect(addDays('2026-10-07', 1)).toBe('2026-10-08');
        expect(addDays('2026-10-07', 38)).toBe('2026-11-14');
        expect(addDays('2026-10-01', -1)).toBe('2026-09-30');
    });

    it('finds the Monday–Sunday week of a date', () => {
        expect(weekBounds('2026-10-07')).toEqual({ from: '2026-10-05', to: '2026-10-11' });
        expect(weekBounds('2026-10-05')).toEqual({ from: '2026-10-05', to: '2026-10-11' });
        expect(weekBounds('2026-10-11')).toEqual({ from: '2026-10-05', to: '2026-10-11' });
    });

    it('names the week, with both months when it crosses one', () => {
        expect(weekLabel({ from: '2026-10-05', to: '2026-10-11' })).toBe('Semana del 5 al 11 oct');
        expect(weekLabel({ from: '2026-09-28', to: '2026-10-04' })).toBe('Semana del 28 sep al 4 oct');
    });

    it('turns Hoy / Ayer / Otra fecha into a date', () => {
        expect(entryDate('today', '2026-10-07', null)).toBe('2026-10-07');
        expect(entryDate('yesterday', '2026-10-07', null)).toBe('2026-10-06');
        expect(entryDate('other', '2026-10-07', '2026-10-02')).toBe('2026-10-02');
        expect(entryDate('other', '2026-10-07', null)).toBeNull();
    });
});

describe('hours', () => {
    const entries = [
        { date: '2026-10-07', person: jhon, hours: 1.5 },
        { date: '2026-10-06', person: jhon, hours: 2 },
        { date: '2026-10-06', person: leonardo, hours: 2 },
        { date: '2026-10-05', person: jhon, hours: 1 },
        { date: '2026-10-05', person: jhon, hours: 1.5 },
        { date: '2026-10-02', person: leonardo, hours: 3 },
    ];

    it('adds hours per person, most hours first', () => {
        expect(hoursByPerson(entries)).toEqual([
            { person: jhon, hours: 6 },
            { person: leonardo, hours: 5 },
        ]);
    });

    it('keeps only the entries inside a week', () => {
        expect(hoursByPerson(entries, { from: '2026-10-05', to: '2026-10-11' })).toEqual([
            { person: jhon, hours: 6 },
            { person: leonardo, hours: 2 },
        ]);
    });

    it('averages the recent hours per week', () => {
        expect(burnRate(15.6, 2)).toBeCloseTo(7.8);
        expect(burnRate(10, 0)).toBe(0);
    });

    it('projects the end date from the hours left and the weekly pace', () => {
        // 42 h left at 7.8 h/week: 37.7 days, rounded up to the 38th day.
        expect(projectedEnd('2026-10-07', 42, 7.8)).toBe('2026-11-14');
        expect(projectedEnd('2026-10-07', 0, 7.8)).toBe('2026-10-07');
        expect(projectedEnd('2026-10-07', 42, 0)).toBeNull();
    });
});

describe('budget', () => {
    const p07 = {
        hours: { estimate: 120, actual: 92 },
        cost: { estimate: 16_000_000, actual: 14_200_000 },
        due_date: '2026-10-31',
        projected_due_date: '2026-11-14',
        approved_at: '2026-09-30',
    };

    it('lists what went more than the threshold above the approval', () => {
        expect(reapprovalReasons(p07)).toEqual(['date']);
        expect(reapprovalReasons({ ...p07, hours: { estimate: 120, actual: 150 } })).toEqual(['hours', 'date']);
        expect(reapprovalReasons({ ...p07, projected_due_date: '2026-10-31' })).toEqual([]);
        expect(reapprovalReasons({ ...p07, cost: null, projected_due_date: '2026-10-31' }, 0.1)).toEqual([]);
    });

    it('says what needs a new approval', () => {
        expect(reapprovalSentence(['date'], 0.2)).toBe('Lo real pasa el 20 % de lo aprobado en la fecha: pide nueva aprobación');
        expect(reapprovalSentence(['hours', 'cost'], 0.25)).toBe('Lo real pasa el 25 % de lo aprobado en las horas y el costo: pide nueva aprobación');
        expect(reapprovalSentence(['hours', 'cost', 'date'], 0.2)).toBe(
            'Lo real pasa el 20 % de lo aprobado en las horas, el costo y la fecha: pide nueva aprobación',
        );
    });

    it('writes the line under each budget bar', () => {
        const lines = plain(budgetLines(p07));
        expect(lines.hours).toEqual({ value: '92 de 120 h', message: 'Quedan 28 h' });
        expect(lines.cost).toEqual({ value: '$ 14.200.000 de $ 16.000.000', message: 'Pasó el 80 %: aviso enviado a los aprobadores' });
        expect(lines.date).toEqual({ value: '14 nov · estimada 31 oct', message: '14 días después de lo aprobado', late: 14 });
    });

    it('covers going over and being on time', () => {
        const lines = plain(budgetLines({ ...p07, hours: { estimate: 48, actual: 50 }, cost: { estimate: 6_300_000, actual: 6_500_000 }, projected_due_date: '2026-10-31' }));
        expect(lines.hours.message).toBe('Pasó por 2 h');
        expect(lines.cost?.message).toBe('Pasó el 100 %: aviso enviado a los aprobadores');
        expect(lines.date).toEqual({ value: '31 oct · estimada 31 oct', message: 'A tiempo', late: 0 });
        expect(budgetLines({ ...p07, cost: null }).cost).toBeNull();
        expect(plain(budgetLines({ ...p07, cost: { estimate: 16_000_000, actual: 2_000_000 } })).cost?.message).toBe('Quedan $ 14.000.000');
    });

    it('adds the project costs', () => {
        expect(costsTotal([{ amount: 12_880_000 }, { amount: 804_000 }, { amount: 380_000 }, { amount: 90_000 }, { amount: 46_000 }])).toBe(14_200_000);
        expect(costsTotal([])).toBe(0);
    });

    it('phrases the WIP limit', () => {
        expect(wipText({ in_development: 3, limit: 3 })).toBe('3 de 3 en desarrollo');
        expect(wipText({ in_development: 1, limit: 3 })).toBe('1 de 3 en desarrollo');
    });
});

describe('requirements', () => {
    const base = { status: 'pending' as const, due_date: '2026-10-09', answered_at: null };

    it('counts the days left on a pending requirement', () => {
        expect(requirementState(base, '2026-10-07')).toEqual({ level: 'pending', label: 'en 2 días', days: 2 });
        expect(requirementState({ ...base, due_date: '2026-10-08' }, '2026-10-07')).toEqual({ level: 'pending', label: 'mañana', days: 1 });
        expect(requirementState({ ...base, due_date: '2026-10-07' }, '2026-10-07')).toEqual({ level: 'pending', label: 'hoy', days: 0 });
    });

    it('marks it overdue once the date passed, even if the server still says pending', () => {
        expect(requirementState({ ...base, status: 'overdue', due_date: '2026-10-01' }, '2026-10-07')).toEqual({ level: 'overdue', label: 'hace 6 días', days: -6 });
        expect(requirementState({ ...base, due_date: '2026-10-06' }, '2026-10-07')).toEqual({ level: 'overdue', label: 'hace 1 día', days: -1 });
    });

    it('tells when it was answered', () => {
        expect(requirementState({ status: 'answered', due_date: '2026-10-05', answered_at: '2026-10-05T11:00:00-05:00' }, '2026-10-07')).toEqual({
            level: 'answered',
            label: 'respondido el 5 oct',
            days: -2,
        });
    });

    it('words the deadline for the person who must answer', () => {
        expect(respondDeadline(base, '2026-10-07')).toBe('Faltan 2 días');
        expect(respondDeadline({ ...base, due_date: '2026-10-08' }, '2026-10-07')).toBe('Falta 1 día');
        expect(respondDeadline({ ...base, due_date: '2026-10-07' }, '2026-10-07')).toBe('Vence hoy');
        expect(respondDeadline({ ...base, due_date: '2026-10-04' }, '2026-10-07')).toBe('Venció hace 3 días');
    });
});

describe('report recipients', () => {
    const allowed = { person: jhon, can_view_costs: true, include_costs: true };
    const requester = { person: { id: 11, name: 'Gerencia de cartera' }, can_view_costs: false, include_costs: false };

    it('lets only people with cost permission get time and cost', () => {
        expect(recipientCosts(allowed, 'internal')).toEqual({ disabled: false, checked: true, reason: null });
        expect(recipientCosts({ ...allowed, include_costs: false }, 'internal')).toEqual({ disabled: false, checked: false, reason: null });
        expect(recipientCosts({ ...requester, include_costs: true }, 'internal')).toEqual({
            disabled: true,
            checked: false,
            reason: 'No tiene permiso de costos: recibe la versión sin tiempo ni costo.',
        });
    });

    it('splits recipients between the internal and the requester version', () => {
        const list = [allowed, { ...allowed, person: leonardo, include_costs: false }, requester];
        expect(recipientsOf(list, 'internal').map((item) => item.person.name)).toEqual(['Jhon Castro']);
        expect(recipientsOf(list, 'requester').map((item) => item.person.name)).toEqual(['Leonardo Herrera', 'Gerencia de cartera']);
    });
});

describe('board', () => {
    const tasks = [
        { key: 'T6', column: 'backlog' as const, position: 1 },
        { key: 'T9', column: 'backlog' as const, position: 2 },
        { key: 'T4', column: 'in_progress' as const, position: 1 },
        { key: 'T7', column: 'in_progress' as const, position: 2 },
        { key: 'T3', column: 'review' as const, position: 1 },
    ];

    it('moves a card to the end of another column and renumbers both', () => {
        const moved = moveTask(tasks, 'T6', 'in_progress');
        expect(moved.find((task) => task.key === 'T6')).toEqual({ key: 'T6', column: 'in_progress', position: 3 });
        expect(moved.find((task) => task.key === 'T9')).toEqual({ key: 'T9', column: 'backlog', position: 1 });
    });

    it('inserts at a position', () => {
        const moved = moveTask(tasks, 'T3', 'in_progress', 1);
        expect(
            moved
                .filter((task) => task.column === 'in_progress')
                .sort((a, b) => a.position - b.position)
                .map((task) => task.key),
        ).toEqual(['T3', 'T4', 'T7']);
    });

    it('leaves the list alone for an unknown card', () => {
        expect(moveTask(tasks, 'T99', 'done')).toEqual(tasks);
    });

    it('previews the WIP count when dropping', () => {
        expect(dropHint(tasks, 'in_progress', 'T6', 3)).toBe('Quedarían 3 de 3');
        expect(dropHint(tasks, 'in_progress', 'T4', 3)).toBeNull();
        expect(dropHint(tasks, 'review', 'T6', undefined)).toBeNull();
    });
});
