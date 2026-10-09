/**
 * Estimated vs. actual for projects. Alerts at 80 % and 100 % are fixed;
 * re-approval kicks in when actual (hours, cost or delivery date) goes more
 * than a configurable share above what was approved (20 % by default).
 */

export type BudgetState = 'ok' | 'warning' | 'over';

export const WARNING_RATIO = 0.8;
export const DEFAULT_REAPPROVAL_THRESHOLD = 0.2;

const DAY = 86_400_000;

/** actual ÷ estimate. No estimate: 0 if nothing was spent, otherwise Infinity. */
export function budgetRatio(actual: number, estimate: number): number {
    if (estimate <= 0) return actual > 0 ? Number.POSITIVE_INFINITY : 0;
    return actual / estimate;
}

/** < 80 % ok · 80–100 % warning (100 included) · > 100 % over. */
export function budgetState(actual: number, estimate: number): BudgetState {
    const ratio = budgetRatio(actual, estimate);
    if (ratio > 1) return 'over';
    if (ratio >= WARNING_RATIO) return 'warning';
    return 'ok';
}

function assertThreshold(threshold: number) {
    if (!(threshold >= 0)) throw new RangeError(`Invalid re-approval threshold: ${threshold}`);
}

/** True when `actual` exceeds `estimate` by more than `threshold` (0.2 = 20 %). */
export function needsReapproval(actual: number, estimate: number, threshold = DEFAULT_REAPPROVAL_THRESHOLD): boolean {
    assertThreshold(threshold);
    return actual > estimate * (1 + threshold);
}

/** Calendar day number of an ISO date or date-time, using its written date part. */
function dayNumber(iso: string): number {
    const match = /^(\d{4})-(\d{2})-(\d{2})/.exec(iso);
    if (!match) throw new RangeError(`Invalid ISO date: ${iso}`);
    return Math.round(Date.UTC(Number(match[1]), Number(match[2]) - 1, Number(match[3])) / DAY);
}

/** Days `projected` falls after `approved`; 0 when on time or early. */
export function daysLate(projected: string, approved: string): number {
    return Math.max(0, dayNumber(projected) - dayNumber(approved));
}

/**
 * The delivery date needs re-approval when the delay is more than
 * `threshold` of the approved duration (approval date → approved due date).
 */
export function scheduleNeedsReapproval(
    approvedAt: string,
    approvedDue: string,
    projectedDue: string,
    threshold = DEFAULT_REAPPROVAL_THRESHOLD,
): boolean {
    assertThreshold(threshold);
    const duration = Math.max(1, dayNumber(approvedDue) - dayNumber(approvedAt));
    return daysLate(projectedDue, approvedDue) > duration * threshold;
}
