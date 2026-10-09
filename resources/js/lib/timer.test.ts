import { describe, expect, it } from 'vitest';
import { elapsedSeconds, formatDecimalHours, formatElapsed, formatHours, formatElapsedSpoken } from './timer';

const NOW = new Date('2026-10-07T14:52:40Z');

describe('elapsedSeconds', () => {
    it('counts whole seconds since the start', () => {
        expect(elapsedSeconds('2026-10-07T13:40:00Z', NOW)).toBe(4360);
    });

    it('never goes negative when the clock is behind', () => {
        expect(elapsedSeconds('2026-10-07T15:00:00Z', NOW)).toBe(0);
    });
});

describe('formatElapsed', () => {
    it('pads to hh:mm:ss', () => {
        expect(formatElapsed(4360)).toBe('01:12:40');
        expect(formatElapsed(0)).toBe('00:00:00');
        expect(formatElapsed(59)).toBe('00:00:59');
    });

    it('keeps counting hours past a day', () => {
        expect(formatElapsed(26 * 3600 + 5)).toBe('26:00:05');
    });

    it('floors fractions', () => {
        expect(formatElapsed(61.9)).toBe('00:01:01');
    });
});

describe('formatElapsedSpoken', () => {
    it('reads naturally for screen readers', () => {
        expect(formatElapsedSpoken(4360)).toBe('1 hora, 12 minutos y 40 segundos');
        expect(formatElapsedSpoken(120)).toBe('2 minutos');
        expect(formatElapsedSpoken(1)).toBe('1 segundo');
        expect(formatElapsedSpoken(0)).toBe('0 segundos');
    });
});

describe('formatHours', () => {
    it('writes hours and minutes', () => {
        expect(formatHours(6 + 20 / 60)).toBe('6 h 20 min');
        expect(formatHours(7.8333333)).toBe('7 h 50 min');
        expect(formatHours(1.5)).toBe('1 h 30 min');
    });

    it('drops the empty part', () => {
        expect(formatHours(2)).toBe('2 h');
        expect(formatHours(0.5)).toBe('30 min');
        expect(formatHours(0)).toBe('0 min');
    });

    it('rounds 59.6 minutes up to the next hour', () => {
        expect(formatHours(1 + 59.6 / 60)).toBe('2 h');
    });
});

describe('formatDecimalHours', () => {
    it('uses a decimal comma and drops ",0"', () => {
        expect(formatDecimalHours(1.5)).toBe('1,5 h');
        expect(formatDecimalHours(2)).toBe('2 h');
        expect(formatDecimalHours(92)).toBe('92 h');
    });
});
