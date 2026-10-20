/**
 * Mocks for Admin/Employees/Show (A-EmployeeDetail, A-EmployeeDetailPhone):
 * a viewer with `credentials.view` and `employees.manage`, one without
 * credential permission, an employee without area or Gestor account, and an
 * area fixed by hand with today's backend shape (no requested fields).
 *
 * No password lives here: the props never carry one. In the gallery the
 * reveal POST is answered by `ui-preview/shims/revealFetch.ts` with the
 * placeholder "[CONTRASEÑA]".
 * All instants are at or before NOW_ISO (Wednesday 7 Oct 2026, 9:40 a. m.).
 */
import type { EmployeeAccess, EmployeeDetail, EmployeeShowProps } from '@/types/pages/admin-employees';
import type { PageProps } from '@/types/shared';
import { AREAS } from './employees';
import { SEGUREX, sharedFor, type MockVariant } from './shared';

type EmployeeShowPage = PageProps<EmployeeShowProps>;

const SEGUREX_AREAS = AREAS.filter((area) => area.company_id === SEGUREX.id).map(({ id, name }) => ({ id, name }));

const SAP = { id: 1, name: 'SAP Business One' };
const TOMA_PEDIDOS = { id: 2, name: 'Toma Pedidos SAP' };
const OTHER_SYSTEM = { id: 3, name: '[SISTEMA]' };
const ACCESS_SYSTEMS = [SAP, TOMA_PEDIDOS, OTHER_SYSTEM];

const PAOLA: EmployeeDetail = {
    id: 101,
    name: 'Paola Ríos',
    email: 'paola.rios@segurex.com',
    job_title: 'Coordinadora de ventas',
    department: 'Ventas',
    area: { id: 2, name: 'Ventas' },
    status: 'active',
    user_id: 21,
    source: 'graph',
    last_synced_at: '2026-10-07T06:00:00-05:00',
    area_locked: false,
    manager: { id: 120, name: '[JEFE DE VENTAS]', job_title: '[CARGO]' },
    // Requested from the app (not sent by the backend yet).
    company_id: SEGUREX.id,
    user_roles: ['Usuario final'],
    reports: [{ id: 105, name: 'Juan Pérez', job_title: 'Asesor comercial', area_name: 'Ventas' }],
};

const PAOLA_ACCESSES: EmployeeAccess[] = [
    { id: 501, system: SAP, username: '[USUARIO]', notes: null, last_rotated_at: '2026-09-15T10:00:00-05:00' },
    { id: 502, system: TOMA_PEDIDOS, username: '[USUARIO]', notes: 'Solo para pedidos de la regional norte.', last_rotated_at: '2026-08-02T08:30:00-05:00' },
    { id: 503, system: OTHER_SYSTEM, username: '[USUARIO]', notes: null, last_rotated_at: null },
];

/** What a viewer without `credentials.view` receives: no usernames, no notes. */
const withoutCredentials = (accesses: EmployeeAccess[]): EmployeeAccess[] => accesses.map((access) => ({ ...access, username: null, notes: null }));

const DIEGO: EmployeeDetail = {
    id: 107,
    name: 'Diego Restrepo',
    email: 'diego.restrepo@segurex.com',
    job_title: 'Auxiliar de cartera',
    department: '[DEPARTAMENTO]',
    area: null,
    status: 'active',
    user_id: null,
    source: 'graph',
    last_synced_at: '2026-10-07T06:00:00-05:00',
    area_locked: false,
    manager: { id: 121, name: '[JEFE DE CARTERA]', job_title: '[CARGO]' },
    company_id: SEGUREX.id,
    reports: [],
};

const base = { areas: SEGUREX_AREAS, access_systems: ACCESS_SYSTEMS };

export const variants: Record<string, MockVariant<EmployeeShowPage>> = {
    withReveal: {
        page: 'Admin/Employees/Show',
        label: 'Con permiso de ver credenciales (Jhon)',
        props: {
            ...sharedFor('jhon'),
            ...base,
            employee: PAOLA,
            accesses: PAOLA_ACCESSES,
            can_reveal: true,
            can_manage: true,
        },
    },
    noPermission: {
        page: 'Admin/Employees/Show',
        label: 'Sin permiso de credenciales ni de administrar',
        props: {
            ...sharedFor('edward'),
            ...base,
            employee: PAOLA,
            accesses: withoutCredentials(PAOLA_ACCESSES),
            can_reveal: false,
            can_manage: false,
        },
    },
    noArea: {
        page: 'Admin/Employees/Show',
        label: 'Sin área y sin cuenta en el Gestor',
        props: {
            ...sharedFor('jhon'),
            ...base,
            employee: DIEGO,
            accesses: withoutCredentials([{ id: 504, system: SAP, username: null, notes: null, last_rotated_at: '2026-09-28T14:00:00-05:00' }]),
            can_reveal: false,
            can_manage: true,
        },
    },
    locked: {
        page: 'Admin/Employees/Show',
        label: 'Área fijada a mano (forma actual del backend)',
        props: {
            ...sharedFor('jhon'),
            ...base,
            employee: {
                id: 105,
                name: 'Juan Pérez',
                email: 'juan.perez@segurex.com',
                job_title: 'Asesor comercial',
                department: 'Comercial',
                area: { id: 2, name: 'Ventas' },
                status: 'inactive',
                user_id: 34,
                source: 'graph',
                last_synced_at: '2026-10-06T06:00:00-05:00',
                area_locked: true,
                manager: { id: 101, name: 'Paola Ríos' },
            },
            accesses: [],
            can_reveal: true,
            can_manage: true,
        },
    },
};
