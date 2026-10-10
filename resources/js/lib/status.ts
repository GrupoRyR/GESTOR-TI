/**
 * Gestor IA request lifecycle: label, color family (maps to the status
 * tokens in app.css) and the phase shown on the phase rail.
 */

export type StatusFamily = 'gray' | 'amber' | 'blue' | 'green' | 'slate' | 'red';

export type Phase = 1 | 2 | 3 | 4 | 5;

export type RequestStatusKey =
    | 'received'
    | 'evaluating'
    | 'needs_info'
    | 'pending_approval'
    | 'approved'
    | 'backlog'
    | 'rejected'
    | 'planned'
    | 'in_development'
    | 'in_testing'
    | 'in_production'
    | 'closed';

export interface RequestStatus {
    key: RequestStatusKey;
    label: string;
    family: StatusFamily;
    phase: Phase;
}

export const PHASE_LABELS: Record<Phase, string> = {
    1: 'Evaluación',
    2: 'Aprobación',
    3: 'Plan',
    4: 'Construcción',
    5: 'Entrega',
};

export const REQUEST_STATUSES: Record<RequestStatusKey, RequestStatus> = {
    received: { key: 'received', label: 'Recibida', family: 'gray', phase: 1 },
    evaluating: { key: 'evaluating', label: 'En evaluación', family: 'gray', phase: 1 },
    needs_info: { key: 'needs_info', label: 'Necesita información', family: 'amber', phase: 1 },
    pending_approval: { key: 'pending_approval', label: 'Pendiente de aprobación', family: 'amber', phase: 2 },
    approved: { key: 'approved', label: 'Aprobada', family: 'green', phase: 2 },
    backlog: { key: 'backlog', label: 'En backlog', family: 'slate', phase: 2 },
    rejected: { key: 'rejected', label: 'Rechazada', family: 'red', phase: 2 },
    planned: { key: 'planned', label: 'Planificada', family: 'blue', phase: 3 },
    in_development: { key: 'in_development', label: 'En desarrollo', family: 'blue', phase: 4 },
    in_testing: { key: 'in_testing', label: 'En pruebas', family: 'amber', phase: 4 },
    in_production: { key: 'in_production', label: 'En producción', family: 'green', phase: 5 },
    closed: { key: 'closed', label: 'Cerrada', family: 'slate', phase: 5 },
};

export function requestStatus(key: RequestStatusKey): RequestStatus {
    return REQUEST_STATUSES[key];
}
