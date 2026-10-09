import { describe, expect, it } from 'vitest';
import { hoursText, leadTimeDelta, monthLong, monthShort, monthsText, paybackScaleMax, periodPrevious, periodRangeText } from './value';

describe('months', () => {
    it('labels months for ticks and tooltips', () => {
        expect(monthShort('2026-08')).toBe('ago');
        expect(monthShort('2026-09')).toBe('sep');
        expect(monthLong('2026-08')).toBe('Agosto 2026');
    });

    it('writes the period range', () => {
        expect(periodRangeText('2026-04-01', '2026-09-30')).toBe('Abril a septiembre de 2026');
        expect(periodRangeText('2025-10-01', '2026-09-30')).toBe('Octubre de 2025 a septiembre de 2026');
        expect(periodRangeText('2026-09-01', '2026-09-30')).toBe('Septiembre de 2026');
    });
});

describe('value wording', () => {
    it('writes hours and months', () => {
        expect(hoursText(210)).toBe('210 h');
        expect(hoursText(12.5)).toBe('12,5 h');
        expect(monthsText(8.1)).toBe('8,1 meses');
        expect(monthsText(10)).toBe('10,0 meses');
        expect(monthsText(1)).toBe('1,0 mes');
    });

    it('phrases the lead-time change: fewer days is good', () => {
        expect(leadTimeDelta(-6, '6m')).toEqual({ text: '6 días menos que el semestre anterior', direction: 'down', good: true });
        expect(leadTimeDelta(3, '3m')).toEqual({ text: '3 días más que el trimestre anterior', direction: 'up', good: false });
        expect(leadTimeDelta(-1, '12m')?.text).toBe('1 día menos que el año anterior');
        expect(leadTimeDelta(0, '6m')).toBeUndefined();
        expect(periodPrevious('year')).toBe('el año anterior');
    });
});

describe('paybackScaleMax', () => {
    it('rounds the longest payback up to a multiple of 5', () => {
        expect(paybackScaleMax([8.1, 8.8, 10, 12.3])).toBe(15);
        expect(paybackScaleMax([15])).toBe(15);
        expect(paybackScaleMax([2])).toBe(5);
        expect(paybackScaleMax([])).toBe(5);
    });
});
