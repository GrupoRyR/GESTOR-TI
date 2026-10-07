import { describe, expect, it } from 'vitest';
import { formatCOP, formatDate, formatDateTime, formatRelative, formatTime } from './format';

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
