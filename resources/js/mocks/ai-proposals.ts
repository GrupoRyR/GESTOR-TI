/**
 * "Proponer proyecto" mocks (boards B-ProposeSelf, B-ProposeApproval,
 * B-ProposePhone): Jhon self-approves, Leonardo sends to approval, a
 * validation error, and the bare page without apps or next keys.
 */
import type { RequestTypeOption } from '@/types/pages/ai-requester';
import type { ProposalAreaGroup, ProposalsCreateProps } from '@/types/pages/ai-proposals';
import type { PageProps } from '@/types/shared';
import { AREAS, PEOPLE, PRODUCT_REFS, TODAY, WIP_LIMIT } from './ai-fixtures';
import { DERIVADOS, SEGUREX, sharedFor, type MockVariant } from './shared';

type CreatePage = PageProps<ProposalsCreateProps>;

/** Same step 1 options the wizard receives. */
const TYPES: RequestTypeOption[] = [
    { value: 'new', title: 'Algo nuevo', description: 'Una app, un reporte o una automatización que no existe.' },
    { value: 'improvement', title: 'Mejorar una app que ya usamos', description: 'Agregar o cambiar algo en una app que ya funciona.' },
    { value: 'bug', title: 'Algo no funciona en una app', description: 'Un error, algo lento o un dato que sale mal.' },
    { value: 'idea', title: 'Tengo una idea o una pregunta', description: 'No sé si se puede. Quiero que TI lo mire.' },
];

const company = (source: typeof SEGUREX) => ({ id: source.id, name: source.name, short_name: source.short_name });

const AREA_GROUPS: ProposalAreaGroup[] = [
    {
        company: company(SEGUREX),
        areas: [AREAS.ventas, AREAS.cartera, AREAS.contabilidad, AREAS.gerencia, AREAS.comprasS, AREAS.tesoreriaS, AREAS.produccionS],
    },
    {
        company: company(DERIVADOS),
        areas: [AREAS.compras, AREAS.ventasD, AREAS.tesoreria, AREAS.diseno, AREAS.mercadeo, AREAS.produccion],
    },
];

const APPS = [PRODUCT_REFS.tomaPedidos, PRODUCT_REFS.portafolio, PRODUCT_REFS.cuentas, PRODUCT_REFS.visualizador, PRODUCT_REFS.camaras];

const BASE: ProposalsCreateProps = {
    can_self_approve: true,
    approvers: [PEOPLE.jhon, PEOPLE.edward],
    notify_on_self_approve: [PEOPLE.edward],
    team: [PEOPLE.jhon, PEOPLE.leonardo],
    types: TYPES,
    areas: AREA_GROUPS,
    apps: APPS,
    wip: { in_development: 3, limit: WIP_LIMIT },
    today: TODAY,
    next_request_key: 'GIA-154',
    next_project_key: 'P-08',
};

/** B-ProposeSelf: Jhon proposes the accounts-payable match and approves it himself. */
const selfApprove: CreatePage = {
    ...sharedFor('jhon'),
    ...BASE,
    draft: {
        type: 'improvement',
        product_slug: PRODUCT_REFS.cuentas.slug,
        title: 'Cuentas por pagar: cruzar las facturas de proveedores con las entradas de SAP B1',
        problem:
            'Antes de pagar, Contabilidad cruza a mano cada factura radicada contra la entrada de mercancía en SAP. Se demora el pago y a veces se pasan diferencias de cantidad o precio.',
        current_process: 'Exportan las entradas de SAP a Excel y buscan cada factura una por una.',
        expected_result: 'Que la app marque sola las facturas que coinciden y deje solo las diferencias para revisar.',
        area_id: AREAS.contabilidad.id,
        benefited_area_ids: [AREAS.comprasS.id, AREAS.tesoreriaS.id],
        hours_per_week: '5',
        people: '3',
        risk: 'medium',
        urgency: 'medium',
        size: 'M',
        hours: { [PEOPLE.jhon.id]: '32', [PEOPLE.leonardo.id]: '8' },
        due_date: '2026-11-27',
        project_name: 'Cuentas por pagar · cruce con SAP',
        start_date: '2026-10-13',
    },
};

const approvalDraft: ProposalsCreateProps['draft'] = {
    type: 'new',
    product_slug: null,
    title: 'Robot que descarga los extractos bancarios cada mañana',
    problem:
        'Cada mañana Tesorería entra a los portales de cuatro bancos para bajar los extractos y armar el saldo del día. Si alguien falta, el saldo sale tarde.',
    current_process: 'Entran a cada banco, descargan el extracto en Excel y copian los saldos a una hoja compartida.',
    expected_result: 'Que a las 7 a. m. los extractos ya estén descargados y el saldo del día armado en una sola hoja.',
    area_id: AREAS.tesoreriaS.id,
    benefited_area_ids: [AREAS.gerencia.id],
    hours_per_week: '6',
    people: '2',
    risk: 'low',
    urgency: 'medium',
    size: 'L',
    hours: { [PEOPLE.jhon.id]: '16', [PEOPLE.leonardo.id]: '32' },
    due_date: '2026-12-18',
};

/** B-ProposeApproval without the error: Leonardo cannot approve, so it goes to Jhon or Edward. */
const toApproval: CreatePage = {
    ...sharedFor('leonardo'),
    ...BASE,
    can_self_approve: false,
    notify_on_self_approve: [],
    next_project_key: null,
    draft: approvalDraft,
};

/** B-ProposeApproval: the server rejected the hours per week (90). */
const withErrors: CreatePage = {
    ...toApproval,
    draft: { ...approvalDraft, hours_per_week: '90' },
    errors: { hours_per_week: 'Escribe entre media hora y 60 horas por semana.' },
};

/** Optional props absent: no apps yet, no next keys; empty form (generic wording). */
const noAppsNoKeys: CreatePage = {
    ...sharedFor('jhon'),
    ...BASE,
    apps: [],
    next_request_key: undefined,
    next_project_key: undefined,
    wip: { in_development: 1, limit: WIP_LIMIT },
};

export const variants: Record<string, MockVariant<CreatePage>> = {
    selfApprove: { page: 'Ai/Proposals/Create', label: 'Proponer proyecto · Jhon aprueba al crear', props: selfApprove },
    toApproval: { page: 'Ai/Proposals/Create', label: 'Proponer proyecto · Leonardo, va a aprobación', props: toApproval },
    withErrors: { page: 'Ai/Proposals/Create', label: 'Proponer proyecto · con un campo por corregir', props: withErrors },
    noAppsNoKeys: { page: 'Ai/Proposals/Create', label: 'Proponer proyecto · sin apps ni claves (vacío)', props: noAppsNoKeys },
};
