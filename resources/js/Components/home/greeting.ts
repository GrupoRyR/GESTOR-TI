/**
 * Home header copy in Bogotá wall time: the greeting by time of day and the
 * long date ("Miércoles 7 de octubre"). Pass `now` for deterministic output.
 */
const TIME_ZONE = 'America/Bogota';

const hourFormat = new Intl.DateTimeFormat('en-US', { timeZone: TIME_ZONE, hour: '2-digit', hourCycle: 'h23' });
const longFormat = new Intl.DateTimeFormat('es-CO', { timeZone: TIME_ZONE, weekday: 'long', day: 'numeric', month: 'long' });

export function greeting(now: Date = new Date()): string {
    const hour = Number(hourFormat.formatToParts(now).find((part) => part.type === 'hour')?.value ?? 0);
    if (hour >= 5 && hour < 12) return 'Buenos días';
    if (hour >= 12 && hour < 19) return 'Buenas tardes';
    return 'Buenas noches';
}

export function longDate(now: Date = new Date()): string {
    const parts = longFormat.formatToParts(now);
    const value = (type: Intl.DateTimeFormatPartTypes) => parts.find((part) => part.type === type)?.value ?? '';
    const weekday = value('weekday');
    return `${weekday.charAt(0).toUpperCase()}${weekday.slice(1)} ${value('day')} de ${value('month')}`;
}
