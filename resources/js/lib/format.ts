/**
 * Display formatting for Colombia: locale es-CO, wall time in America/Bogota.
 * Inputs are ISO 8601 strings as sent by Laravel. Dates and times are built
 * from Intl parts so the output is stable across browser ICU versions.
 */

const LOCALE = 'es-CO';
const TIME_ZONE = 'America/Bogota';
const NBSP = ' ';

const MINUTE = 60_000;
const HOUR = 60 * MINUTE;
const DAY = 24 * HOUR;

const dateParts = new Intl.DateTimeFormat(LOCALE, {
    timeZone: TIME_ZONE,
    day: 'numeric',
    month: 'short',
    year: 'numeric',
});

const clockParts = new Intl.DateTimeFormat('en-US', {
    timeZone: TIME_ZONE,
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
    hourCycle: 'h23',
});

const currency = new Intl.NumberFormat(LOCALE, {
    style: 'currency',
    currency: 'COP',
    minimumFractionDigits: 0,
    maximumFractionDigits: 0,
});

function toDate(iso: string): Date {
    const date = new Date(iso);
    if (Number.isNaN(date.getTime())) {
        throw new RangeError(`Invalid ISO 8601 date: ${iso}`);
    }
    return date;
}

function part(parts: Intl.DateTimeFormatPart[], type: Intl.DateTimeFormatPartTypes): string {
    return parts.find((p) => p.type === type)?.value ?? '';
}

/** Bogotá wall-clock fields for an instant. */
function bogota(date: Date) {
    const parts = clockParts.formatToParts(date);
    return {
        year: Number(part(parts, 'year')),
        month: Number(part(parts, 'month')),
        day: Number(part(parts, 'day')),
        hour: Number(part(parts, 'hour')),
        minute: Number(part(parts, 'minute')),
    };
}

/** Days since epoch of the Bogotá calendar date (for "hoy"/"ayer"). */
function bogotaDayNumber(date: Date): number {
    const { year, month, day } = bogota(date);
    return Math.round(Date.UTC(year, month - 1, day) / DAY);
}

/** "7 oct 2026" */
export function formatDate(iso: string): string {
    const parts = dateParts.formatToParts(toDate(iso));
    const month = part(parts, 'month').replace(/\.$/, '');
    return [part(parts, 'day'), month, part(parts, 'year')].join(' ');
}

/** "9:40 a. m." */
export function formatTime(iso: string): string {
    const { hour, minute } = bogota(toDate(iso));
    const period = hour < 12 ? `a.${NBSP}m.` : `p.${NBSP}m.`;
    const hour12 = hour % 12 === 0 ? 12 : hour % 12;
    return `${hour12}:${String(minute).padStart(2, '0')}${NBSP}${period}`;
}

/** "7 oct 2026, 3:05 p. m." */
export function formatDateTime(iso: string): string {
    return `${formatDate(iso)}, ${formatTime(iso)}`;
}

/**
 * Human relative time: "hace un momento", "hace 12 min", "hoy 9:40 a. m.",
 * "ayer 8:00 p. m.", "hace 2 días", "en 30 min"; older than a week falls
 * back to the date. Pass `now` for deterministic rendering and tests.
 */
export function formatRelative(iso: string, now: Date = new Date()): string {
    const date = toDate(iso);
    const diff = now.getTime() - date.getTime();
    const abs = Math.abs(diff);

    if (abs < MINUTE) {
        return 'hace un momento';
    }

    if (diff < 0) {
        if (abs < HOUR) return `en ${Math.round(abs / MINUTE)} min`;
        if (abs < DAY) return `en ${Math.round(abs / HOUR)} h`;
        return formatDate(iso);
    }

    if (abs < HOUR) {
        return `hace ${Math.floor(abs / MINUTE)} min`;
    }

    const days = bogotaDayNumber(now) - bogotaDayNumber(date);
    if (days === 0) return `hoy ${formatTime(iso)}`;
    if (days === 1) return `ayer ${formatTime(iso)}`;
    if (days < 7) return `hace ${days} días`;
    return formatDate(iso);
}

/** "$ 14.200.000" — Colombian pesos, no decimals. */
export function formatCOP(amount: number): string {
    return currency.format(Math.round(amount));
}
