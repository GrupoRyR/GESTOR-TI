import { describe, expect, it } from 'vitest';
import { formatCOP, formatDate, formatDateTime, formatFileSize, formatLongDate, formatRelative, formatShortDate, formatTime } from './format';

// Intl output uses narrow no-break spaces in some places; normalize them so
// assertions read naturally.
const norm = (value: string) => value.replace(/[  ]/g, ' ');

// 2026-10-07 09:00 in Bogotá (UTC-5) is 14:00 UTC.
const NOW = new Date('2026-10-07T14:00:00Z');

describe('formatDate', () => {
    it('formats in Bogotá time, not UTC', () => {
        // 03:00 UTC on Oct 8 is still Oct 7 in Bogotá.
        expect(norm(formatDate('2026-10-08T03:00:00Z'))).toBe('7 oct 2026');
    });
});

describe('formatTime', () => {
    it('uses a 12-hour clock with the Colombian day-period marker', () => {
        expect(norm(formatTime('2026-10-07T14:40:00Z'))).toBe('9:40 a. m.');
        expect(norm(formatTime('2026-10-07T20:05:00Z'))).toBe('3:05 p. m.');
    });
});

describe('formatDateTime', () => {
    it('combines date and time in Bogotá', () => {
        expect(norm(formatDateTime('2026-10-07T20:05:00Z'))).toBe('7 oct 2026, 3:05 p. m.');
    });
});

describe('formatRelative', () => {
    it('says "hace un momento" for less than a minute', () => {
        expect(norm(formatRelative('2026-10-07T13:59:30Z', NOW))).toBe('hace un momento');
    });

    it('counts minutes', () => {
        expect(norm(formatRelative('2026-10-07T13:48:00Z', NOW))).toBe('hace 12 min');
    });

    it('uses "hoy" with the time for earlier today', () => {
        expect(norm(formatRelative('2026-10-07T11:40:00Z', NOW))).toBe('hoy 6:40 a. m.');
    });

    it('uses "ayer" with the time for the previous Bogotá day', () => {
        // 2026-10-07 01:00 UTC is 2026-10-06 20:00 in Bogotá.
        expect(norm(formatRelative('2026-10-07T01:00:00Z', NOW))).toBe('ayer 8:00 p. m.');
    });

    it('counts days within a week', () => {
        expect(norm(formatRelative('2026-10-05T15:00:00Z', NOW))).toBe('hace 2 días');
    });

    it('falls back to the date after a week', () => {
        expect(norm(formatRelative('2026-09-20T15:00:00Z', NOW))).toBe('20 sept 2026');
    });

    it('handles future instants', () => {
        expect(norm(formatRelative('2026-10-07T14:30:00Z', NOW))).toBe('en 30 min');
    });
});

describe('formatCOP', () => {
    it('formats pesos with dot thousands and no decimals', () => {
        expect(norm(formatCOP(14200000))).toBe('$ 14.200.000');
    });

    it('rounds away cents', () => {
        expect(norm(formatCOP(1999.6))).toBe('$ 2.000');
    });

    it('formats zero and negatives', () => {
        expect(norm(formatCOP(0))).toBe('$ 0');
        expect(norm(formatCOP(-350000))).toBe('-$ 350.000');
    });
});

describe('formatLongDate', () => {
    it('spells weekday, day and month in Bogotá time', () => {
        expect(formatLongDate('2026-10-13T17:00:00-05:00')).toBe('martes 13 de octubre');
        // 02:00 UTC on Oct 8 is still Wednesday Oct 7 in Bogotá.
        expect(formatLongDate('2026-10-08T02:00:00Z')).toBe('miércoles 7 de octubre');
    });
});

describe('formatShortDate', () => {
    it('drops the year: "26 sep"', () => {
        expect(formatShortDate('2026-09-26T10:00:00-05:00')).toBe('26 sep');
        expect(formatShortDate('2026-10-31')).toBe('31 oct');
    });
});

describe('formatFileSize', () => {
    it('uses KB under a megabyte and MB with one decimal above', () => {
        expect(formatFileSize(320_000)).toBe('313 KB');
        expect(formatFileSize(48_000)).toBe('47 KB');
        expect(formatFileSize(500)).toBe('1 KB');
        expect(formatFileSize(2_621_440)).toBe('2,5 MB');
    });
});
