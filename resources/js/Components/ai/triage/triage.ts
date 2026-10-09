/**
 * Pure helpers for the IT triage pages (inbox, request detail, approvals,
 * backlog, my week): deadline wording, the first-response semáforo, waiting
 * times, estimate totals, what a merge does and the payback sentence.
 * Every date is read as Bogotá wall time; pass `now` for stable output.
 */
import { formatShortDate, formatTime } from '../../../lib/format';

export type DueLevel = 'late' | 'today' | 'ok';

export interface DueState {
    level: DueLevel;
    label: string;
}

const MINUTE = 60_000;
const HOUR = 60 * MINUTE;
const DAY = 24 * HOUR;

const dayParts = new Intl.DateTimeFormat('en-CA', { timeZone: 'America/Bogota', year: 'numeric', month: '2-digit', day: '2-digit' });
const weekdayFormat = new Intl.DateTimeFormat('es-CO', { timeZone: 'America/Bogota', weekday: 'long' });

const isPlainDate = (iso: string) => /^\d{4}-\d{2}-\d{2}$/.test(iso);

/** Plain dates are calendar days in Bogotá (noon avoids any offset slip). */
function instant(iso: string): Date {
    const date = new Date(isPlainDate(iso) ? `${iso}T12:00:00-05:00` : iso);
    if (Number.isNaN(date.getTime())) throw new RangeError(`Invalid ISO 8601 date: ${iso}`);
    return date;
}

/** Days since epoch of the Bogotá calendar date. */
function bogotaDay(date: Date): number {
    const [year, month, day] = dayParts.format(date).split('-').map(Number) as [number, number, number];
    return Math.round(Date.UTC(year, month - 1, day) / DAY);
}

const days = (count: number) => `${count} ${count === 1 ? 'día' : 'días'}`;
const capitalize = (text: string) => text.charAt(0).toUpperCase() + text.slice(1);

/**
 * Deadline wording. `deadline` (inbox, first response): "Vencida hace 1 día",
 * "Vence hoy, 5:00 p. m.", "Vence mañana", "Vence el viernes", "Vence el 21 oct".
 * `agenda` (Mi semana): "Hoy, 5:00 p. m.", "Jueves 8 oct".
 */
export function dueState(dueAt: string, now: Date = new Date(), style: 'deadline' | 'agenda' = 'deadline'): DueState {
    const plain = isPlainDate(dueAt);
    const due = instant(dueAt);
    const dayDiff = bogotaDay(due) - bogotaDay(now);
    const time = plain ? '' : `, ${formatTime(dueAt)}`;

    if (dayDiff < 0) return { level: 'late', label: `Vencida hace ${days(-dayDiff)}` };
    if (dayDiff === 0) {
        if (!plain && due.getTime() < now.getTime()) {
            const hours = Math.max(1, Math.floor((now.getTime() - due.getTime()) / HOUR));
            return { level: 'late', label: `Vencida hace ${hours} h` };
        }
        return { level: 'today', label: style === 'agenda' ? `Hoy${time}` : `Vence hoy${time}` };
    }
    if (style === 'agenda') {
        return { level: 'ok', label: `${capitalize(weekdayFormat.format(due))} ${formatShortDate(dueAt)}` };
    }
    if (dayDiff === 1) return { level: 'ok', label: 'Vence mañana' };
    if (dayDiff < 7) return { level: 'ok', label: `Vence el ${weekdayFormat.format(due)}` };
    return { level: 'ok', label: `Vence el ${formatShortDate(dueAt)}` };
}

/** How long the first response took: "Respondida en 4 h", "… el mismo día", "… en 1 día". */
export function answeredLabel(createdAt: string, answeredAt: string): string {
    const created = instant(createdAt);
    const answered = instant(answeredAt);
    const elapsed = Math.max(0, answered.getTime() - created.getTime());
    if (elapsed < HOUR) return `Respondida en ${Math.max(1, Math.round(elapsed / MINUTE))} min`;
    const dayDiff = bogotaDay(answered) - bogotaDay(created);
    if (dayDiff === 0) return elapsed <= 4 * HOUR ? `Respondida en ${Math.round(elapsed / HOUR)} h` : 'Respondida el mismo día';
    return `Respondida en ${days(dayDiff)}`;
}

export type FirstResponseLevel = DueLevel | 'answered';

/** First-response semáforo of a request row. */
export function firstResponseState(
    firstResponse: { due_at: string; answered_at: string | null },
    createdAt: string,
    now: Date = new Date(),
): { level: FirstResponseLevel; label: string } {
    if (firstResponse.answered_at) return { level: 'answered', label: answeredLabel(createdAt, firstResponse.answered_at) };
    return dueState(firstResponse.due_at, now);
}

/** Waiting time: "hace 30 min", "hace 1 h", "hace 2 días". */
export function sinceLabel(since: string, now: Date = new Date()): string {
    const start = instant(since);
    const elapsed = Math.max(0, now.getTime() - start.getTime());
    if (elapsed < HOUR) return `hace ${Math.max(1, Math.floor(elapsed / MINUTE))} min`;
    const dayDiff = bogotaDay(now) - bogotaDay(start);
    if (dayDiff === 0) return `hace ${Math.floor(elapsed / HOUR)} h`;
    return `hace ${days(dayDiff)}`;
}

/** Hours the team loses per week (hours × people), or null while unknown. */
export function teamHours(hoursPerWeek: number | null, people: number | null): number | null {
    if (hoursPerWeek === null || people === null) return null;
    return Number((hoursPerWeek * people).toFixed(2));
}

const hoursOrZero = (value: number) => (Number.isFinite(value) && value > 0 ? value : 0);

/**
 * Estimate shown while sending to approval: total hours and, only when every
 * rate is known (the viewer has `ai.costs.view`), hours × rate + external costs.
 */
export function estimateTotals(rows: { hours: number; rate: number | null }[], external: number | null): { hours: number; cost: number | null } {
    const hours = rows.reduce((sum, row) => sum + hoursOrZero(row.hours), 0);
    if (rows.some((row) => row.rate === null)) return { hours, cost: null };
    const team = rows.reduce((sum, row) => sum + hoursOrZero(row.hours) * (row.rate ?? 0), 0);
    return { hours, cost: Math.round(team + hoursOrZero(external ?? 0)) };
}

/**
 * "Qué pasa al unir": the request closes as a duplicate, its votes and its
 * requester move to the one that stays. People = both vote counts plus the
 * person who asked for the closed one.
 */
export function mergeOutcome(
    source: { key: string; votes: number; requester: string },
    into: { key: string; votes: number },
): { people: number; lines: string[] } {
    const people = into.votes + source.votes + 1;
    const votes =
        source.votes === 0
            ? `${into.key} queda con ${people} personas.`
            : source.votes === 1
              ? `Su voto pasa a ${into.key}, que queda con ${people} personas.`
              : `Sus ${source.votes} votos pasan a ${into.key}, que queda con ${people} personas.`;
    return {
        people,
        lines: [
            `${source.key} se cierra como duplicada de ${into.key}.`,
            votes,
            `${source.requester} queda como seguidora y recibe los avisos de ${into.key}.`,
            `Sus respuestas y adjuntos se copian a ${into.key}.`,
        ],
    };
}

/** Return sentence for an approval: months to pay back, or why it is unknown. */
export function paybackText(months: number | null, areaName: string): string {
    if (months === null) {
        return `Sin valor de hora del área. ${areaName} aún no tiene valor de hora, así que no se calcula en cuántos meses se paga.`;
    }
    if (months < 1) return 'Se paga en menos de un mes con las horas que ahorra.';
    const rounded = Math.round(months);
    if (rounded <= 1) return 'Se paga en cerca de un mes con las horas que ahorra.';
    return `Se paga en unos ${rounded} meses con las horas que ahorra.`;
}

/** How copy addresses someone: "Paola" for "Paola Ríos"; areas ("Gerencia de cartera") and placeholders stay whole. */
export function firstName(name: string): string {
    const trimmed = name.trim();
    if (trimmed.startsWith('[') || /\s(de|del)\s/i.test(trimmed)) return trimmed;
    return trimmed.split(/\s+/)[0] ?? trimmed;
}
