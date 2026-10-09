/**
 * Small chart math for the SVG charts (no chart library): round axis ticks,
 * a linear scale and the nearest data point for the crosshair.
 */

const precision = (value: number) => Number(value.toPrecision(12));

/** Step of 1, 2 or 5 × 10ⁿ closest to `span / count`. */
function niceStep(span: number, count: number): number {
    const raw = span / Math.max(1, count);
    const magnitude = 10 ** Math.floor(Math.log10(raw));
    const error = raw / magnitude;
    const factor = error >= 7.07 ? 10 : error >= 3.16 ? 5 : error >= 1.41 ? 2 : 1;
    return factor * magnitude;
}

/**
 * Ascending ticks with a 1-2-5 step that cover `[min, max]` (the first tick
 * ≤ min, the last ≥ max), about `count` intervals.
 */
export function niceTicks(min: number, max: number, count = 5): number[] {
    let lo = Math.min(min, max);
    let hi = Math.max(min, max);
    if (lo === hi) {
        if (lo === 0) return [0, 1];
        const pad = Math.abs(lo) * 0.1;
        lo -= pad;
        hi += pad;
    }
    const step = niceStep(hi - lo, count);
    const first = Math.floor(precision(lo / step));
    const last = Math.ceil(precision(hi / step));
    const ticks: number[] = [];
    for (let index = first; index <= last; index++) ticks.push(precision(index * step));
    return ticks;
}

export type Scale = (value: number) => number;

/** Maps `domain` linearly onto `range` (pass `[bottom, top]` for an SVG y axis). */
export function linearScale([d0, d1]: [number, number], [r0, r1]: [number, number]): Scale {
    const span = d1 - d0;
    if (span === 0) return () => r0;
    return (value) => r0 + ((value - d0) / span) * (r1 - r0);
}

/** Index of the x position closest to `x`; -1 for an empty list. */
export function nearestIndex(xs: readonly number[], x: number): number {
    let best = -1;
    let distance = Number.POSITIVE_INFINITY;
    xs.forEach((value, index) => {
        const d = Math.abs(value - x);
        if (d < distance) {
            best = index;
            distance = d;
        }
    });
    return best;
}
