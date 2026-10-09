import { describe, expect, it } from 'vitest';
import { priorityBreakdown, SIZE_WEIGHTS, suggestedPriority, URGENCY_MULTIPLIERS } from './priority';

/** Score that must exist (size given). */
const score = (input: Parameters<typeof suggestedPriority>[0]): number => {
    const value = suggestedPriority(input);
    if (value === null) throw new Error('expected a score');
    return value;
};

describe('suggestedPriority', () => {
    it('scores GIA-148 (6 h × 8 people, +3 votes, size M, high urgency) as 77', () => {
        expect(suggestedPriority({ hours_per_week: 6, people: 8, votes: 3, size: 'M', urgency: 'high' })).toBe(77);
    });

    it('exposes the documented weights', () => {
        expect(SIZE_WEIGHTS).toEqual({ S: 1, M: 2, L: 3, XL: 5 });
        expect(URGENCY_MULTIPLIERS).toEqual({ low: 0.8, medium: 1, high: 1.2, urgent: 1.5 });
    });

    it('rises with saved hours, people and votes', () => {
        const base = { hours_per_week: 3, people: 2, votes: 0, size: 'M' as const, urgency: 'medium' as const };
        const baseline = score(base);
        expect(score({ ...base, hours_per_week: 6 })).toBeGreaterThan(baseline);
        expect(score({ ...base, people: 4 })).toBeGreaterThan(baseline);
        expect(score({ ...base, votes: 4 })).toBeGreaterThan(baseline);
    });

    it('falls as the effort grows', () => {
        const base = { hours_per_week: 4, people: 5, votes: 0, urgency: 'medium' as const };
        const scores = (['S', 'M', 'L', 'XL'] as const).map((size) => score({ ...base, size }));
        expect(scores).toEqual([...scores].sort((a, b) => b - a));
        expect(new Set(scores).size).toBe(4);
    });

    it('rises with urgency', () => {
        const base = { hours_per_week: 4, people: 5, votes: 0, size: 'L' as const };
        const scores = (['low', 'medium', 'high', 'urgent'] as const).map((urgency) => score({ ...base, urgency }));
        expect(scores).toEqual([...scores].sort((a, b) => a - b));
    });

    it('stays within 0..100', () => {
        expect(suggestedPriority({ hours_per_week: 0, people: 0, votes: 0, size: 'XL', urgency: 'low' })).toBe(0);
        expect(suggestedPriority({ hours_per_week: 40, people: 500, votes: 99, size: 'S', urgency: 'urgent' })).toBeLessThanOrEqual(100);
    });

    it('cannot be scored without a size', () => {
        expect(suggestedPriority({ hours_per_week: 6, people: 8, votes: 0, size: null, urgency: 'high' })).toBeNull();
    });

    it('treats negative inputs as zero', () => {
        expect(suggestedPriority({ hours_per_week: -5, people: 3, votes: -2, size: 'S', urgency: 'medium' })).toBe(0);
    });
});

describe('priorityBreakdown', () => {
    it('explains every factor of the score', () => {
        expect(priorityBreakdown({ hours_per_week: 6, people: 8, votes: 3, size: 'M', urgency: 'high' })).toEqual({
            team_hours: 48,
            impact: 55.5,
            effort: 2,
            urgency_multiplier: 1.2,
            raw: 33.3,
            score: 77,
        });
    });
});
