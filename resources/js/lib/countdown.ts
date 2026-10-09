/**
 * Short countdowns: a revealed vault value (30 s), the clipboard wipe and the
 * vault auto-lock (10 min). The server decides the deadline; this only shows it.
 */

/** Whole seconds until `deadline`, rounded up and never negative. */
export function secondsLeft(deadline: string, now: Date = new Date()): number {
    const end = new Date(deadline).getTime();
    if (Number.isNaN(end)) throw new RangeError(`Invalid ISO 8601 date: ${deadline}`);
    return Math.max(0, Math.ceil((end - now.getTime()) / 1000));
}

/** "9:12" from one minute up, "24 seg" below. */
export function formatCountdown(seconds: number): string {
    const total = Math.max(0, Math.floor(seconds));
    if (total < 60) return `${total} seg`;
    return `${Math.floor(total / 60)}:${String(total % 60).padStart(2, '0')}`;
}

/** "9 minutos y 12 segundos", for screen readers. */
export function countdownSpoken(seconds: number): string {
    const total = Math.max(0, Math.floor(seconds));
    const minutes = Math.floor(total / 60);
    const rest = total % 60;
    const m = minutes > 0 ? `${minutes} ${minutes === 1 ? 'minuto' : 'minutos'}` : '';
    const s = rest > 0 || minutes === 0 ? `${rest} ${rest === 1 ? 'segundo' : 'segundos'}` : '';
    return [m, s].filter(Boolean).join(' y ');
}

/** Share of the time left (0..1), for the ring. */
export function countdownFraction(left: number, total: number): number {
    if (total <= 0) return 0;
    return Math.min(1, Math.max(0, left / total));
}
