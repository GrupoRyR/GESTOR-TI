/**
 * Suggested priority of a request (0–100): impact ÷ effort, adjusted by
 * urgency. TI sees it while evaluating and can override it; the server
 * stores both (`priority_suggested`, `priority_final`).
 *
 *   team_hours = hours_per_week × people          (time the team loses per week)
 *   impact     = team_hours + 2.5 × votes         (each +1 is worth ~2.5 h/week)
 *   raw        = impact ÷ SIZE_WEIGHTS[size] × URGENCY_MULTIPLIERS[urgency]
 *   score      = round(100 × raw ÷ (raw + 10))    (saturates towards 100)
 *
 * The saturating curve keeps small requests distinguishable while very large
 * ones approach, but never reach, 100. Negative inputs count as zero.
 */

export type EffortSize = 'S' | 'M' | 'L' | 'XL';

/** low = Puede esperar / Baja · medium = Este trimestre / Media · high = Este mes / Alta · urgent = Urgente. */
export type Urgency = 'low' | 'medium' | 'high' | 'urgent';

export const SIZE_WEIGHTS: Record<EffortSize, number> = { S: 1, M: 2, L: 3, XL: 5 };

export const URGENCY_MULTIPLIERS: Record<Urgency, number> = { low: 0.8, medium: 1, high: 1.2, urgent: 1.5 };

/** Hours per week each +1 vote adds to the impact. */
export const VOTE_WEIGHT = 2.5;

/** Raw value that scores 50. */
const HALF_POINT = 10;

export interface PriorityInput {
    hours_per_week: number;
    people: number;
    votes: number;
    /** Not evaluated yet → no score. */
    size: EffortSize | null;
    urgency: Urgency;
}

export interface PriorityBreakdown {
    team_hours: number;
    impact: number;
    effort: number;
    urgency_multiplier: number;
    raw: number;
    score: number;
}

const positive = (value: number) => (Number.isFinite(value) && value > 0 ? value : 0);
const round = (value: number, digits = 4) => Number(value.toFixed(digits));

/** Every factor of the score, for the "¿Cómo se calcula?" explanation. */
export function priorityBreakdown(input: PriorityInput & { size: EffortSize }): PriorityBreakdown {
    const team_hours = round(positive(input.hours_per_week) * positive(input.people));
    const impact = round(team_hours + VOTE_WEIGHT * positive(input.votes));
    const effort = SIZE_WEIGHTS[input.size];
    const urgency_multiplier = URGENCY_MULTIPLIERS[input.urgency];
    const raw = round((impact / effort) * urgency_multiplier);
    const score = Math.min(100, Math.max(0, Math.round((100 * raw) / (raw + HALF_POINT))));
    return { team_hours, impact, effort, urgency_multiplier, raw, score };
}

/** 0–100, or `null` while the request has no size. */
export function suggestedPriority(input: PriorityInput): number | null {
    if (input.size === null) return null;
    return priorityBreakdown({ ...input, size: input.size }).score;
}
