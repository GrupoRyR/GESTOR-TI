import { describe, expect, it } from 'vitest';
import { greeting, longDate } from './greeting';

// Bogotá is UTC-5 all year.
const at = (bogotaTime: string) => new Date(`2026-10-07T${bogotaTime}:00-05:00`);

describe('greeting', () => {
    it('says "Buenos días" before noon in Bogotá', () => {
        expect(greeting(at('06:30'))).toBe('Buenos días');
        expect(greeting(at('11:59'))).toBe('Buenos días');
    });
    it('says "Buenas tardes" from noon to 6:59 p. m.', () => {
        expect(greeting(at('12:00'))).toBe('Buenas tardes');
        expect(greeting(at('18:59'))).toBe('Buenas tardes');
    });
    it('says "Buenas noches" from 7 p. m. and in the early morning', () => {
        expect(greeting(at('19:00'))).toBe('Buenas noches');
        expect(greeting(at('03:00'))).toBe('Buenas noches');
    });
    it('uses Bogotá time, not UTC', () => {
        // 16:00 UTC is 11:00 in Bogotá.
        expect(greeting(new Date('2026-10-07T16:00:00Z'))).toBe('Buenos días');
    });
});

describe('longDate', () => {
    it('writes the weekday and the day of the month in Spanish', () => {
        expect(longDate(at('09:00'))).toBe('Miércoles 7 de octubre');
    });
    it('uses the Bogotá calendar day', () => {
        // 03:00 UTC on Oct 8 is still Wednesday Oct 7 in Bogotá.
        expect(longDate(new Date('2026-10-08T03:00:00Z'))).toBe('Miércoles 7 de octubre');
    });
});
