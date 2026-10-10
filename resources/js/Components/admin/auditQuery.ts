import type { AuditFilters } from '../../types/pages/admin';

/** Default Auditoría view: everything from the last 7 days. */
export const DEFAULT_AUDIT_FILTERS: AuditFilters = { causer_id: null, module: null, sensitive_only: false, range: '7d' };

/** Query string for `admin.audit.index` and `admin.audit.export`: only active filters. */
export function auditQuery(filters: AuditFilters): Record<string, string | number> {
    const query: Record<string, string | number> = { range: filters.range };
    if (filters.causer_id !== null) query.causer_id = filters.causer_id;
    if (filters.module) query.module = filters.module;
    if (filters.sensitive_only) query.sensitive_only = 1;
    return query;
}

export function isAuditFiltered(filters: AuditFilters): boolean {
    return (
        filters.causer_id !== null ||
        filters.module !== null ||
        filters.sensitive_only ||
        filters.range !== DEFAULT_AUDIT_FILTERS.range
    );
}
