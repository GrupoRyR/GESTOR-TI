/**
 * Pure helpers for the request detail (requester and IT views): timeline
 * icons and meta lines, and the "Tiempo hoy" sentence.
 */
import type { IconName } from '../../ui/Icon';
import type { TimelineItem } from '../../ui/Timeline';
import { formatShortDate } from '../../../lib/format';
import { REQUEST_STATUSES, type RequestStatusKey } from '../../../lib/status';
import type { TimelineEvent, TimelineKind } from '../../../types/ai';

const KIND_ICONS: Record<TimelineKind, IconName> = {
    status: 'info',
    question: 'question',
    answer: 'message',
    approval: 'check-circle',
    rejection: 'x-circle',
    comment: 'chat',
    notification: 'bell',
    estimate: 'clock',
    merge: 'pull-request',
    priority: 'arrow-up',
};

export type StatusIcons = Record<RequestStatusKey, IconName>;

/**
 * Status events reuse the status icon ("Pendiente de aprobación" → hourglass);
 * pass `STATUS_ICONS` from ui/StatusChip.
 */
export function timelineIcon(event: TimelineEvent, statusIcons: StatusIcons): IconName {
    if (event.kind === 'status') {
        const status = Object.values(REQUEST_STATUSES).find((item) => item.label === event.title);
        if (status) return statusIcons[status.key];
    }
    return KIND_ICONS[event.kind];
}

/** TimelineEvent → ui/Timeline item: "2 oct · TI la dejó lista para decidir". */
export function toTimelineItem(event: TimelineEvent, statusIcons: StatusIcons): TimelineItem {
    const second = event.detail ?? event.actor;
    return {
        id: event.id,
        icon: timelineIcon(event, statusIcons),
        family: event.family,
        title: event.title,
        quote: event.quote ?? undefined,
        meta: second ? `${formatShortDate(event.at)} · ${second}` : formatShortDate(event.at),
    };
}

const number = (value: number) => value.toLocaleString('es-CO', { maximumFractionDigits: 1 });

/** "3 h/sem × 4 personas = 12 h", or null while either is unknown. */
export function teamHoursLine(hours: number | null, people: number | null): string | null {
    if (hours === null || people === null) return null;
    return `${number(hours)} h/sem × ${people} ${people === 1 ? 'persona' : 'personas'} = ${number(hours * people)} h`;
}
