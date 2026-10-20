/**
 * Ficha del colaborador (`Pages/Admin/Employees/Show.tsx`, Inertia component
 * `Admin/Employees/Show`), opened from each row of Colaboradores.
 *
 * Ziggy routes used:
 * - `employees.index`             GET   —                                       breadcrumb / phone back
 * - `employees.show`              GET   `{ employee: id }`                      manager and report links
 * - `employees.update`            PUT   `{ employee: id }`  body `{ area_id: number | null }`
 *                                                           (a number fixes the area; `null` goes back to the
 *                                                           Microsoft department mapping) or `{ status }`
 * - `employees.accesses.reveal`   POST  `{ employee: id, access: id }`  no body. JSON, not Inertia:
 *                                       `{ value, expires_in: 30 }` with no-store headers; 403 with a
 *                                       Spanish `message`, 429 after 10 per minute. Audited as
 *                                       `credential_revealed`. The page sends it with `fetch` and keeps
 *                                       the value only in component state.
 *
 * Permissions:
 * - `employees.view`     opens the page.
 * - `credentials.view`   (`can_reveal`) usernames, notes and "Ver contraseña". Without it the server
 *                        sends `username` and `notes` as `null`.
 * - `employees.manage`   (`can_manage`) area assignment and "Marcar como inactivo".
 */

export type EmployeeStatus = 'active' | 'inactive';

/** `graph` = synced from Microsoft; `manual` = added by hand in the Gestor. */
export type EmployeeSource = 'graph' | 'manual';

export interface EmployeeRef {
    id: number;
    name: string;
    /** Requested from the app: manager and report cards show the job title under the name. */
    job_title?: string | null;
}

export interface EmployeeDetail {
    id: number;
    name: string;
    email: string | null;
    job_title: string | null;
    /** Microsoft department (where the automatic area comes from). */
    department: string | null;
    area: { id: number; name: string } | null;
    status: EmployeeStatus;
    /** Gestor TI account; `null` = has never signed in. */
    user_id: number | null;
    source: EmployeeSource;
    last_synced_at: string | null;
    /** `true` = area chosen by hand; the Microsoft sync keeps it. */
    area_locked: boolean;
    manager: EmployeeRef | null;
    /** Requested from the app: company badge in the header. */
    company_id?: number;
    /** Requested from the app: Gestor roles of the linked user (only when `user_id` is set). */
    user_roles?: string[];
    /** Requested from the app: "Le reportan". Absent = the section is not shown. */
    reports?: (EmployeeRef & { area_name?: string | null })[];
}

export interface EmployeeAccess {
    id: number;
    system: { id: number; name: string };
    /** `null` without `credentials.view`. */
    username: string | null;
    /** `null` without `credentials.view`. */
    notes: string | null;
    last_rotated_at: string | null;
}

export interface EmployeeShowProps {
    employee: EmployeeDetail;
    accesses: EmployeeAccess[];
    can_reveal: boolean;
    can_manage: boolean;
    /** Areas of the employee's company. */
    areas: { id: number; name: string }[];
    /** Active access systems (for an add-access form; unused by the approved design). */
    access_systems: { id: number; name: string }[];
}
