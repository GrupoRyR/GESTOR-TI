import { describe, expect, it } from 'vitest';
import { budgetRatio, budgetState, daysLate, needsReapproval, scheduleNeedsReapproval } from './budget';

describe('budgetRatio', () => {
    it('divides actual by estimate', () => {
        expect(budgetRatio(14_200_000, 16_000_000)).toBeCloseTo(0.8875, 4);
    });

    it('is 0 with nothing spent and no estimate, Infinity when spending without an estimate', () => {
        expect(budgetRatio(0, 0)).toBe(0);
        expect(budgetRatio(5, 0)).toBe(Number.POSITIVE_INFINITY);
    });
});

describe('budgetState', () => {
    it('is ok below 80 %', () => {
        expect(budgetState(92, 120)).toBe('ok');
        expect(budgetState(79.9, 100)).toBe('ok');
    });

    it('warns from 80 % up to 100 % included', () => {
        expect(budgetState(80, 100)).toBe('warning');
        expect(budgetState(14_200_000, 16_000_000)).toBe('warning');
        expect(budgetState(100, 100)).toBe('warning');
    });

    it('is over above 100 %', () => {
        expect(budgetState(100.5, 100)).toBe('over');
        expect(budgetState(1, 0)).toBe('over');
    });
});

describe('needsReapproval', () => {
    it('triggers only above 20 % by default', () => {
        expect(needsReapproval(120, 100)).toBe(false);
        expect(needsReapproval(120.1, 100)).toBe(true);
    });

    it('accepts a configured threshold', () => {
        expect(needsReapproval(110, 100, 0.05)).toBe(true);
        expect(needsReapproval(130, 100, 0.5)).toBe(false);
    });

    it('rejects a negative threshold', () => {
        expect(() => needsReapproval(1, 1, -0.1)).toThrow(RangeError);
    });
});

describe('daysLate', () => {
    it('counts calendar days after the approved date (P-07: 31 oct → 14 nov)', () => {
        expect(daysLate('2026-11-14', '2026-10-31')).toBe(14);
    });

    it('is 0 when on time or early', () => {
        expect(daysLate('2026-10-31', '2026-10-31')).toBe(0);
        expect(daysLate('2026-10-20', '2026-10-31')).toBe(0);
    });

    it('ignores the time of day and uses the date part', () => {
        expect(daysLate('2026-11-01T23:00:00-05:00', '2026-10-31')).toBe(1);
    });
});

describe('scheduleNeedsReapproval', () => {
    it('compares the delay with the approved duration (P-07: 14 of 31 days)', () => {
        expect(scheduleNeedsReapproval('2026-09-30', '2026-10-31', '2026-11-14')).toBe(true);
    });

    it('allows up to the threshold', () => {
        // 10 days approved, 2 late = 20 %: not above.
        expect(scheduleNeedsReapproval('2026-10-01', '2026-10-11', '2026-10-13')).toBe(false);
        expect(scheduleNeedsReapproval('2026-10-01', '2026-10-11', '2026-10-14')).toBe(true);
    });
});
