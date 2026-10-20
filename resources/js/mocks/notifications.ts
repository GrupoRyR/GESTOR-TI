/**
 * Mocks for Notifications/Index (A-Notifications*): unread and read items in
 * Hoy / Ayer / Esta semana, an alert without url, everything read, empty,
 * a second page (older dates) and the requested server-side unread filter.
 * All instants are at or before NOW_ISO (Wednesday 7 Oct 2026, 9:40 a. m.).
 */
import { route } from '@/lib/route';
import type { NotificationItem, NotificationsIndexProps } from '@/types/pages/notifications';
import type { PageProps, Paginated } from '@/types/shared';
import { NOW_ISO } from './ai-fixtures';
import { SEGUREX, sharedFor, type MockVariant } from './shared';

type NotificationsPage = PageProps<NotificationsIndexProps>;

const PER_PAGE = 20;

let sequence = 0;

/** A `GestorNotification` payload (`{event, title, body, url, company_id}`). */
function gestor(
    at: string,
    cls: string,
    event: string,
    title: string,
    body: string,
    url: string | null,
    readAt: string | null = null,
): NotificationItem {
    sequence += 1;
    return {
        id: `9f3c1a2e-0000-4000-8000-${String(sequence).padStart(12, '0')}`,
        type: `App\\Notifications\\${cls}`,
        data: { event, title, body, url, company_id: SEGUREX.id },
        read_at: readAt,
        created_at: at,
    };
}

/** An `AlertRaised` payload: no event, no url. */
function alertRaised(at: string, level: string, title: string, body: string, readAt: string | null = null): NotificationItem {
    sequence += 1;
    return {
        id: `9f3c1a2e-0000-4000-8000-${String(sequence).padStart(12, '0')}`,
        type: 'App\\Notifications\\AlertRaised',
        data: { alert_id: 40 + sequence, level, source: '[ORIGEN]', title, body, company_id: SEGUREX.id },
        read_at: readAt,
        created_at: at,
    };
}

const READ_AT = '2026-10-07T09:35:00-05:00';

/** Jhon's first page: 5 unread (one is an alert without url). */
const PAGE_ONE: NotificationItem[] = [
    gestor(
        '2026-10-07T09:28:00-05:00',
        'AiApprovalRequested',
        'ai.approval.requested',
        'GIA-153 espera tu aprobación',
        '«Flujo de caja semanal desde SAP B1»',
        route('ai.requests.show', { request: 'GIA-153' }),
    ),
    gestor(
        '2026-10-07T09:02:00-05:00',
        'AiRequestCreated',
        'ai.request.created',
        'GIA-153 · Nueva solicitud',
        'Llegó una solicitud nueva de Cartera: «Flujo de caja semanal desde SAP B1».',
        route('ai.requests.show', { request: 'GIA-153' }),
    ),
    gestor(
        '2026-10-07T08:15:00-05:00',
        'VaultAccessed',
        'vault.accessed',
        'Bóveda: Leonardo Herrera vio [CREDENCIAL]',
        'Desde [ORIGEN], [HORA]. Si no fuiste tú, cambia esa credencial y revisa el registro de accesos.',
        route('ai.vault.index'),
    ),
    gestor(
        '2026-10-07T07:48:00-05:00',
        'AiRequirementAnswered',
        'ai.requirement.answered',
        'P-07 · respondieron «[REQUERIMIENTO]»',
        '[RESPONSABLE CONTABILIDAD] ya respondió el requerimiento.',
        route('ai.projects.requirements', { project: 'P-07' }),
        READ_AT,
    ),
    gestor(
        '2026-10-06T16:30:00-05:00',
        'AiTaskUnblocked',
        'ai.task.unblocked',
        'P-07 · T4 ya no está bloqueada',
        '[RESPONSABLE CONTABILIDAD] respondió «[REQUERIMIENTO]». Puedes seguir con «[TAREA]».',
        route('ai.projects.board', { project: 'P-07' }),
    ),
    gestor(
        '2026-10-06T11:20:00-05:00',
        'AiRequestStatusChanged',
        'ai.request.status_changed',
        'GIA-151 · En desarrollo',
        'Tu solicitud «Visualizador de cartera: exportar a Excel la vista filtrada» ahora está: En desarrollo.',
        route('ai.requests.show', { request: 'GIA-151' }),
        '2026-10-06T12:02:00-05:00',
    ),
    gestor(
        '2026-10-05T07:00:00-05:00',
        'VaultReminder',
        'vault.reminder',
        'Bóveda: [CREDENCIAL] vence en 15 días',
        'Vence el [FECHA]. Renuévala antes para que nada deje de funcionar.',
        route('ai.vault.index'),
        '2026-10-05T08:10:00-05:00',
    ),
    alertRaised('2026-10-05T06:05:00-05:00', 'critical', '[TÍTULO DE LA ALERTA]', '[DETALLE DE LA ALERTA]'),
];

const UNREAD_PAGE_ONE = PAGE_ONE.filter((item) => item.read_at === null).length;

/** Older items (page 2 of 2), all read. */
const PAGE_TWO: NotificationItem[] = [
    gestor(
        '2026-10-02T15:10:00-05:00',
        'AiRatingRequested',
        'ai.rating.requested',
        'GIA-150 · Ya está en producción',
        'Tu solicitud «Comparar precios de proveedores en el Portafolio» ya está en producción. Califica de 1 a 5 si resolvió tu necesidad y qué tan fácil es de usar.',
        route('ai.requests.show', { request: 'GIA-150' }),
        '2026-10-02T16:00:00-05:00',
    ),
    gestor(
        '2026-10-01T10:00:00-05:00',
        'AiBudgetAlert',
        'ai.project.budget',
        'P-07 llegó al 80 % de lo aprobado',
        '«[NOMBRE DEL PROYECTO]». Horas: 64 h de 80 h (80 %).',
        route('ai.projects.show', { project: 'P-07' }),
        '2026-10-01T10:30:00-05:00',
    ),
    gestor(
        '2026-09-28T08:00:00-05:00',
        'KnowledgeReviewReminder',
        'assistant.knowledge.review',
        'Base de conocimiento: 3 fichas con la revisión vencida',
        'Pasaron 6 meses sin revisarlas: [FICHA 1]; [FICHA 2]; [FICHA 3]. Ábrelas, confirma que siguen vigentes y márcalas como revisadas.',
        route('assistant.knowledge.index'),
        '2026-09-28T09:00:00-05:00',
    ),
    gestor(
        '2026-09-25T17:45:00-05:00',
        'ProductIncidentRegistered',
        'alert.critical',
        'Incidente en [PRODUCTO]: [INCIDENTE]',
        'Impacto alto. Empezó el [FECHA]. Lo registró [RESPONSABLE].',
        route('ai.products.show', { product: '[producto]' }),
        '2026-09-25T18:00:00-05:00',
    ),
];

/** Andrés (requester): his requests and a requirement from TI. */
const REQUESTER_ITEMS: NotificationItem[] = [
    gestor(
        '2026-10-07T08:50:00-05:00',
        'AiRequirementRequested',
        'ai.requirement.requested',
        'TI te pide: [REQUERIMIENTO]',
        'Jhon Castro (TI) lo necesita para el proyecto «[NOMBRE DEL PROYECTO]». Respóndelo antes del [FECHA].',
        route('ai.projects.requirements', { project: 'P-07' }),
    ),
    gestor(
        '2026-10-05T14:20:00-05:00',
        'AiRequestStatusChanged',
        'ai.request.status_changed',
        'GIA-152 · En evaluación',
        'Tu solicitud «Portafolio: subir fotos de producto en lote» ahora está: En evaluación.',
        route('ai.requests.show', { request: 'GIA-152' }),
        '2026-10-05T15:00:00-05:00',
    ),
];

function markAllRead(items: NotificationItem[]): NotificationItem[] {
    return items.map((item) => ({ ...item, read_at: item.read_at ?? READ_AT }));
}

/** Laravel-style paginator (`links` with "previous" first and "next" last). */
function paginate(rows: NotificationItem[], total: number, currentPage = 1): Paginated<NotificationItem> {
    const lastPage = Math.max(1, Math.ceil(total / PER_PAGE));
    const url = (page: number) => `${route('notifications.index')}?page=${page}`;
    return {
        data: rows,
        links: [
            { url: currentPage > 1 ? url(currentPage - 1) : null, label: '&laquo; Anterior', active: false },
            ...Array.from({ length: lastPage }, (_, index) => ({ url: url(index + 1), label: String(index + 1), active: index + 1 === currentPage })),
            { url: currentPage < lastPage ? url(currentPage + 1) : null, label: 'Siguiente &raquo;', active: false },
        ],
        meta: { current_page: currentPage, last_page: lastPage, per_page: PER_PAGE, total },
    };
}

export const notificationsProps = {
    ...sharedFor('jhon', { notificationsUnread: UNREAD_PAGE_ONE }),
    notifications: paginate(PAGE_ONE, PAGE_ONE.length),
    now: NOW_ISO,
} satisfies NotificationsPage;

export const variants: Record<string, MockVariant<NotificationsPage>> = {
    unread: { page: 'Notifications/Index', label: 'Con sin leer (incluye una alerta sin enlace)', props: notificationsProps },
    allRead: {
        page: 'Notifications/Index',
        label: 'Todas leídas',
        props: {
            ...sharedFor('jhon', { notificationsUnread: 0 }),
            notifications: paginate(markAllRead(PAGE_ONE), PAGE_ONE.length),
            now: NOW_ISO,
        },
    },
    secondPage: {
        page: 'Notifications/Index',
        label: 'Página 2 (más antiguas)',
        props: {
            ...sharedFor('jhon', { notificationsUnread: UNREAD_PAGE_ONE }),
            notifications: paginate(PAGE_TWO, PER_PAGE + PAGE_TWO.length, 2),
            now: NOW_ISO,
        },
    },
    requester: {
        page: 'Notifications/Index',
        label: 'Solicitante (Andrés)',
        props: {
            ...sharedFor('andres', { notificationsUnread: 1 }),
            notifications: paginate(REQUESTER_ITEMS, REQUESTER_ITEMS.length),
            now: NOW_ISO,
        },
    },
    empty: {
        page: 'Notifications/Index',
        label: 'Vacía',
        props: {
            ...sharedFor('jhon', { notificationsUnread: 0 }),
            notifications: paginate([], 0),
            now: NOW_ISO,
        },
    },
    serverUnread: {
        page: 'Notifications/Index',
        label: 'Sin leer con filtro del servidor (pedido a la app)',
        props: {
            ...sharedFor('jhon', { notificationsUnread: UNREAD_PAGE_ONE }),
            notifications: paginate(
                PAGE_ONE.filter((item) => item.read_at === null),
                UNREAD_PAGE_ONE,
            ),
            filters: { unread: true },
            now: NOW_ISO,
        },
    },
};
