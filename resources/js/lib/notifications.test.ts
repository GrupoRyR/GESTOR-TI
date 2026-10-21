import { describe, expect, it } from 'vitest';
import type { NotificationItem } from '../types/pages/notifications';
import {
    formatNotificationTime,
    groupNotificationsByDay,
    isSameOrigin,
    notificationDetail,
    resolveNotificationKind,
    visibleNotifications,
} from './notifications';

/** Intl and formatTime use no-break spaces; compare with plain ones. */
const norm = (value: string) => value.replace(/ /g, ' ');

// Wednesday 7 Oct 2026, 9:40 a. m. in Bogotá (same instant as mocks' NOW_ISO).
const NOW = new Date('2026-10-07T09:40:00-05:00');

function item(overrides: Partial<NotificationItem> & { at?: string; event?: string } = {}): NotificationItem {
    const { at = '2026-10-07T09:00:00-05:00', event = 'ai.approval.requested', ...rest } = overrides;
    return {
        id: rest.id ?? `n-${at}`,
        type: 'App\\Notifications\\AiApprovalRequested',
        data: { event, title: 'GIA-153 espera tu aprobación', body: '«Flujo de caja»', url: 'https://ti.segurex.co/ai/requests/GIA-153', company_id: 1 },
        read_at: null,
        created_at: at,
        ...rest,
    };
}

function alert(level: string, overrides: Partial<NotificationItem> = {}): NotificationItem {
    return {
        id: `alert-${level}`,
        type: 'App\\Notifications\\AlertRaised',
        data: { alert_id: 9, level, source: 'backups', title: 'La copia nocturna falló', body: 'Revisa el disco.', company_id: 1 },
        read_at: null,
        created_at: '2026-10-07T06:05:00-05:00',
        ...overrides,
    };
}

describe('resolveNotificationKind', () => {
    it('maps Gestor IA events to the Gestor IA module with a specific icon', () => {
        expect(resolveNotificationKind(item({ event: 'ai.approval.requested' }))).toEqual({
            event: 'ai.approval.requested',
            module: 'Gestor IA',
            icon: 'check-circle',
        });
        expect(resolveNotificationKind(item({ event: 'ai.requirement.requested' })).icon).toBe('clipboard-check');
        expect(resolveNotificationKind(item({ event: 'ai.release.published' })).icon).toBe('rocket');
        expect(resolveNotificationKind(item({ event: 'ai.project.budget' })).icon).toBe('trend');
    });

    it('maps the self-approved proposal notice to Gestor IA with the project icon', () => {
        const proposed = item({ event: 'ai.project.proposed' });
        proposed.data = { ...proposed.data, title: 'P-08 · Jhon Castro propuso y aprobó un proyecto' };
        expect(resolveNotificationKind(proposed)).toEqual({ event: 'ai.project.proposed', module: 'Gestor IA', icon: 'code' });
    });

    it('maps vault and Asistente events to their modules', () => {
        expect(resolveNotificationKind(item({ event: 'vault.accessed' }))).toMatchObject({ module: 'Bóveda', icon: 'lock' });
        expect(resolveNotificationKind(item({ event: 'vault.reminder' }))).toMatchObject({ module: 'Bóveda', icon: 'lock' });
        expect(resolveNotificationKind(item({ event: 'assistant.knowledge.review' }))).toMatchObject({ module: 'Asistente TI', icon: 'book' });
        expect(resolveNotificationKind(item({ event: 'assistant.credential.revealed' }))).toMatchObject({ module: 'Asistente TI', icon: 'key' });
    });

    it('treats alert events sent by GestorNotification (e.g. product incidents) as alerts', () => {
        expect(resolveNotificationKind(item({ event: 'alert.critical' }))).toEqual({ event: 'alert.critical', module: 'Alerta crítica', icon: 'alert-triangle' });
    });

    it('derives the event of AlertRaised payloads from their level', () => {
        expect(resolveNotificationKind(alert('critical'))).toEqual({ event: 'alert.critical', module: 'Alerta crítica', icon: 'alert-triangle' });
        expect(resolveNotificationKind(alert('warning'))).toEqual({ event: 'alert.warning', module: 'Alerta de atención', icon: 'alert-triangle' });
        expect(resolveNotificationKind(alert('info'))).toEqual({ event: 'alert.info', module: 'Alerta informativa', icon: 'info' });
    });

    it('falls back to a generic alert for unknown levels', () => {
        expect(resolveNotificationKind(alert('debug'))).toEqual({ event: 'alert.debug', module: 'Alerta', icon: 'alert-triangle' });
    });

    it('falls back by prefix for unknown events, then to the bell', () => {
        expect(resolveNotificationKind(item({ event: 'ai.something.new' }))).toEqual({ event: 'ai.something.new', module: 'Gestor IA', icon: 'ai' });
        expect(resolveNotificationKind(item({ event: 'assistant.other' }))).toMatchObject({ module: 'Asistente TI', icon: 'assistant' });
        expect(resolveNotificationKind(item({ event: 'tickets.created' }))).toEqual({ event: 'tickets.created', module: 'Gestor TI', icon: 'bell' });
    });
});

describe('groupNotificationsByDay', () => {
    it('groups into Hoy, Ayer, Esta semana and older dates, keeping order', () => {
        const items = [
            item({ id: 'a', at: '2026-10-07T09:28:00-05:00' }),
            item({ id: 'b', at: '2026-10-07T00:10:00-05:00' }),
            item({ id: 'c', at: '2026-10-06T23:50:00-05:00' }),
            item({ id: 'd', at: '2026-10-05T07:00:00-05:00' }),
            item({ id: 'e', at: '2026-10-04T18:00:00-05:00' }),
            item({ id: 'f', at: '2026-10-04T08:00:00-05:00' }),
            item({ id: 'g', at: '2026-09-28T08:00:00-05:00' }),
        ];

        const groups = groupNotificationsByDay(items, NOW);

        expect(groups.map((group) => group.label)).toEqual(['Hoy', 'Ayer', 'Esta semana', '4 oct 2026', '28 sept 2026']);
        expect(groups.map((group) => group.items.map((entry) => entry.id))).toEqual([['a', 'b'], ['c'], ['d'], ['e', 'f'], ['g']]);
    });

    it('uses the Bogotá calendar day, not UTC', () => {
        // 23:50 in Bogotá is already the next day in UTC.
        const groups = groupNotificationsByDay([item({ at: '2026-10-06T23:50:00-05:00' })], NOW);
        expect(groups[0]?.label).toBe('Ayer');
    });

    it('starts the week on Monday', () => {
        const monday = new Date('2026-10-05T10:00:00-05:00');
        const groups = groupNotificationsByDay([item({ at: '2026-10-04T10:00:00-05:00' }), item({ at: '2026-10-03T10:00:00-05:00' })], monday);
        expect(groups.map((group) => group.label)).toEqual(['Ayer', '3 oct 2026']);
    });

    it('puts items from the future (clock skew) in Hoy', () => {
        expect(groupNotificationsByDay([item({ at: '2026-10-07T09:45:00-05:00' })], NOW)[0]?.label).toBe('Hoy');
    });

    it('returns no groups for no items', () => {
        expect(groupNotificationsByDay([], NOW)).toEqual([]);
    });
});

describe('formatNotificationTime', () => {
    it('is relative within the last hour', () => {
        expect(norm(formatNotificationTime('2026-10-07T09:28:00-05:00', NOW))).toBe('hace 12 min');
        expect(norm(formatNotificationTime('2026-10-07T09:39:40-05:00', NOW))).toBe('hace un momento');
        expect(norm(formatNotificationTime('2026-10-07T09:45:00-05:00', NOW))).toBe('hace un momento');
    });

    it('shows the clock time today and yesterday', () => {
        expect(norm(formatNotificationTime('2026-10-07T08:15:00-05:00', NOW))).toBe('8:15 a. m.');
        expect(norm(formatNotificationTime('2026-10-06T16:30:00-05:00', NOW))).toBe('4:30 p. m.');
    });

    it('adds the weekday earlier this week', () => {
        expect(norm(formatNotificationTime('2026-10-05T07:00:00-05:00', NOW))).toBe('lunes, 7:00 a. m.');
    });

    it('shows only the time for older days (the group shows the date)', () => {
        expect(norm(formatNotificationTime('2026-09-28T08:00:00-05:00', NOW))).toBe('8:00 a. m.');
    });
});

describe('notificationDetail', () => {
    it('joins the body lines into one line', () => {
        const entry = item();
        entry.data = { ...entry.data, body: '«Flujo de caja»\n\nResumen:  dos líneas' };
        expect(notificationDetail(entry)).toBe('«Flujo de caja» Resumen: dos líneas');
    });

    it('returns an empty string for an empty body', () => {
        const entry = item();
        entry.data = { ...entry.data, body: '' };
        expect(notificationDetail(entry)).toBe('');
    });
});

describe('visibleNotifications', () => {
    const read = item({ id: 'read', read_at: '2026-10-07T09:30:00-05:00' });
    const unread = item({ id: 'unread' });

    it('keeps everything for "Todas"', () => {
        expect(visibleNotifications([read, unread], false)).toEqual([read, unread]);
    });

    it('keeps only unread items for "Sin leer"', () => {
        expect(visibleNotifications([read, unread], true)).toEqual([unread]);
    });
});

describe('isSameOrigin', () => {
    it('accepts relative and same-origin urls', () => {
        expect(isSameOrigin('/ai/requests/GIA-153', 'https://ti.segurex.co')).toBe(true);
        expect(isSameOrigin('https://ti.segurex.co/ai/requests/GIA-153', 'https://ti.segurex.co')).toBe(true);
    });

    it('rejects other origins and invalid urls', () => {
        expect(isSameOrigin('https://github.com/GrupoRyR/x/releases', 'https://ti.segurex.co')).toBe(false);
        expect(isSameOrigin('http://[bad', 'https://ti.segurex.co')).toBe(false);
    });
});
