/**
 * Home (`Pages/Home.tsx`, Inertia component `Home`). Two views chosen by the
 * server: `it` for the IT team, `requester` for everybody else.
 *
 * Ziggy routes used:
 * - `home`                 GET  —                           (layout)
 * - `ai.requests.create`   GET  —                           requester CTA
 * - `ai.requests.index`    GET  `{ attention?: 'unanswered' | 'overdue_tasks' | 'waiting_requirements', status?: 'pending_approval' }`
 *                                                            "Ver todas" and phone attention rows with more than one item
 * - `ai.requests.show`     GET  `{ request: key }`          requester cards; attention items (via `href_route`)
 * - `ai.tasks.show`        GET  attention items (via `href_route` / `href_params`)
 * - `ai.requirements.show` GET  attention items (via `href_route` / `href_params`)
 * - `ai.vault.index`       GET  —                           phone "Credenciales por vencer" row with several items
 * - `ai.ratings.create`    GET  `{ request: key }`          "Calificar"
 * - `ai.approvals.show`    GET  `{ request: key }`          approval cards
 *
 * Permissions:
 * - `ai.projects.approve`  shows "Esperan tu aprobación" (only the Aprobador de proyectos role).
 * - The server only fills `expiring_credentials` for users with `ai.vault.access`.
 */
import type { RequestStatusKey } from '@/lib/status';

export interface AttentionItem {
    id: string;
    /** Bold line, e.g. "GIA-148 · vencida hace 1 día". */
    title: string;
    /** Second line, e.g. "Avisar bloqueo de pedido por cartera". */
    detail: string;
    href_route: string;
    href_params?: Record<string, string | number>;
    severity: 'late' | 'attention';
}

export interface ApprovalItem {
    /** Request key, e.g. "GIA-146". */
    key: string;
    title: string;
    estimate_hours: number;
    /** Estimated cost in Colombian pesos. */
    cost_cop: number;
    /** ISO 8601 delivery date. */
    due_date: string;
}

export interface MonitoredAppPlaceholder {
    name: string;
    /** `local` apps (e.g. robotcamara) never send a heartbeat. */
    kind: 'web' | 'local';
}

export interface MonitoredApp extends MonitoredAppPlaceholder {
    status: 'up' | 'degraded' | 'down' | 'unknown';
    last_heartbeat_at: string | null;
}

export type HomeMonitoring = { enabled: false; apps: MonitoredAppPlaceholder[] } | { enabled: true; apps: MonitoredApp[] };

export interface HomeAttention {
    unanswered_requests: AttentionItem[];
    overdue_tasks: AttentionItem[];
    waiting_requirements: AttentionItem[];
    expiring_credentials: AttentionItem[];
}

export interface HomeItProps {
    view: 'it';
    greeting_name: string;
    attention: HomeAttention;
    pending_approvals: ApprovalItem[];
    monitoring: HomeMonitoring;
}

export interface RequesterRequest {
    key: string;
    title: string;
    status: RequestStatusKey;
    /** What happens next, in plain words; `null` hides the line. */
    next_step: string | null;
    /** Delivered and not rated yet. */
    can_rate: boolean;
    /** E.g. "Puesto 6 en la hoja de ruta de Compras" for backlog items. */
    roadmap_position: string | null;
}

export interface HomeRequesterProps {
    view: 'requester';
    greeting_name: string;
    requests: RequesterRequest[];
}

export type HomeProps = HomeItProps | HomeRequesterProps;
