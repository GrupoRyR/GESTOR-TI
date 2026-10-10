/**
 * Colaboradores (`Pages/Employees/Index.tsx`, Inertia component `Employees/Index`).
 *
 * Ziggy routes used:
 * - `employees.index`           GET   query = `EmployeeFilters` (null/false values omitted); also `page`
 * - `employees.show`            GET   `{ employee: id }`        row link
 * - `employees.create`          GET   —                         "Agregar colaborador" / "Agregar a mano"
 * - `employees.sync`            POST  —                         "Sincronizar ahora" / "Volver a intentar"
 * - `admin.integrations.index`  GET   —                         "Ver la integración" in the sync error
 *
 * Permissions:
 * - `ai.manage`         shows create and sync actions and the integration link.
 * - `credentials.view`  shows the "Accesos" column.
 * - The company column appears only when `current_company_id === null` ("Todas"),
 *   which requires `companies.view_all`.
 */
import type { Paginated } from '@/types/shared';

export interface EmployeeRow {
    id: number;
    name: string;
    email: string;
    job_title: string | null;
    area: { id: number; name: string } | null;
    company_id: number;
    manager_name: string | null;
    /** Gestor TI account; `null` = has never signed in. */
    user: { roles: string[] } | null;
    /** Apps and credentials this person has access to. */
    accesses_count: number;
}

export type EmployeeStatusFilter = 'active' | 'inactive' | 'all';

export interface EmployeeFilters {
    q: string | null;
    company_id: number | null;
    area_id: number | null;
    status: EmployeeStatusFilter;
    without_area: boolean;
}

export interface EmployeeArea {
    id: number;
    name: string;
    company_id: number;
}

export interface EmployeesIndexProps {
    employees: Paginated<EmployeeRow>;
    filters: EmployeeFilters;
    areas: EmployeeArea[];
    counts: { without_area: number; total: number };
    /** Last successful Microsoft sync (ISO 8601). */
    last_sync_at: string | null;
    /** Message of the last failed sync; `null` when the last run worked. */
    sync_error: string | null;
}
