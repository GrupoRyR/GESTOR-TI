/**
 * Chat display helpers (boards C-ChatPhone, C-ChatDesktop): day groups,
 * time labels and the tiny answer formatting the assistant may use
 * (paragraphs and `**bold**`). No HTML is ever interpreted.
 */
import { bogotaDateKey, formatLongDate, formatShortDate, formatTime } from './format';

const DAY = 86_400_000;

function dayNumber(key: string): number {
    const [year, month, day] = key.split('-').map(Number);
    return Date.UTC(year ?? 0, (month ?? 1) - 1, day ?? 1) / DAY;
}

/** Calendar days between the Bogotá dates of `iso` and `now` (0 = today). */
function daysAgo(iso: string, now: Date): number {
    return dayNumber(bogotaDateKey(now.toISOString())) - dayNumber(bogotaDateKey(iso));
}

const capitalize = (text: string) => text.charAt(0).toUpperCase() + text.slice(1);

/** "Hoy, miércoles 7 de octubre", "Ayer, martes 6 de octubre", "Lunes 5 de octubre". */
export function dayLabel(iso: string, now: Date = new Date()): string {
    const days = daysAgo(iso, now);
    const long = formatLongDate(iso);
    if (days === 0) return `Hoy, ${long}`;
    if (days === 1) return `Ayer, ${long}`;
    return capitalize(long);
}

export interface DayGroup<T> {
    /** Bogotá `YYYY-MM-DD`. */
    key: string;
    label: string;
    items: T[];
}

/** Consecutive messages of the same Bogotá day, in the given order. */
export function groupByDay<T extends { at: string }>(items: T[], now: Date = new Date()): DayGroup<T>[] {
    const groups: DayGroup<T>[] = [];
    for (const item of items) {
        const key = bogotaDateKey(item.at);
        const last = groups[groups.length - 1];
        if (last && last.key === key) {
            last.items.push(item);
        } else {
            groups.push({ key, label: dayLabel(item.at, now), items: [item] });
        }
    }
    return groups;
}

/** Bubble time: "9:40 a. m." (the day comes from the group divider). */
export function messageTime(iso: string): string {
    return formatTime(iso);
}

const weekday = new Intl.DateTimeFormat('es-CO', { timeZone: 'America/Bogota', weekday: 'short' });

/** Conversation list: "Hoy 9:40 a. m.", "Ayer", "Hace 2 días", "Mié 30 sep". */
export function conversationWhen(iso: string, now: Date = new Date()): string {
    const days = daysAgo(iso, now);
    if (days <= 0) return `Hoy ${formatTime(iso)}`;
    if (days === 1) return 'Ayer';
    if (days < 7) return `Hace ${days} días`;
    return `${capitalize(weekday.format(new Date(iso)).replace(/\.$/, ''))} ${formatShortDate(iso)}`;
}

/** Paragraphs of an answer, split on blank lines. */
export function paragraphs(text: string): string[] {
    return text
        .split(/\n\s*\n/)
        .map((part) => part.trim())
        .filter((part) => part.length > 0);
}

export interface InlineSegment {
    text: string;
    bold: boolean;
}

/** `**bold**` runs as segments; everything else stays literal text. */
export function inlineSegments(text: string): InlineSegment[] {
    const segments: InlineSegment[] = [];
    const pattern = /\*\*(.+?)\*\*/g;
    let cursor = 0;
    for (const match of text.matchAll(pattern)) {
        const start = match.index ?? 0;
        if (start > cursor) segments.push({ text: text.slice(cursor, start), bold: false });
        segments.push({ text: match[1] ?? '', bold: true });
        cursor = start + match[0].length;
    }
    if (cursor < text.length) segments.push({ text: text.slice(cursor), bold: false });
    return segments;
}
