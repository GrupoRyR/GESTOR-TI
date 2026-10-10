/**
 * Task timer and hour formatting. The server stores `started_at`; the
 * client only derives the running time from it, so a reload never loses it.
 */

const pad = (value: number) => String(value).padStart(2, '0');

const plural = (value: number, one: string, many: string) => `${value} ${value === 1 ? one : many}`;

/** Whole seconds between `startedAt` and `now`, never negative. */
export function elapsedSeconds(startedAt: string, now: Date = new Date()): number {
    const start = new Date(startedAt).getTime();
    if (Number.isNaN(start)) throw new RangeError(`Invalid ISO 8601 date: ${startedAt}`);
    return Math.max(0, Math.floor((now.getTime() - start) / 1000));
}

/** "01:12:40". Hours keep counting past 24. */
export function formatElapsed(seconds: number): string {
    const total = Math.max(0, Math.floor(seconds));
    const hours = Math.floor(total / 3600);
    const minutes = Math.floor((total % 3600) / 60);
    return `${pad(hours)}:${pad(minutes)}:${pad(total % 60)}`;
}

/** "1 hora, 12 minutos y 40 segundos", for `aria-label`s. */
export function formatElapsedSpoken(seconds: number): string {
    const total = Math.max(0, Math.floor(seconds));
    const parts = [
        [Math.floor(total / 3600), 'hora', 'horas'],
        [Math.floor((total % 3600) / 60), 'minuto', 'minutos'],
        [total % 60, 'segundo', 'segundos'],
    ] as const;
    const words = parts.filter(([value]) => value > 0).map(([value, one, many]) => plural(value, one, many));
    if (words.length === 0) return '0 segundos';
    if (words.length === 1) return words[0]!;
    return `${words.slice(0, -1).join(', ')} y ${words[words.length - 1]}`;
}

/** Decimal hours → "6 h 20 min", "2 h", "30 min" (rounded to the minute). */
export function formatHours(hours: number): string {
    const minutesTotal = Math.max(0, Math.round(hours * 60));
    const h = Math.floor(minutesTotal / 60);
    const m = minutesTotal % 60;
    if (h === 0) return `${m} min`;
    return m === 0 ? `${h} h` : `${h} h ${m} min`;
}

const decimal = new Intl.NumberFormat('es-CO', { maximumFractionDigits: 1 });

/** "1,5 h", "92 h" — time-entry tables. */
export function formatDecimalHours(hours: number): string {
    return `${decimal.format(hours)} h`;
}
