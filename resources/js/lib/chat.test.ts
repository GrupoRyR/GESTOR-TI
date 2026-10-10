import { describe, expect, it } from 'vitest';
import { conversationGroups, conversationWhen, dayLabel, groupByDay, inlineSegments, messageTime, paragraphs, pendingQuestion } from './chat';

const NOW = new Date('2026-10-07T09:40:00-05:00');
/** format.ts keeps "9:40 a. m." together with non-breaking spaces. */
const plain = (text: string) => text.replace(/ /g, ' ');

describe('dayLabel', () => {
    it('names today and yesterday, then the long date', () => {
        expect(dayLabel('2026-10-07T09:40:00-05:00', NOW)).toBe('Hoy, miércoles 7 de octubre');
        expect(dayLabel('2026-10-06T18:00:00-05:00', NOW)).toBe('Ayer, martes 6 de octubre');
        expect(dayLabel('2026-10-05T08:00:00-05:00', NOW)).toBe('Lunes 5 de octubre');
    });

    it('uses the Bogotá calendar day, not UTC', () => {
        // 23:30 in Bogotá is already the next day in UTC.
        expect(dayLabel('2026-10-06T23:30:00-05:00', NOW)).toBe('Ayer, martes 6 de octubre');
    });
});

describe('groupByDay', () => {
    it('groups consecutive messages by Bogotá day, keeping order', () => {
        const groups = groupByDay(
            [
                { id: 1, at: '2026-10-06T23:30:00-05:00' },
                { id: 2, at: '2026-10-07T09:40:00-05:00' },
                { id: 3, at: '2026-10-07T09:41:00-05:00' },
            ],
            NOW,
        );
        expect(groups.map((group) => [group.key, group.label, group.items.map((item) => item.id)])).toEqual([
            ['2026-10-06', 'Ayer, martes 6 de octubre', [1]],
            ['2026-10-07', 'Hoy, miércoles 7 de octubre', [2, 3]],
        ]);
    });

    it('returns no groups for no messages', () => {
        expect(groupByDay([], NOW)).toEqual([]);
    });
});

describe('messageTime', () => {
    it('shows only the time inside its day group', () => {
        expect(plain(messageTime('2026-10-07T09:40:00-05:00'))).toBe('9:40 a. m.');
        expect(plain(messageTime('2026-10-07T13:05:00-05:00'))).toBe('1:05 p. m.');
    });
});

describe('conversationWhen', () => {
    it('labels the conversation list', () => {
        expect(plain(conversationWhen('2026-10-07T09:40:00-05:00', NOW))).toBe('Hoy 9:40 a. m.');
        expect(conversationWhen('2026-10-06T16:00:00-05:00', NOW)).toBe('Ayer');
        expect(conversationWhen('2026-10-05T11:00:00-05:00', NOW)).toBe('Hace 2 días');
        expect(conversationWhen('2026-09-30T11:00:00-05:00', NOW)).toBe('Mié 30 sep');
    });
});

describe('answer text', () => {
    it('splits paragraphs on blank lines', () => {
        expect(paragraphs('Uno.\n\nDos.\n\n\n')).toEqual(['Uno.', 'Dos.']);
    });

    it('turns **bold** into segments without HTML', () => {
        expect(inlineSegments('Sale a las **6:00 a. m.** desde <b>SAP</b>')).toEqual([
            { text: 'Sale a las ', bold: false },
            { text: '6:00 a. m.', bold: true },
            { text: ' desde <b>SAP</b>', bold: false },
        ]);
    });

    it('leaves an unclosed marker as text', () => {
        expect(inlineSegments('a **b')).toEqual([{ text: 'a **b', bold: false }]);
    });
});

describe('conversationGroups', () => {
    const item = (id: number, last: string) => ({ id, title: `C${id}`, last_message_at: last });

    it('splits the list into Hoy, Esta semana and Antes, keeping order and skipping empty groups', () => {
        const groups = conversationGroups(
            [item(1, '2026-10-07T09:40:00-05:00'), item(2, '2026-10-06T16:00:00-05:00'), item(3, '2026-10-01T11:00:00-05:00'), item(4, '2026-09-30T11:00:00-05:00')],
            NOW,
        );
        expect(groups.map((group) => [group.label, group.items.map((entry) => entry.id)])).toEqual([
            ['Hoy', [1]],
            ['Esta semana', [2, 3]],
            ['Antes', [4]],
        ]);
    });

    it('uses the Bogotá day: 23:30 yesterday is not today', () => {
        expect(conversationGroups([item(1, '2026-10-06T23:30:00-05:00')], NOW)[0]?.label).toBe('Esta semana');
    });

    it('returns no groups for an empty list', () => {
        expect(conversationGroups([], NOW)).toEqual([]);
    });
});

describe('pendingQuestion', () => {
    it('is the last message when it is a question still without an answer', () => {
        const question = { id: 2, role: 'user' as const };
        expect(pendingQuestion([{ id: 1, role: 'assistant' as const }, question])).toBe(question);
    });

    it('is null when the last message is an answer, or there are none', () => {
        expect(pendingQuestion([{ id: 1, role: 'user' as const }, { id: 2, role: 'assistant' as const }])).toBeNull();
        expect(pendingQuestion([{ id: 1, role: 'system' as const }])).toBeNull();
        expect(pendingQuestion([])).toBeNull();
    });
});
