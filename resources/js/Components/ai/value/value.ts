/**
 * Value dashboard helpers (boards B-Value, B-ValuePhone): month labels,
 * hour and payback wording, the lead-time delta and the payback bar scale.
 */
const MONTHS = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];
const MONTH_NAMES = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];

const monthIndex = (month: string) => Number(month.slice(5, 7)) - 1;

/** "ago" from "2026-08". */
export function monthShort(month: string): string {
    return MONTHS[monthIndex(month)] ?? month;
}

/** "Agosto 2026" from "2026-08". */
export function monthLong(month: string): string {
    const name = MONTH_NAMES[monthIndex(month)] ?? month;
    return `${name.charAt(0).toUpperCase()}${name.slice(1)} ${month.slice(0, 4)}`;
}

/** "Abril a septiembre de 2026" · "Octubre de 2025 a septiembre de 2026" from two `YYYY-MM-DD` dates. */
export function periodRangeText(from: string, to: string): string {
    const name = (date: string) => MONTH_NAMES[monthIndex(date)] ?? date;
    const capital = (text: string) => `${text.charAt(0).toUpperCase()}${text.slice(1)}`;
    const fromYear = from.slice(0, 4);
    const toYear = to.slice(0, 4);
    if (from.slice(0, 7) === to.slice(0, 7)) return `${capital(name(from))} de ${toYear}`;
    if (fromYear === toYear) return `${capital(name(from))} a ${name(to)} de ${toYear}`;
    return `${capital(name(from))} de ${fromYear} a ${name(to)} de ${toYear}`;
}

const number = (value: number) => value.toLocaleString('es-CO', { maximumFractionDigits: 1 });
const decimal = (value: number) => value.toLocaleString('es-CO', { minimumFractionDigits: 1, maximumFractionDigits: 1 });

/** "210 h", "12,5 h". */
export function hoursText(hours: number): string {
    return `${number(hours)} h`;
}

/** "8,1 meses", "1,0 mes". */
export function monthsText(months: number): string {
    return `${decimal(months)} ${months === 1 ? 'mes' : 'meses'}`;
}

export type ValuePeriod = '3m' | '6m' | '12m' | 'year';

export const VALUE_PERIODS: { value: ValuePeriod; label: string }[] = [
    { value: '3m', label: 'Últimos 3 meses' },
    { value: '6m', label: 'Últimos 6 meses' },
    { value: '12m', label: 'Últimos 12 meses' },
    { value: 'year', label: 'Este año' },
];

/** What the period is compared with: "el semestre anterior". */
export function periodPrevious(period: ValuePeriod): string {
    if (period === '3m') return 'el trimestre anterior';
    if (period === '6m') return 'el semestre anterior';
    return 'el año anterior';
}

/** StatTile delta for "De solicitud a producción": fewer days is good. */
export function leadTimeDelta(delta: number, period: ValuePeriod): { text: string; direction: 'up' | 'down'; good: boolean } | undefined {
    if (delta === 0) return undefined;
    const days = Math.abs(delta);
    const word = days === 1 ? 'día' : 'días';
    return {
        text: `${days} ${word} ${delta < 0 ? 'menos' : 'más'} que ${periodPrevious(period)}`,
        direction: delta < 0 ? 'down' : 'up',
        good: delta < 0,
    };
}

/** Scale maximum of the payback bars: the longest payback rounded up to a multiple of 5 (at least 5). */
export function paybackScaleMax(months: readonly number[]): number {
    const longest = Math.max(0, ...months);
    return Math.max(5, Math.ceil(longest / 5) * 5);
}
