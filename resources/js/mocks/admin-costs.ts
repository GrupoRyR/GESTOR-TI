/**
 * Mocks for Administración · Costos y aprobaciones (board B-AdminRates).
 * Rates are invented sample values consistent with the $ 160.000 / h used in
 * the triage estimates; area hour values match the triage mocks (Cartera
 * $ 45.000, Ventas and Tesorería unset).
 */
import type { AdminCostsIndexProps } from '@/types/pages/admin-costs';
import type { PageProps, SharedProps } from '@/types/shared';
import { AREAS, PEOPLE, TODAY } from './ai-fixtures';
import { sharedFor, type MockVariant } from './shared';

type AdminCostsPage = PageProps<AdminCostsIndexProps>;

function without(shared: SharedProps, ...removed: string[]): SharedProps {
    const user = shared.auth.user;
    return { ...shared, auth: { user: { ...user, permissions: user.permissions.filter((permission) => !removed.includes(permission)) } } };
}

const adminCosts: AdminCostsPage = {
    ...sharedFor('jhon'),
    rates: [
        {
            person: PEOPLE.jhon,
            rate: 160_000,
            effective_from: '2026-01-01',
            history: [
                { rate: 150_000, effective_from: '2025-07-01', changed_by: 'Leonardo Herrera' },
                { rate: 140_000, effective_from: '2025-01-01', changed_by: 'Leonardo Herrera' },
            ],
        },
        {
            person: PEOPLE.leonardo,
            rate: 160_000,
            effective_from: '2026-01-01',
            history: [{ rate: 150_000, effective_from: '2025-01-01', changed_by: 'Jhon Castro' }],
        },
    ],
    area_values: [
        { area: AREAS.cartera, value: 45_000 },
        { area: AREAS.ventas, value: null },
        { area: AREAS.contabilidad, value: null },
        { area: AREAS.compras, value: 13_000 },
        { area: AREAS.tesoreria, value: null },
    ],
    approvers: [
        { person: PEOPLE.jhon, detail: 'Líder IA · Segurex' },
        { person: PEOPLE.edward, detail: '[CARGO] · Gerencia · Segurex' },
    ],
    approver_candidates: [PEOPLE.leonardo],
    settings: { require_other_approver: true, reapproval_threshold_percent: 20 },
    today: TODAY,
};

export const variants: Record<string, MockVariant<AdminCostsPage>> = {
    'admin-costs': { page: 'Admin/Costs/Index', label: 'Costos y aprobaciones (Jhon)', props: adminCosts },
    'admin-costs-view-only': {
        page: 'Admin/Costs/Index',
        label: 'Costos y aprobaciones · ve tarifas sin poder cambiarlas',
        props: { ...adminCosts, ...without(sharedFor('leonardo'), 'ai.costs.manage') },
    },
    'admin-costs-no-permission': {
        page: 'Admin/Costs/Index',
        label: 'Costos y aprobaciones · sin permiso de costos',
        props: { ...adminCosts, ...without(sharedFor('leonardo'), 'ai.costs.view', 'ai.costs.manage'), rates: null, area_values: null },
    },
};
