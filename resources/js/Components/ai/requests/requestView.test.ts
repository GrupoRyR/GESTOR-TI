import { describe, expect, it } from 'vitest';
import type { TimelineEvent } from '../../../types/ai';
import { teamHoursLine, timelineIcon, toTimelineItem, type StatusIcons } from './requestView';

const ICONS = { received: 'inbox', evaluating: 'search', pending_approval: 'hourglass' } as StatusIcons;

const event = (overrides: Partial<TimelineEvent>): TimelineEvent => ({
    id: 1,
    kind: 'status',
    family: 'gray',
    title: 'Recibida',
    quote: null,
    detail: null,
    actor: null,
    at: '2026-09-26T10:00:00-05:00',
    ...overrides,
});

describe('timelineIcon', () => {
    it('uses the status icon when a status event names a known status', () => {
        expect(timelineIcon(event({ title: 'Pendiente de aprobación' }), ICONS)).toBe('hourglass');
        expect(timelineIcon(event({ title: 'En evaluación' }), ICONS)).toBe('search');
    });

    it('falls back to the event kind', () => {
        expect(timelineIcon(event({ kind: 'question', title: 'TI preguntó' }), ICONS)).toBe('question');
        expect(timelineIcon(event({ kind: 'approval', title: 'Aprobada por Edward Ramanan' }), ICONS)).toBe('check-circle');
        expect(timelineIcon(event({ kind: 'status', title: 'Algo raro' }), ICONS)).toBe('info');
    });
});

describe('toTimelineItem', () => {
    it('joins the short date with the detail', () => {
        const item = toTimelineItem(event({ id: 14, title: 'Pendiente de aprobación', detail: 'TI la dejó lista para decidir', at: '2026-10-02T10:00:00-05:00' }), ICONS);
        expect(item).toMatchObject({ id: 14, family: 'gray', title: 'Pendiente de aprobación', meta: '2 oct · TI la dejó lista para decidir' });
    });

    it('adds the actor when there is no detail', () => {
        const item = toTimelineItem(event({ kind: 'question', title: 'TI preguntó', actor: 'Jhon Castro', at: '2026-09-29T11:00:00-05:00' }), ICONS);
        expect(item.meta).toBe('29 sep · Jhon Castro');
    });
});

describe('teamHoursLine', () => {
    it('reads "3 h/sem × 4 personas = 12 h"', () => {
        expect(teamHoursLine(3, 4)).toBe('3 h/sem × 4 personas = 12 h');
        expect(teamHoursLine(1.5, 1)).toBe('1,5 h/sem × 1 persona = 1,5 h');
    });

    it('returns null when either number is missing', () => {
        expect(teamHoursLine(null, 4)).toBeNull();
    });
});
