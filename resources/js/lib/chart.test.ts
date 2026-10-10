import { describe, expect, it } from 'vitest';
import { linearScale, nearestIndex, niceTicks } from './chart';

describe('niceTicks', () => {
    it('covers the data with round steps (hours saved: 96..210 from zero)', () => {
        expect(niceTicks(0, 210, 5)).toEqual([0, 50, 100, 150, 200, 250]);
    });

    it('uses 1-2-5 steps', () => {
        expect(niceTicks(0, 134, 5)).toEqual([0, 20, 40, 60, 80, 100, 120, 140]);
        expect(niceTicks(0, 10, 5)).toEqual([0, 2, 4, 6, 8, 10]);
        expect(niceTicks(0, 0.9, 3)).toEqual([0, 0.2, 0.4, 0.6, 0.8, 1]);
    });

    it('handles a flat series', () => {
        expect(niceTicks(0, 0, 5)).toEqual([0, 1]);
        const ticks = niceTicks(5, 5, 5);
        expect(ticks).toContain(5);
        expect(ticks[0]).toBeLessThan(5);
        expect(ticks[ticks.length - 1]).toBeGreaterThan(5);
    });

    it('swaps a reversed domain and keeps the extremes inside', () => {
        const ticks = niceTicks(120, 20, 5);
        expect(ticks[0]).toBeLessThanOrEqual(20);
        expect(ticks[ticks.length - 1]).toBeGreaterThanOrEqual(120);
    });

    it('always returns ascending values with a constant step', () => {
        for (const [min, max] of [
            [0, 134],
            [3, 87],
            [-40, 260],
            [0, 0.03],
        ] as const) {
            const ticks = niceTicks(min, max, 5);
            expect(ticks[0]).toBeLessThanOrEqual(min);
            expect(ticks[ticks.length - 1]).toBeGreaterThanOrEqual(max);
            const steps = ticks.slice(1).map((tick, index) => tick - ticks[index]!);
            for (const step of steps) expect(step).toBeCloseTo(steps[0]!, 9);
            const magnitude = 10 ** Math.floor(Math.log10(steps[0]!));
            expect([1, 2, 2.5, 5, 10]).toContainEqual(Math.round((steps[0]! / magnitude) * 10) / 10);
        }
    });
});

describe('linearScale', () => {
    it('maps the domain onto the range, also inverted (SVG y axis)', () => {
        const y = linearScale([0, 250], [256, 16]);
        expect(y(0)).toBe(256);
        expect(y(250)).toBe(16);
        expect(y(210)).toBeCloseTo(54.4, 5);
    });

    it('returns the range start for a zero-width domain', () => {
        expect(linearScale([5, 5], [0, 100])(5)).toBe(0);
    });
});

describe('nearestIndex', () => {
    it('finds the closest x position', () => {
        const xs = [60, 172.8, 285.6, 398.4];
        expect(nearestIndex(xs, 0)).toBe(0);
        expect(nearestIndex(xs, 220)).toBe(1);
        expect(nearestIndex(xs, 240)).toBe(2);
        expect(nearestIndex(xs, 999)).toBe(3);
        expect(nearestIndex([], 10)).toBe(-1);
    });
});
