import { describe, expect, it } from 'vitest';
import { conversationWhen, dayLabel, groupByDay, inlineSegments, messageTime, paragraphs } from './chat';

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
