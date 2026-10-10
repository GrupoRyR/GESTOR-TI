import { describe, expect, it } from 'vitest';
import { expiryText, minutesLeft } from './telegramExpiry';

const NOW = new Date('2026-10-07T14:00:00Z');

describe('minutesLeft', () => {
    it('rounds partial minutes up so "vence en 1 minuto" never shows 0', () => {
        expect(minutesLeft('2026-10-07T14:09:00Z', NOW)).toBe(9);
        expect(minutesLeft('2026-10-07T14:08:01Z', NOW)).toBe(9);
        expect(minutesLeft('2026-10-07T14:00:20Z', NOW)).toBe(1);
    });
    it('is 0 once the link expired', () => {
        expect(minutesLeft('2026-10-07T14:00:00Z', NOW)).toBe(0);
        expect(minutesLeft('2026-10-07T13:40:00Z', NOW)).toBe(0);
    });
    it('understands offsets', () => {
        expect(minutesLeft('2026-10-07T09:05:00-05:00', NOW)).toBe(5);
    });
});

describe('expiryText', () => {
    it('uses singular and plural', () => {
        expect(expiryText(1)).toBe('El enlace vence en 1 minuto.');
        expect(expiryText(9)).toBe('El enlace vence en 9 minutos.');
    });
    it('says it expired at 0', () => {
        expect(expiryText(0)).toBe('El enlace ya venció.');
    });
});
