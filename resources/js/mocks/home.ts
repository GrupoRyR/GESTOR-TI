/**
 * Mocks for Home: IT board (Jhon approves, Leonardo does not), requester
 * home (Andrés, Derivados) and their empty / edge states.
 */
import type { HomeAttention, HomeItProps, HomeRequesterProps, MonitoredAppPlaceholder } from '@/types/pages/home';
import type { PageProps } from '@/types/shared';
import { ANDINA, COMPANIES, DERIVADOS, sharedFor, type MockVariant } from './shared';

type HomePage = PageProps<HomeItProps> | PageProps<HomeRequesterProps>;

const APPS: MonitoredAppPlaceholder[] = [
    { name: 'Toma Pedidos SAP', kind: 'web' },
    { name: 'Portafolio de productos', kind: 'web' },
    { name: 'Cuentas por pagar', kind: 'web' },
    { name: 'Visualizador de cartera', kind: 'web' },
    { name: 'robotcamara', kind: 'local' },
];

const ATTENTION: HomeAttention = {
    unanswered_requests: [
        {
            id: 'GIA-148',
            title: 'GIA-148 · vencida hace 1 día',
            detail: 'Avisar bloqueo de pedido por cartera',
            href_route: 'ai.requests.show',
            href_params: { request: 'GIA-148' },
            severity: 'late',
        },
        {
            id: 'GIA-149',
            title: 'GIA-149 · vence hoy',
            detail: 'Reporte de comisiones en Excel',
            href_route: 'ai.requests.show',
            href_params: { request: 'GIA-149' },
            severity: 'attention',
        },
    ],
    overdue_tasks: [
        {
            id: 'GIA-131-T2',
            title: 'GIA-131 · T2 · 3 días',
            detail: 'Bloqueada por la API de WooCommerce',
            href_route: 'ai.tasks.show',
            href_params: { request: 'GIA-131', task: 'T2' },
            severity: 'late',
        },
    ],
    waiting_requirements: [
        {
            id: 'REQ-57',
            title: 'API de WooCommerce · Mercadeo',
            detail: 'Pedido hace 6 días · recordatorio mañana',
            href_route: 'ai.requirements.show',
            href_params: { requirement: 57 },
            severity: 'attention',
        },
        {
            id: 'REQ-61',
            title: 'Validar criterios · GIA-127',
            detail: 'Paola Ríos · En pruebas',
            href_route: 'ai.requirements.show',
            href_params: { requirement: 61 },
            severity: 'attention',
        },
        {
            id: 'REQ-63',
            title: 'Muestra de facturas · Tesorería',
            detail: 'Mónica Salazar · pedido hace 2 días',
            href_route: 'ai.requirements.show',
            href_params: { requirement: 63 },
            severity: 'attention',
        },
    ],
    expiring_credentials: [
        {
            id: 'CRED-12',
            title: 'DEEPSEEK_API_KEY · 12 días',
            detail: 'Rotación programada',
            href_route: 'ai.vault.index',
            severity: 'attention',
        },
    ],
};

const itBase: HomeItProps = {
    view: 'it',
    greeting_name: 'Jhon',
    attention: ATTENTION,
    pending_approvals: [
        { key: 'GIA-146', title: 'Fichas técnicas en PDF', estimate_hours: 40, cost_cop: 6_400_000, due_date: '2026-10-31T17:00:00-05:00' },
        { key: 'GIA-144', title: 'Conciliación bancaria', estimate_hours: 72, cost_cop: 11_500_000, due_date: '2026-11-28T17:00:00-05:00' },
    ],
    monitoring: { enabled: false, apps: APPS },
};

export const itProps = { ...sharedFor('jhon'), ...itBase } satisfies PageProps<HomeItProps>;

const requesterBase: HomeRequesterProps = {
    view: 'requester',
    greeting_name: 'Andrés',
    requests: [
        {
            key: 'GIA-146',
            title: 'Exportar fichas técnicas en PDF',
            status: 'pending_approval',
            next_step: 'TI ya la evaluó. Falta el visto bueno de un aprobador.',
            can_rate: false,
            roadmap_position: null,
        },
        {
            key: 'GIA-118',
            title: 'Buscador por referencia en el Portafolio',
            status: 'in_production',
            next_step: null,
            can_rate: true,
            roadmap_position: null,
        },
        {
            key: 'GIA-102',
            title: 'Alertas de stock bajo por correo',
            status: 'backlog',
            next_step: null,
            can_rate: false,
            roadmap_position: 'Puesto 6 en la hoja de ruta de Compras',
        },
        {
            key: 'GIA-139',
            title: 'Cargar cotizaciones de proveedores desde Excel',
            status: 'needs_info',
            next_step: 'TI te pidió un archivo de ejemplo. Respóndele desde la solicitud.',
            can_rate: false,
            roadmap_position: null,
        },
    ],
};

export const requesterProps = { ...sharedFor('andres'), ...requesterBase } satisfies PageProps<HomeRequesterProps>;

export const variants: Record<string, MockVariant<HomePage>> = {
    'it-approver': { page: 'Home', label: 'TI · Jhon (aprueba proyectos)', props: itProps },
    'it-super-admin': {
        page: 'Home',
        label: 'TI · Leonardo (sin aprobación ni bóveda)',
        props: {
            ...sharedFor('leonardo'),
            ...itBase,
            greeting_name: 'Leonardo',
            attention: { ...ATTENTION, expiring_credentials: [] },
        } satisfies PageProps<HomeItProps>,
    },
    'it-all-clear': {
        page: 'Home',
        label: 'TI · nada pendiente',
        props: {
            ...itProps,
            attention: { unanswered_requests: [], overdue_tasks: [], waiting_requirements: [], expiring_credentials: [] },
            pending_approvals: [],
        } satisfies PageProps<HomeItProps>,
    },
    'it-monitoring-live': {
        page: 'Home',
        label: 'TI · monitoreo activo (una app caída)',
        props: {
            ...itProps,
            monitoring: {
                enabled: true,
                apps: [
                    { name: 'Toma Pedidos SAP', kind: 'web', status: 'up', last_heartbeat_at: '2026-10-07T08:58:00-05:00' },
                    { name: 'Portafolio de productos', kind: 'web', status: 'up', last_heartbeat_at: '2026-10-07T08:59:00-05:00' },
                    { name: 'Cuentas por pagar', kind: 'web', status: 'degraded', last_heartbeat_at: '2026-10-07T08:41:00-05:00' },
                    { name: 'Visualizador de cartera', kind: 'web', status: 'down', last_heartbeat_at: '2026-10-07T06:12:00-05:00' },
                    { name: 'robotcamara', kind: 'local', status: 'unknown', last_heartbeat_at: null },
                ],
            },
        } satisfies PageProps<HomeItProps>,
    },
    'it-derivados': {
        page: 'Home',
        label: 'TI · empresa Derivados seleccionada',
        props: { ...itProps, ...sharedFor('jhon', { currentCompanyId: DERIVADOS.id }) } satisfies PageProps<HomeItProps>,
    },
    'it-andina': {
        page: 'Home',
        label: 'TI · marca de prueba Andina',
        props: {
            ...itProps,
            ...sharedFor('jhon', { companies: [...COMPANIES, ANDINA], currentCompanyId: ANDINA.id }),
        } satisfies PageProps<HomeItProps>,
    },
    requester: { page: 'Home', label: 'Solicitante · Andrés (Derivados)', props: requesterProps },
    'requester-empty': {
        page: 'Home',
        label: 'Solicitante · sin solicitudes',
        props: { ...requesterProps, requests: [] } satisfies PageProps<HomeRequesterProps>,
    },
    'requester-flash': {
        page: 'Home',
        label: 'Solicitante · recién envió una solicitud',
        props: {
            ...requesterProps,
            ...sharedFor('andres', { flash: { success: 'Recibimos tu solicitud GIA-150. Te avisamos cuando TI la revise.' } }),
        } satisfies PageProps<HomeRequesterProps>,
    },
};
