/**
 * Pure helpers of the notifications page (`Notifications/Index`): module and
 * icon per event, grouping by Bogotá calendar day, row times and filters.
 */
import type { IconName } from '../Components/ui/Icon';
import type { NotificationItem } from '../types/pages/notifications';
import { bogotaDateKey, formatDate, formatTime } from './format';

export interface NotificationKind {
    /** Event key; for `AlertRaised` payloads, `alert.{level}`. */
    event: string | null;
    /** Visible module label, e.g. "Gestor IA", "Bóveda", "Alerta crítica". */
    module: string;
    icon: IconName;
}

export interface NotificationGroup {
    key: string;
    /** "Hoy", "Ayer", "Esta semana" or a date (`formatDate`), e.g. "28 sept 2026". */
    label: string;
    items: NotificationItem[];
}

const DAY = 86_400_000;
const HOUR = 3_600_000;
const MINUTE = 60_000;

const WEEKDAYS = ['lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado', 'domingo'];

const ALERT_LEVELS: Record<string, { module: string; icon: IconName }> = {
    critical: { module: 'Alerta crítica', icon: 'alert-triangle' },
    warning: { module: 'Alerta de atención', icon: 'alert-triangle' },
    info: { module: 'Alerta informativa', icon: 'info' },
};

/** Icons per known event (catalog: config/notifications.php). */
const EVENT_ICONS: Record<string, IconName> = {
    'ai.request.created': 'ai',
    'ai.request.status_changed': 'history',
    'ai.rating.requested': 'rocket',
    'ai.rating.hours': 'clock',
    'ai.approval.requested': 'check-circle',
    'ai.requirement.requested': 'clipboard-check',
    'ai.requirement.answered': 'clipboard-check',
    'ai.task.unblocked': 'subtasks',
    'ai.project.budget': 'trend',
    'ai.project.reapproval': 'check-circle',
    'ai.release.published': 'rocket',
    'ai.hours.reminder': 'clock',
    'vault.accessed': 'lock',
    'vault.reminder': 'lock',
    'assistant.knowledge.review': 'book',
    'assistant.credential.revealed': 'key',
};

/** Module label and fallback icon per event prefix. */
const PREFIXES: { prefix: string; module: string; icon: IconName }[] = [
    { prefix: 'ai.', module: 'Gestor IA', icon: 'ai' },
    { prefix: 'vault.', module: 'Bóveda', icon: 'lock' },
    { prefix: 'assistant.', module: 'Asistente TI', icon: 'assistant' },
];

function eventOf(item: NotificationItem): string | null {
    const data = item.data;
    if ('event' in data && typeof data.event === 'string' && data.event !== '') return data.event;
    if ('level' in data && typeof data.level === 'string' && data.level !== '') return `alert.${data.level}`;
    return null;
}

/** Module and icon of a notification, from its event (or alert level). */
export function resolveNotificationKind(item: NotificationItem): NotificationKind {
    const event = eventOf(item);

    if (event === null) return { event, module: 'Gestor TI', icon: 'bell' };

    if (event.startsWith('alert.')) {
        const level = ALERT_LEVELS[event.slice('alert.'.length)];
        return { event, module: level?.module ?? 'Alerta', icon: level?.icon ?? 'alert-triangle' };
    }

    const owner = PREFIXES.find((entry) => event.startsWith(entry.prefix));
    if (!owner) return { event, module: 'Gestor TI', icon: 'bell' };

    return { event, module: owner.module, icon: EVENT_ICONS[event] ?? owner.icon };
}

/** Days since epoch of the Bogotá calendar date of an instant. */
function dayNumber(iso: string): number {
    const [year = 0, month = 1, day = 1] = bogotaDateKey(iso).split('-').map(Number);
    return Math.round(Date.UTC(year, month - 1, day) / DAY);
}

/** 0 = Monday … 6 = Sunday (1970-01-01, day 0, was a Thursday). */
function weekdayIndex(day: number): number {
    return (((day + 3) % 7) + 7) % 7;
}

type DayBucket = 'today' | 'yesterday' | 'week' | 'older';

function bucket(iso: string, now: Date): DayBucket {
    const today = dayNumber(now.toISOString());
    const day = dayNumber(iso);
    const diff = today - day;
    if (diff <= 0) return 'today';
    if (diff === 1) return 'yesterday';
    const weekStart = today - weekdayIndex(today);
    return day >= weekStart ? 'week' : 'older';
}

/**
 * Consecutive groups in list order (the list comes newest first): "Hoy",
 * "Ayer", "Esta semana" (since Monday) and one group per older date.
 */
export function groupNotificationsByDay(items: NotificationItem[], now: Date): NotificationGroup[] {
    const groups: NotificationGroup[] = [];

    for (const item of items) {
        const kind = bucket(item.created_at, now);
        const key = kind === 'older' ? bogotaDateKey(item.created_at) : kind;
        const last = groups[groups.length - 1];

        if (last && last.key === key) {
            last.items.push(item);
            continue;
        }

        const label = kind === 'today' ? 'Hoy' : kind === 'yesterday' ? 'Ayer' : kind === 'week' ? 'Esta semana' : formatDate(item.created_at);
        groups.push({ key, label, items: [item] });
    }

    return groups;
}

/**
 * Time on a row: "hace 12 min" within the hour, the clock time today and
 * yesterday, "lunes, 7:00 a. m." this week, the clock time for older days
 * (their group already shows the date).
 */
export function formatNotificationTime(iso: string, now: Date): string {
    const elapsed = now.getTime() - new Date(iso).getTime();

    if (elapsed < MINUTE) return 'hace un momento';
    if (elapsed < HOUR) return `hace ${Math.floor(elapsed / MINUTE)} min`;

    if (bucket(iso, now) === 'week') return `${WEEKDAYS[weekdayIndex(dayNumber(iso))]}, ${formatTime(iso)}`;

    return formatTime(iso);
}

/** The body as one line (the row truncates it). */
export function notificationDetail(item: NotificationItem): string {
    return item.data.body.replace(/\s+/g, ' ').trim();
}

/** "Todas" keeps every item; "Sin leer" only the unread ones. */
export function visibleNotifications(items: NotificationItem[], unreadOnly: boolean): NotificationItem[] {
    return unreadOnly ? items.filter((item) => item.read_at === null) : items;
}

/** Whether `url` stays in the app (Inertia visit) or needs a full page load. */
export function isSameOrigin(url: string, origin: string): boolean {
    try {
        return new URL(url, origin).origin === new URL(origin).origin;
    } catch {
        return false;
    }
}
