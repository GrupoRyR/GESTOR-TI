/**
 * Notificaciones (`Pages/Notifications/Index.tsx`, Inertia component `Notifications/Index`).
 *
 * Ziggy routes used:
 * - `notifications.index`     GET   query `page`; `unread=1` only when the
 *                                   server sends `filters` (see below)
 * - `notifications.read`      POST  body `{ id }`   per-item "Marcar como leída",
 *                                   and before opening an item with `url`
 * - `notifications.read_all`  POST  —               "Marcar todas como leídas"
 * - `profile.edit`            GET   —               "Cambiar mis avisos"
 *
 * Permissions: none beyond `auth`; every user sees only their own
 * notifications. The unread total is the shared prop `notifications_unread`.
 *
 * Opening an item with `url`: if unread, POST `notifications.read` first and
 * visit the url on success (Inertia visit for same-origin urls, a full page
 * load otherwise). Read items are plain links. Items without `url` are not
 * links; they only have the "Marcar como leída" button.
 *
 * "Todas / Sin leer": while the server sends no `filters`, the toggle filters
 * the current page on the client (it cannot see unread items on other pages).
 * Requested from the app: an `unread` query param and `filters.unread`; when
 * present the toggle reloads `notifications.index` with `unread=1`.
 */
import type { Paginated } from '@/types/shared';

/** Payload of every `App\Notifications\GestorNotification` (`toArray`). */
export interface GestorNotificationData {
    /** Event key from `config/notifications.php`, e.g. `ai.approval.requested`. */
    event: string;
    title: string;
    body: string;
    url: string | null;
    company_id: number | null;
}

export type AlertLevel = 'critical' | 'warning' | 'info';

/** Payload of `App\Notifications\AlertRaised`: no `event` and no `url`. */
export interface AlertNotificationData {
    alert_id: number;
    level: AlertLevel | string;
    source: string;
    title: string;
    body: string;
    company_id: number | null;
}

export type NotificationData = GestorNotificationData | AlertNotificationData;

export interface NotificationItem {
    /** UUID of the `notifications` row. */
    id: string;
    /** Notification class, e.g. `App\Notifications\AiApprovalRequested`. */
    type: string;
    data: NotificationData;
    /** ISO 8601; `null` = unread. */
    read_at: string | null;
    /** ISO 8601. */
    created_at: string;
}

export interface NotificationFilters {
    unread: boolean;
}

export interface NotificationsIndexProps {
    /** 20 per page, newest first. */
    notifications: Paginated<NotificationItem>;
    /**
     * Requested from the app (server-side unread filter). Absent today: the
     * toggle filters the current page on the client.
     */
    filters?: NotificationFilters;
    /**
     * Requested from the app (server time, ISO 8601) so "Hoy / Ayer" match
     * the server. Absent, the device clock is used. Mocks pass `NOW_ISO`.
     */
    now?: string;
}
