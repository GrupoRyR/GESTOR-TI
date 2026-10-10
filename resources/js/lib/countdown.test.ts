import { describe, expect, it } from 'vitest';
import { countdownFraction, countdownSpoken, formatCountdown, secondsLeft } from './countdown';

const NOW = new Date('2026-10-07T14:40:00Z');

describe('secondsLeft', () => {
    it('counts whole seconds until the deadline, rounding up', () => {
        expect(secondsLeft('2026-10-07T14:49:12Z', NOW)).toBe(552);
        expect(secondsLeft('2026-10-07T14:40:00.300Z', NOW)).toBe(1);
    });

    it('stops at zero', () => {
        expect(secondsLeft('2026-10-07T14:39:00Z', NOW)).toBe(0);
    });
});

describe('formatCountdown', () => {
    it('uses m:ss from one minute up', () => {
        expect(formatCountdown(552)).toBe('9:12');
        expect(formatCountdown(600)).toBe('10:00');
        expect(formatCountdown(60)).toBe('1:00');
    });

    it('uses seconds below one minute', () => {
        expect(formatCountdown(24)).toBe('24 seg');
        expect(formatCountdown(0)).toBe('0 seg');
    });
});

describe('countdownSpoken', () => {
    it('reads minutes and seconds', () => {
        expect(countdownSpoken(552)).toBe('9 minutos y 12 segundos');
        expect(countdownSpoken(60)).toBe('1 minuto');
        expect(countdownSpoken(1)).toBe('1 segundo');
    });
});

describe('countdownFraction', () => {
    it('is the share of time left, clamped to 0..1', () => {
        expect(countdownFraction(15, 30)).toBe(0.5);
        expect(countdownFraction(40, 30)).toBe(1);
        expect(countdownFraction(-1, 30)).toBe(0);
        expect(countdownFraction(5, 0)).toBe(0);
    });
});
