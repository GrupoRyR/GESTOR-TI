/**
 * Mocks for Employees/Index: both companies in "Todas", one company,
 * "Sin área" filter, empty directory, sync error and a requester-like
 * viewer without management permissions.
 */
import type { EmployeeArea, EmployeeFilters, EmployeeRow, EmployeesIndexProps } from '@/types/pages/employees';
import type { PageProps, Paginated } from '@/types/shared';
import { DERIVADOS, SEGUREX, sharedFor, type MockVariant } from './shared';

type EmployeesPage = PageProps<EmployeesIndexProps>;

export const AREAS: EmployeeArea[] = [
    { id: 1, name: 'TI', company_id: SEGUREX.id },
    { id: 2, name: 'Ventas', company_id: SEGUREX.id },
    { id: 3, name: 'Gerencia', company_id: SEGUREX.id },
    { id: 4, name: 'Cartera', company_id: SEGUREX.id },
    { id: 5, name: 'Mercadeo', company_id: SEGUREX.id },
    { id: 11, name: 'Compras', company_id: DERIVADOS.id },
    { id: 12, name: 'Tesorería', company_id: DERIVADOS.id },
    { id: 13, name: 'Diseño', company_id: DERIVADOS.id },
];

const area = (id: number) => {
    const found = AREAS.find((item) => item.id === id);
    return found ? { id: found.id, name: found.name } : null;
};

export const EMPLOYEES: EmployeeRow[] = [
    {
        id: 101,
        name: 'Paola Ríos',
        email: 'paola.rios@segurex.com',
        job_title: 'Coordinadora de ventas',
        area: area(2),
        company_id: SEGUREX.id,
        manager_name: '[JEFE DE VENTAS]',
        user: { roles: ['Usuario final'] },
        accesses_count: 3,
    },
    {
        id: 102,
        name: 'Andrés Méndez',
        email: 'andres.mendez@derivadoscol.com',
        job_title: 'Analista de compras',
        area: area(11),
        company_id: DERIVADOS.id,
        manager_name: '[JEFE DE COMPRAS]',
        user: { roles: ['Usuario final'] },
        accesses_count: 2,
    },
    {
        id: 103,
        name: 'Leonardo Herrera',
        email: 'leonardo.herrera@segurex.com',
        job_title: 'Gerente de TI',
        area: area(1),
        company_id: SEGUREX.id,
        manager_name: null,
        user: { roles: ['Súper Admin'] },
        accesses_count: 6,
    },
    {
        id: 104,
        name: 'Edward Ramanan',
        email: 'edward.ramanan@segurex.com',
        job_title: 'CEO',
        area: area(3),
        company_id: SEGUREX.id,
        manager_name: null,
        user: { roles: ['Aprobador de proyectos'] },
        accesses_count: 4,
    },
    {
        id: 105,
        name: 'Juan Pérez',
        email: 'juan.perez@segurex.com',
        job_title: 'Asesor comercial',
        area: area(2),
        company_id: SEGUREX.id,
        manager_name: 'Paola Ríos',
        user: null,
        accesses_count: 3,
    },
    {
        id: 106,
        name: 'Mónica Salazar',
        email: 'monica.salazar@derivadoscol.com',
        job_title: 'Tesorera',
        area: area(12),
        company_id: DERIVADOS.id,
        manager_name: '[GERENTE FINANCIERO]',
        user: null,
        accesses_count: 2,
    },
    {
        id: 107,
        name: 'Diego Restrepo',
        email: 'diego.restrepo@segurex.com',
        job_title: 'Auxiliar de cartera',
        area: null,
        company_id: SEGUREX.id,
        manager_name: '[JEFE DE CARTERA]',
        user: null,
        accesses_count: 1,
    },
    {
        id: 108,
        name: 'Camila Torres',
        email: 'camila.torres@derivadoscol.com',
        job_title: 'Diseñadora gráfica',
        area: area(13),
        company_id: DERIVADOS.id,
        manager_name: 'Andrés Méndez',
        user: { roles: ['Usuario final'] },
        accesses_count: 0,
    },
];

/** Laravel-style paginator around a slice. */
function paginate(rows: EmployeeRow[], total: number, currentPage = 1, perPage = 8): Paginated<EmployeeRow> {
    const lastPage = Math.max(1, Math.ceil(total / perPage));
    const url = (page: number) => `#employees.index?page=${page}`;
    return {
        data: rows,
        links: [
            { url: currentPage > 1 ? url(currentPage - 1) : null, label: '&laquo; Anterior', active: false },
            ...Array.from({ length: Math.min(lastPage, 3) }, (_, index) => ({
                url: url(index + 1),
                label: String(index + 1),
                active: index + 1 === currentPage,
            })),
            { url: currentPage < lastPage ? url(currentPage + 1) : null, label: 'Siguiente &raquo;', active: false },
        ],
        meta: { current_page: currentPage, last_page: lastPage, per_page: perPage, total },
    };
}

const FILTERS: EmployeeFilters = { q: null, company_id: null, area_id: null, status: 'active', without_area: false };

const base: EmployeesIndexProps = {
    employees: paginate(EMPLOYEES, 214),
    filters: FILTERS,
    areas: AREAS,
    counts: { without_area: 4, total: 214 },
    last_sync_at: '2026-10-07T06:00:00-05:00',
    sync_error: null,
};

export const employeesProps = { ...sharedFor('jhon'), ...base } satisfies EmployeesPage;

const derivadosRows = EMPLOYEES.filter((row) => row.company_id === DERIVADOS.id);

export const variants: Record<string, MockVariant<EmployeesPage>> = {
    all: { page: 'Employees/Index', label: 'Todas las empresas', props: employeesProps },
    segurex: {
        page: 'Employees/Index',
        label: 'Solo Segurex',
        props: {
            ...employeesProps,
            ...sharedFor('jhon', { currentCompanyId: SEGUREX.id }),
            employees: paginate(EMPLOYEES.filter((row) => row.company_id === SEGUREX.id), 151),
            areas: AREAS.filter((item) => item.company_id === SEGUREX.id),
            counts: { without_area: 3, total: 151 },
        } satisfies EmployeesPage,
    },
    'without-area': {
        page: 'Employees/Index',
        label: 'Filtro «Sin área»',
        props: {
            ...employeesProps,
            employees: paginate(EMPLOYEES.filter((row) => row.area === null), 4),
            filters: { ...FILTERS, without_area: true },
        } satisfies EmployeesPage,
    },
    'no-results': {
        page: 'Employees/Index',
        label: 'Búsqueda sin resultados',
        props: {
            ...employeesProps,
            employees: paginate([], 0),
            filters: { ...FILTERS, q: 'Gutiérrez' },
        } satisfies EmployeesPage,
    },
    empty: {
        page: 'Employees/Index',
        label: 'Derivados sin colaboradores todavía',
        props: {
            ...employeesProps,
            ...sharedFor('jhon', { currentCompanyId: DERIVADOS.id }),
            employees: paginate([], 0),
            areas: [],
            counts: { without_area: 0, total: 0 },
            last_sync_at: null,
        } satisfies EmployeesPage,
    },
    'sync-error': {
        page: 'Employees/Index',
        label: 'Falló la sincronización',
        props: {
            ...employeesProps,
            ...sharedFor('jhon', { currentCompanyId: DERIVADOS.id }),
            employees: paginate(derivadosRows, 63),
            areas: AREAS.filter((item) => item.company_id === DERIVADOS.id),
            counts: { without_area: 1, total: 63 },
            last_sync_at: '2026-10-06T06:00:00-05:00',
            sync_error:
                'El inquilino de Derivados no ha dado el permiso User.Read.All. Un administrador de Microsoft de Derivados debe aprobarlo; después vuelve a intentar. Código graph_403.',
        } satisfies EmployeesPage,
    },
    'read-only': {
        page: 'Employees/Index',
        label: 'Andrés (sin permisos de gestión)',
        props: {
            ...employeesProps,
            ...sharedFor('andres'),
            employees: paginate(derivadosRows, 63),
            areas: AREAS.filter((item) => item.company_id === DERIVADOS.id),
            counts: { without_area: 1, total: 63 },
        } satisfies EmployeesPage,
    },
};
