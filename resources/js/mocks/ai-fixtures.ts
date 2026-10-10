/**
 * Single source of Stage B mock entities, taken from the approved boards
 * ("Etapa B · Gestor IA", 2026-10-09). Page mocks (`mocks/ai*.ts`) import
 * from here so every screen tells the same story. Today in the mocks is
 * miércoles 7 oct 2026, 9:40 a. m. (Bogotá).
 *
 * This file exports no `variants`, so the gallery ignores it.
 *
 * Stored priorities (`priority_suggested`) are the approved board values;
 * live evaluation uses lib/priority (GIA-148 evaluates to 77 there too).
 */
import type {
    AcceptanceCriterion,
    AreaRef,
    AreaSummary,
    Doc,
    DocSummary,
    DiffLine,
    DocVersion,
    Estimate,
    PersonRef,
    Product,
    ProductVersion,
    Project,
    ProjectCost,
    RequestDetail,
    RequestSummary,
    Requirement,
    Task,
    TimeEntry,
    TimelineEvent,
    ValueMetrics,
    VaultAccessLog,
    VaultItem,
    VaultRevealed,
} from '@/types/ai';
import { DERIVADOS, SEGUREX } from './shared';

/** "Now" for every Stage B mock. */
export const NOW_ISO = '2026-10-07T09:40:00-05:00';
export const TODAY = '2026-10-07';
export const WIP_LIMIT = 3;

// ---- people ---------------------------------------------------------------

export const PEOPLE = {
    jhon: { id: 1, name: 'Jhon Castro', job_title: 'Líder IA', avatar_url: null },
    leonardo: { id: 2, name: 'Leonardo Herrera', job_title: 'Gerente de TI', avatar_url: null },
    edward: { id: 3, name: 'Edward Ramanan', job_title: '[CARGO]', avatar_url: null },
    paola: { id: 4, name: 'Paola Ríos', job_title: 'Coordinadora de ventas', avatar_url: null },
    juan: { id: 5, name: 'Juan Pérez', job_title: 'Asesor comercial', avatar_url: null },
    andres: { id: 7, name: 'Andrés Méndez', job_title: 'Analista de compras', avatar_url: null },
    monica: { id: 8, name: 'Mónica Salazar', job_title: 'Tesorera', avatar_url: null },
    camila: { id: 9, name: 'Camila Torres', job_title: 'Diseñadora', avatar_url: null },
    diego: { id: 10, name: 'Diego Restrepo', job_title: 'Auxiliar de cartera', avatar_url: null },
    cartera: { id: 11, name: 'Gerencia de cartera', job_title: null, avatar_url: null },
} satisfies Record<string, PersonRef>;

// ---- areas ----------------------------------------------------------------

export const AREAS = {
    ventas: { id: 11, name: 'Ventas', company_id: SEGUREX.id },
    cartera: { id: 12, name: 'Cartera', company_id: SEGUREX.id },
    contabilidad: { id: 13, name: 'Contabilidad', company_id: SEGUREX.id },
    gerencia: { id: 14, name: 'Gerencia', company_id: SEGUREX.id },
    comprasS: { id: 15, name: 'Compras', company_id: SEGUREX.id },
    tesoreriaS: { id: 16, name: 'Tesorería', company_id: SEGUREX.id },
    produccionS: { id: 17, name: 'Producción', company_id: SEGUREX.id },
    compras: { id: 21, name: 'Compras', company_id: DERIVADOS.id },
    ventasD: { id: 22, name: 'Ventas', company_id: DERIVADOS.id },
    tesoreria: { id: 23, name: 'Tesorería', company_id: DERIVADOS.id },
    diseno: { id: 24, name: 'Diseño', company_id: DERIVADOS.id },
    mercadeo: { id: 25, name: 'Mercadeo', company_id: DERIVADOS.id },
    produccion: { id: 26, name: 'Producción', company_id: DERIVADOS.id },
} satisfies Record<string, AreaRef>;

const area = (ref: AreaRef) => ({ id: ref.id, name: ref.name });

// ---- requests -------------------------------------------------------------

type RequestSeed = Omit<RequestSummary, 'similar_to' | 'project_key' | 'rank' | 'product' | 'urgency' | 'type'> &
    Partial<Pick<RequestSummary, 'similar_to' | 'project_key' | 'rank' | 'product' | 'urgency' | 'type'>>;

const request = (seed: RequestSeed): RequestSummary => ({
    type: 'improvement',
    similar_to: null,
    project_key: null,
    rank: null,
    product: null,
    urgency: null,
    ...seed,
});

const answered = (due_at: string, answered_at: string | null) => ({ due_at, answered_at });

export const PRODUCT_REFS = {
    tomaPedidos: { slug: 'toma-pedidos', name: 'Toma Pedidos SAP', version: 'v2.4.0' },
    portafolio: { slug: 'portafolio', name: 'Portafolio de productos', version: 'v1.8.2' },
    cuentas: { slug: 'cuentas-por-pagar', name: 'Cuentas por pagar', version: 'v1.2.0' },
    visualizador: { slug: 'visualizador-cartera', name: 'Visualizador de cartera', version: 'v3.1.0' },
    camaras: { slug: 'robotcamara', name: 'Cámaras', version: 'v0.9.3' },
} as const;

export const REQUESTS: RequestSummary[] = [
    request({
        key: 'GIA-153',
        title: 'Flujo de caja semanal desde SAP B1',
        type: 'new',
        status: 'received',
        company_id: DERIVADOS.id,
        area: area(AREAS.tesoreria),
        requester: PEOPLE.monica,
        hours_per_week: 4,
        people: 2,
        votes: 0,
        size: null,
        priority_suggested: null,
        priority_final: null,
        urgency: 'medium',
        first_response: answered('2026-10-08T09:10:00-05:00', null),
        created_at: '2026-10-07T09:10:00-05:00',
    }),
    request({
        key: 'GIA-152',
        title: 'Portafolio: subir fotos de producto en lote',
        status: 'received',
        company_id: DERIVADOS.id,
        area: area(AREAS.diseno),
        requester: PEOPLE.camila,
        hours_per_week: 2,
        people: 1,
        votes: 0,
        size: null,
        priority_suggested: null,
        priority_final: null,
        urgency: 'low',
        product: PRODUCT_REFS.portafolio,
        first_response: answered('2026-10-09T17:00:00-05:00', null),
        created_at: '2026-10-07T08:30:00-05:00',
    }),
    request({
        key: 'GIA-151',
        title: 'Visualizador de cartera: exportar a Excel la vista filtrada',
        status: 'received',
        company_id: SEGUREX.id,
        area: area(AREAS.cartera),
        requester: PEOPLE.diego,
        hours_per_week: 3,
        people: 2,
        votes: 1,
        size: null,
        priority_suggested: null,
        priority_final: null,
        urgency: 'medium',
        product: PRODUCT_REFS.visualizador,
        first_response: answered('2026-10-08T08:50:00-05:00', null),
        created_at: '2026-10-07T08:50:00-05:00',
    }),
    request({
        key: 'GIA-150',
        title: 'Comparar precios de proveedores en el Portafolio',
        status: 'needs_info',
        company_id: DERIVADOS.id,
        area: area(AREAS.compras),
        requester: PEOPLE.andres,
        hours_per_week: 6,
        people: 8,
        votes: 0,
        size: null,
        priority_suggested: null,
        priority_final: null,
        urgency: 'high',
        product: PRODUCT_REFS.portafolio,
        first_response: answered('2026-10-07T16:00:00-05:00', '2026-10-07T09:40:00-05:00'),
        created_at: '2026-10-06T16:00:00-05:00',
    }),
    request({
        key: 'GIA-149',
        title: 'Reporte de comisiones en Excel',
        type: 'new',
        status: 'received',
        company_id: SEGUREX.id,
        area: area(AREAS.ventas),
        requester: PEOPLE.juan,
        hours_per_week: null,
        people: null,
        votes: 0,
        size: null,
        priority_suggested: null,
        priority_final: null,
        first_response: answered('2026-10-07T17:00:00-05:00', null),
        created_at: '2026-10-06T17:00:00-05:00',
    }),
    request({
        key: 'GIA-148',
        title: 'Toma Pedidos SAP: avisar al asesor cuando el pedido quede bloqueado por cartera',
        status: 'received',
        company_id: SEGUREX.id,
        area: area(AREAS.ventas),
        requester: PEOPLE.paola,
        hours_per_week: 6,
        people: 8,
        votes: 3,
        size: null,
        priority_suggested: null,
        priority_final: null,
        urgency: 'high',
        product: PRODUCT_REFS.tomaPedidos,
        similar_to: { key: 'GIA-121', title: 'Alerta de pedidos bloqueados por cartera', votes: 4 },
        first_response: answered('2026-10-06T08:20:00-05:00', null),
        created_at: '2026-10-05T08:20:00-05:00',
    }),
    request({
        key: 'GIA-147',
        title: 'Toma Pedidos SAP: ver el cupo de crédito del cliente al tomar el pedido',
        status: 'needs_info',
        company_id: SEGUREX.id,
        area: area(AREAS.ventas),
        requester: PEOPLE.juan,
        hours_per_week: null,
        people: null,
        votes: 0,
        size: null,
        priority_suggested: null,
        priority_final: null,
        product: PRODUCT_REFS.tomaPedidos,
        first_response: answered('2026-10-05T10:00:00-05:00', '2026-10-04T14:00:00-05:00'),
        created_at: '2026-10-04T10:00:00-05:00',
    }),
    request({
        key: 'GIA-146',
        title: 'Portafolio de productos: exportar fichas técnicas en PDF',
        status: 'pending_approval',
        company_id: DERIVADOS.id,
        area: area(AREAS.compras),
        requester: PEOPLE.andres,
        hours_per_week: 3,
        people: 4,
        votes: 0,
        size: 'M',
        priority_suggested: 68,
        priority_final: 68,
        urgency: 'high',
        product: PRODUCT_REFS.portafolio,
        rank: 3,
        first_response: answered('2026-09-28T10:00:00-05:00', '2026-09-27T11:00:00-05:00'),
        created_at: '2026-09-26T10:00:00-05:00',
    }),
    request({
        key: 'GIA-144',
        title: 'Conciliación bancaria automática desde SAP B1',
        type: 'new',
        status: 'pending_approval',
        company_id: DERIVADOS.id,
        area: area(AREAS.tesoreria),
        requester: PEOPLE.monica,
        hours_per_week: 5,
        people: 2,
        votes: 1,
        size: 'L',
        priority_suggested: 71,
        priority_final: 71,
        urgency: 'medium',
        rank: 1,
        first_response: answered('2026-10-05T09:00:00-05:00', '2026-10-02T15:00:00-05:00'),
        created_at: '2026-10-02T09:00:00-05:00',
    }),
    request({
        key: 'GIA-141',
        title: 'Toma Pedidos SAP: historial de pedidos por cliente',
        status: 'approved',
        company_id: SEGUREX.id,
        area: area(AREAS.ventas),
        requester: PEOPLE.paola,
        hours_per_week: 3,
        people: 8,
        votes: 3,
        size: 'M',
        priority_suggested: 70,
        priority_final: 70,
        product: PRODUCT_REFS.tomaPedidos,
        first_response: answered('2026-09-22T09:00:00-05:00', '2026-09-21T15:00:00-05:00'),
        created_at: '2026-09-21T09:00:00-05:00',
    }),
    request({
        key: 'GIA-139',
        title: 'Visualizador de cartera: filtro por asesor y zona',
        status: 'in_development',
        company_id: SEGUREX.id,
        area: area(AREAS.cartera),
        requester: PEOPLE.cartera,
        hours_per_week: 4,
        people: 5,
        votes: 2,
        size: 'L',
        priority_suggested: 74,
        priority_final: 82,
        urgency: 'high',
        product: PRODUCT_REFS.visualizador,
        project_key: 'P-07',
        first_response: answered('2026-09-28T10:00:00-05:00', '2026-09-28T09:00:00-05:00'),
        created_at: '2026-09-26T10:00:00-05:00',
    }),
    request({
        key: 'GIA-136',
        title: 'Cuentas por pagar: adjuntar el soporte desde el celular',
        status: 'planned',
        company_id: SEGUREX.id,
        area: area(AREAS.contabilidad),
        requester: { id: 12, name: '[SOLICITANTE CONTABILIDAD]', job_title: null, avatar_url: null },
        hours_per_week: 2,
        people: 3,
        votes: 1,
        size: 'S',
        priority_suggested: 64,
        priority_final: 64,
        product: PRODUCT_REFS.cuentas,
        first_response: answered('2026-09-16T09:00:00-05:00', '2026-09-15T16:00:00-05:00'),
        created_at: '2026-09-15T09:00:00-05:00',
    }),
    request({
        key: 'GIA-133',
        title: 'Portafolio: precios por lista de cliente',
        status: 'planned',
        company_id: DERIVADOS.id,
        area: area(AREAS.ventasD),
        requester: PEOPLE.andres,
        hours_per_week: 2,
        people: 4,
        votes: 1,
        size: 'M',
        priority_suggested: 60,
        priority_final: 60,
        product: PRODUCT_REFS.portafolio,
        first_response: answered('2026-09-11T09:00:00-05:00', '2026-09-10T15:00:00-05:00'),
        created_at: '2026-09-10T09:00:00-05:00',
    }),
    request({
        key: 'GIA-131',
        title: 'Portafolio: conectar la API de WooCommerce',
        status: 'in_development',
        company_id: DERIVADOS.id,
        area: area(AREAS.ventasD),
        requester: PEOPLE.andres,
        hours_per_week: 3,
        people: 3,
        votes: 1,
        size: 'M',
        priority_suggested: 66,
        priority_final: 66,
        product: PRODUCT_REFS.portafolio,
        project_key: 'P-05',
        first_response: answered('2026-09-03T09:00:00-05:00', '2026-09-02T14:00:00-05:00'),
        created_at: '2026-09-02T09:00:00-05:00',
    }),
    request({
        key: 'GIA-127',
        title: 'Toma Pedidos SAP: descuentos por volumen',
        status: 'in_testing',
        company_id: SEGUREX.id,
        area: area(AREAS.ventas),
        requester: PEOPLE.paola,
        hours_per_week: 2,
        people: 8,
        votes: 1,
        size: 'M',
        priority_suggested: 69,
        priority_final: 69,
        product: PRODUCT_REFS.tomaPedidos,
        project_key: 'P-06',
        first_response: answered('2026-08-26T09:00:00-05:00', '2026-08-25T15:00:00-05:00'),
        created_at: '2026-08-25T09:00:00-05:00',
    }),
    request({
        key: 'GIA-121',
        title: 'Alerta de pedidos bloqueados por cartera',
        status: 'backlog',
        company_id: SEGUREX.id,
        area: area(AREAS.ventas),
        requester: PEOPLE.juan,
        hours_per_week: 2,
        people: 8,
        votes: 4,
        size: 'M',
        priority_suggested: 72,
        priority_final: 72,
        product: PRODUCT_REFS.tomaPedidos,
        rank: 2,
        first_response: answered('2026-08-19T09:00:00-05:00', '2026-08-18T16:00:00-05:00'),
        created_at: '2026-08-18T09:00:00-05:00',
    }),
    request({
        key: 'GIA-118',
        title: 'Portafolio: buscador por referencia',
        status: 'in_production',
        company_id: DERIVADOS.id,
        area: area(AREAS.compras),
        requester: PEOPLE.andres,
        hours_per_week: 3,
        people: 1,
        votes: 0,
        size: 'S',
        priority_suggested: 62,
        priority_final: 62,
        product: PRODUCT_REFS.portafolio,
        first_response: answered('2026-08-12T09:00:00-05:00', '2026-08-11T15:00:00-05:00'),
        created_at: '2026-08-11T09:00:00-05:00',
    }),
    request({
        key: 'GIA-116',
        title: 'Cámaras: aviso cuando una cámara deja de grabar',
        status: 'backlog',
        company_id: SEGUREX.id,
        area: area(AREAS.gerencia),
        requester: PEOPLE.leonardo,
        hours_per_week: 1,
        people: 2,
        votes: 0,
        size: 'S',
        priority_suggested: 49,
        priority_final: 49,
        product: PRODUCT_REFS.camaras,
        rank: 7,
        first_response: answered('2026-08-07T09:00:00-05:00', '2026-08-06T10:00:00-05:00'),
        created_at: '2026-08-06T09:00:00-05:00',
    }),
    request({
        key: 'GIA-113',
        title: 'Toma Pedidos SAP: historial de precios por cliente',
        status: 'backlog',
        company_id: SEGUREX.id,
        area: area(AREAS.ventas),
        requester: PEOPLE.paola,
        hours_per_week: 1.5,
        people: 8,
        votes: 3,
        size: 'M',
        priority_suggested: 66,
        priority_final: 66,
        product: PRODUCT_REFS.tomaPedidos,
        rank: 3,
        first_response: answered('2026-07-30T09:00:00-05:00', '2026-07-29T15:00:00-05:00'),
        created_at: '2026-07-29T09:00:00-05:00',
    }),
    request({
        key: 'GIA-110',
        title: 'Contabilidad: cierre de mes con lista de chequeo',
        type: 'new',
        status: 'backlog',
        company_id: SEGUREX.id,
        area: area(AREAS.contabilidad),
        requester: { id: 12, name: '[SOLICITANTE CONTABILIDAD]', job_title: null, avatar_url: null },
        hours_per_week: 3,
        people: 2,
        votes: 1,
        size: 'S',
        priority_suggested: 61,
        priority_final: 61,
        rank: 5,
        first_response: answered('2026-07-22T09:00:00-05:00', '2026-07-21T15:00:00-05:00'),
        created_at: '2026-07-21T09:00:00-05:00',
    }),
    request({
        key: 'GIA-108',
        title: 'Producción: tablero de órdenes de fabricación',
        type: 'new',
        status: 'backlog',
        company_id: DERIVADOS.id,
        area: area(AREAS.produccion),
        requester: { id: 13, name: '[SOLICITANTE PRODUCCIÓN]', job_title: null, avatar_url: null },
        hours_per_week: 5,
        people: 3,
        votes: 1,
        size: 'L',
        priority_suggested: 63,
        priority_final: 63,
        rank: 4,
        first_response: answered('2026-07-16T09:00:00-05:00', '2026-07-15T15:00:00-05:00'),
        created_at: '2026-07-15T09:00:00-05:00',
    }),
    request({
        key: 'GIA-104',
        title: 'Cartera: recordatorio automático de pago al cliente',
        type: 'new',
        status: 'backlog',
        company_id: SEGUREX.id,
        area: area(AREAS.cartera),
        requester: PEOPLE.diego,
        hours_per_week: 2,
        people: 4,
        votes: 2,
        size: 'M',
        priority_suggested: 55,
        priority_final: 55,
        rank: 6,
        first_response: answered('2026-07-09T09:00:00-05:00', '2026-07-08T15:00:00-05:00'),
        created_at: '2026-07-08T09:00:00-05:00',
    }),
    request({
        key: 'GIA-102',
        title: 'Alertas de stock bajo por correo',
        type: 'new',
        status: 'backlog',
        company_id: DERIVADOS.id,
        area: area(AREAS.compras),
        requester: PEOPLE.andres,
        hours_per_week: 3,
        people: 2,
        votes: 2,
        size: 'S',
        priority_suggested: 58,
        priority_final: 70,
        rank: 1,
        first_response: answered('2026-07-03T09:00:00-05:00', '2026-07-02T15:00:00-05:00'),
        created_at: '2026-07-02T09:00:00-05:00',
    }),
    request({
        key: 'GIA-097',
        title: 'Cuentas por pagar: aprobación de facturas en celular',
        status: 'rejected',
        company_id: SEGUREX.id,
        area: area(AREAS.contabilidad),
        requester: { id: 12, name: '[SOLICITANTE CONTABILIDAD]', job_title: null, avatar_url: null },
        hours_per_week: 1,
        people: 3,
        votes: 0,
        size: null,
        priority_suggested: null,
        priority_final: null,
        product: PRODUCT_REFS.cuentas,
        first_response: answered('2026-06-24T09:00:00-05:00', '2026-06-23T15:00:00-05:00'),
        created_at: '2026-06-23T09:00:00-05:00',
    }),
];

/** Reason shown on the rejected GIA-097. */
export const REJECTION_REASON = 'SAP ya lo trae con el módulo de aprobaciones.';

// ---- estimates, criteria, timelines ---------------------------------------

export const ESTIMATES: Record<string, Estimate> = {
    'GIA-146': {
        hours_by_person: [
            { person: PEOPLE.jhon, hours: 28 },
            { person: PEOPLE.leonardo, hours: 12 },
        ],
        hours_total: 40,
        external_costs: 0,
        due_date: '2026-10-31',
        total_cost: 6_400_000,
    },
    'GIA-144': {
        hours_by_person: [
            { person: PEOPLE.jhon, hours: 48 },
            { person: PEOPLE.leonardo, hours: 24 },
        ],
        hours_total: 72,
        external_costs: 0,
        due_date: '2026-11-28',
        total_cost: 11_500_000,
    },
};

export const CRITERIA_GIA_146: AcceptanceCriterion[] = [
    { id: 1, text: 'Desde la ficha de un producto hay un botón “Descargar PDF”.', validated: null, note: null },
    { id: 2, text: 'El PDF trae foto, referencia, medidas y descripción técnica.', validated: null, note: null },
    { id: 3, text: 'El PDF lleva el logo de Derivados arriba y el del proveedor junto a la referencia.', validated: null, note: null },
    { id: 4, text: 'Se pueden elegir varias fichas y bajarlas en un solo PDF.', validated: null, note: null },
];

export const CRITERIA_P07: AcceptanceCriterion[] = [
    { id: 11, text: 'Filtrar la cartera por asesor', validated: { by: 'Gerencia de cartera', at: '2026-10-06T11:00:00-05:00' }, note: null },
    { id: 12, text: 'Filtrar la cartera por zona', validated: { by: 'Gerencia de cartera', at: '2026-10-06T11:05:00-05:00' }, note: null },
    { id: 13, text: 'Ver el total vencido por asesor y por rango de días', validated: null, note: 'Depende de T4 y T7' },
    { id: 14, text: 'Exportar a Excel lo que se filtró', validated: null, note: null },
    { id: 15, text: 'Cargar en menos de 3 segundos con toda la cartera', validated: null, note: null },
];

export const TIMELINE_GIA_139: TimelineEvent[] = [
    { id: 4, kind: 'status', family: 'blue', title: 'En desarrollo', quote: null, detail: null, actor: 'Jhon Castro', at: '2026-10-07T09:40:00-05:00' },
    {
        id: 3,
        kind: 'question',
        family: 'amber',
        title: 'TI preguntó',
        quote: '¿El filtro por zona debe incluir clientes inactivos?',
        detail: 'respondida por Gerencia de cartera',
        actor: null,
        at: '2026-10-02T10:00:00-05:00',
    },
    {
        id: 2,
        kind: 'approval',
        family: 'green',
        title: 'Aprobada por Edward Ramanan',
        quote: null,
        detail: '120 h · $ 16.000.000 · entrega 31 oct',
        actor: 'Edward Ramanan',
        at: '2026-09-30T15:00:00-05:00',
    },
    {
        id: 1,
        kind: 'status',
        family: 'gray',
        title: 'Recibida',
        quote: null,
        detail: 'desde el botón "Pedir mejora" del Visualizador',
        actor: null,
        at: '2026-09-26T10:00:00-05:00',
    },
];

export const TIMELINE_GIA_146: TimelineEvent[] = [
    { id: 14, kind: 'status', family: 'amber', title: 'Pendiente de aprobación', quote: null, detail: 'TI la dejó lista para decidir', actor: null, at: '2026-10-02T10:00:00-05:00' },
    {
        id: 13,
        kind: 'answer',
        family: 'amber',
        title: 'TI preguntó',
        quote: '¿El PDF lleva el logo de Derivados, el del proveedor o los dos?',
        detail: 'Tu respuesta · 30 sep: El de Derivados arriba y el del proveedor pequeño junto a la referencia.',
        actor: 'Jhon Castro',
        at: '2026-09-29T11:00:00-05:00',
    },
    { id: 12, kind: 'status', family: 'gray', title: 'En evaluación', quote: null, detail: 'Jhon Castro la tomó', actor: 'Jhon Castro', at: '2026-09-27T11:00:00-05:00' },
    {
        id: 11,
        kind: 'status',
        family: 'gray',
        title: 'Recibida',
        quote: null,
        detail: 'desde el botón “Pedir mejora” del Portafolio de productos',
        actor: null,
        at: '2026-09-26T10:00:00-05:00',
    },
];

/** GIA-148 as TI sees it (with internal notes). */
export const REQUEST_GIA_148: RequestDetail = {
    ...REQUESTS.find((r) => r.key === 'GIA-148')!,
    answers: {
        problem:
            'Cuando un pedido queda bloqueado por cartera en SAP, el asesor no se entera. Lo descubre cuando el cliente llama a preguntar por qué no le ha llegado.',
        current_process:
            'Reviso en SAP los pedidos bloqueados dos veces al día y le escribo a cada asesor por WhatsApp con el cliente y el saldo vencido.',
        expected_result: 'Que el asesor reciba un aviso en Toma Pedidos y por correo apenas el pedido quede bloqueado, con el saldo vencido del cliente.',
        success_criteria: null,
        systems: ['Toma Pedidos SAP', 'SAP Business One'],
        systems_notes: null,
        deadline: null,
        deadline_reason: 'En noviembre y diciembre se duplican los pedidos.',
    },
    attachments: [
        { id: 1, name: 'pedidos-bloqueados-sep.xlsx', size: 48_000, mime: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', url: '#' },
        { id: 2, name: 'bloqueo-en-sap.png', size: 320_000, mime: 'image/png', url: '#' },
    ],
    criteria: [],
    timeline: [
        { id: 21, kind: 'status', family: 'gray', title: 'Recibida', quote: null, detail: null, actor: null, at: '2026-10-05T08:20:00-05:00' },
    ],
    comments: [
        { id: 31, author: PEOPLE.paola, is_it: false, body: 'Adjunto el listado de septiembre para que vean cuántos fueron.', created_at: '2026-10-05T08:25:00-05:00' },
        { id: 32, author: PEOPLE.juan, is_it: false, body: 'A mí me pasó con dos clientes la semana pasada.', created_at: '2026-10-05T15:10:00-05:00' },
    ],
    internal_notes: [
        {
            id: 41,
            author: PEOPLE.leonardo,
            is_it: true,
            body: 'SAP ya marca el bloqueo en el estado del pedido. Se puede leer con la misma consulta que usa el Visualizador de cartera, sin tocar SAP.',
            created_at: '2026-10-06T16:30:00-05:00',
        },
        {
            id: 42,
            author: PEOPLE.jhon,
            is_it: true,
            body: 'Si la unimos con GIA-121 quedan 8 personas pidiendo lo mismo. Le respondo a Paola hoy.',
            created_at: '2026-10-07T08:05:00-05:00',
        },
    ],
    estimate: null,
    approval: null,
    rating: null,
    followers_count: 4,
};

// ---- projects -------------------------------------------------------------

const WIP = { in_development: 3, limit: WIP_LIMIT };

export const PROJECTS: Project[] = [
    {
        key: 'P-07',
        name: 'Visualizador de cartera · filtros',
        status: 'in_development',
        objective:
            'Que la Gerencia de cartera filtre la cartera por asesor y por zona sin pedir reportes a TI, y vea cuánto tiene vencido cada asesor.',
        request_keys: ['GIA-139'],
        company_id: SEGUREX.id,
        area: area(AREAS.cartera),
        benefited_areas: [{ area: area(AREAS.ventas), note: 'los asesores ven su cartera' }],
        team: [
            { person: PEOPLE.jhon, role: 'Líder del proyecto' },
            { person: PEOPLE.leonardo, role: 'Desarrollo' },
        ],
        wip: WIP,
        budget: {
            hours: { estimate: 120, actual: 92 },
            cost: { estimate: 16_000_000, actual: 14_200_000 },
            due_date: '2026-10-31',
            projected_due_date: '2026-11-14',
            approved_at: '2026-09-30',
            needs_reapproval: true,
        },
        repository: { url: 'https://github.com/[ORG]/visualizador-cartera', branch: 'main', open_prs: 1, last_change_at: '2026-10-07T06:40:00-05:00' },
        tasks_by_column: { backlog: 2, todo: 2, in_progress: 2, review: 1, done: 2 },
        blocked_tasks: 1,
        criteria: CRITERIA_P07,
        open_requirements: 1,
        product_slug: 'visualizador-cartera',
        started_at: '2026-10-01',
    },
    {
        key: 'P-05',
        name: 'Portafolio · WooCommerce',
        status: 'in_development',
        objective: 'Publicar el catálogo del Portafolio en la tienda WooCommerce de Derivados sin cargar productos a mano.',
        request_keys: ['GIA-131'],
        company_id: DERIVADOS.id,
        area: area(AREAS.ventasD),
        benefited_areas: [{ area: area(AREAS.mercadeo), note: null }],
        team: [{ person: PEOPLE.jhon, role: 'Líder del proyecto' }],
        wip: WIP,
        budget: {
            hours: { estimate: 60, actual: 21 },
            cost: { estimate: 8_000_000, actual: 2_900_000 },
            due_date: '2026-11-15',
            projected_due_date: '2026-11-20',
            approved_at: '2026-09-10',
            needs_reapproval: false,
        },
        repository: { url: 'https://github.com/[ORG]/portafolio', branch: 'main', open_prs: 0, last_change_at: '2026-10-01T10:00:00-05:00' },
        tasks_by_column: { backlog: 1, todo: 1, in_progress: 1, review: 0, done: 1 },
        blocked_tasks: 1,
        criteria: [],
        open_requirements: 2,
        product_slug: 'portafolio',
        started_at: '2026-09-14',
    },
    {
        key: 'P-06',
        name: 'Toma Pedidos · descuentos por volumen',
        status: 'in_testing',
        objective: 'Que Toma Pedidos aplique solo los descuentos por volumen aprobados por Ventas.',
        request_keys: ['GIA-127'],
        company_id: SEGUREX.id,
        area: area(AREAS.ventas),
        benefited_areas: [],
        team: [
            { person: PEOPLE.leonardo, role: 'Líder del proyecto' },
            { person: PEOPLE.jhon, role: 'Desarrollo' },
        ],
        wip: WIP,
        budget: {
            hours: { estimate: 48, actual: 46 },
            cost: { estimate: 6_300_000, actual: 6_050_000 },
            due_date: '2026-10-16',
            projected_due_date: '2026-10-16',
            approved_at: '2026-08-28',
            needs_reapproval: false,
        },
        repository: { url: 'https://github.com/[ORG]/toma-pedidos', branch: 'main', open_prs: 0, last_change_at: '2026-10-05T17:00:00-05:00' },
        tasks_by_column: { backlog: 0, todo: 0, in_progress: 0, review: 1, done: 6 },
        blocked_tasks: 0,
        criteria: [],
        open_requirements: 1,
        product_slug: 'toma-pedidos',
        started_at: '2026-09-01',
    },
];

// ---- tasks ----------------------------------------------------------------

const task = (seed: Omit<Task, 'blocked_by' | 'github' | 'timer'> & Partial<Pick<Task, 'blocked_by' | 'github' | 'timer'>>): Task => ({
    blocked_by: null,
    github: null,
    timer: null,
    ...seed,
});

/** P-07 board (T1–T9) plus the P-05 tasks that show up in "Mi semana". */
export const TASKS: Task[] = [
    task({ key: 'T6', project_key: 'P-07', title: 'Manual de usuario', column: 'backlog', labels: ['Documentación'], assignees: [PEOPLE.leonardo], due_at: '2026-10-28', subtasks_done: 0, subtasks_total: 3, position: 1 }),
    task({ key: 'T9', project_key: 'P-07', title: 'Vista en celular para los asesores', column: 'backlog', labels: ['Diseño'], assignees: [PEOPLE.jhon], due_at: null, subtasks_done: 0, subtasks_total: 2, position: 2 }),
    task({
        key: 'T5',
        project_key: 'P-07',
        title: 'Pruebas con cartera real',
        column: 'todo',
        labels: ['Pruebas'],
        assignees: [PEOPLE.jhon],
        due_at: '2026-10-24',
        subtasks_done: 0,
        subtasks_total: 4,
        position: 1,
        github: { issue: { number: 39, state: 'open' }, pr: null, ci: null },
    }),
    task({ key: 'T8', project_key: 'P-07', title: 'Exportar a Excel lo filtrado', column: 'todo', labels: ['Frontend'], assignees: [PEOPLE.jhon], due_at: '2026-10-27', subtasks_done: 0, subtasks_total: 2, position: 2 }),
    task({
        key: 'T4',
        project_key: 'P-07',
        title: 'Consulta de cartera por asesor desde SAP B1',
        column: 'in_progress',
        labels: ['SAP', 'Backend'],
        assignees: [PEOPLE.jhon],
        due_at: '2026-10-18',
        subtasks_done: 2,
        subtasks_total: 5,
        position: 1,
        // No issue yet (approved Stage D decision): "Crear issue en GitHub" makes #57.
        // 01:12:40 at NOW_ISO.
        timer: { running: true, started_at: '2026-10-07T08:27:20-05:00', person_id: PEOPLE.jhon.id },
    }),
    task({
        key: 'T7',
        project_key: 'P-07',
        title: 'Total vencido por rango de días',
        column: 'in_progress',
        labels: ['SAP'],
        assignees: [PEOPLE.leonardo],
        due_at: '2026-10-21',
        subtasks_done: 0,
        subtasks_total: 3,
        position: 2,
        blocked_by: { id: 64, title: 'Acceso de lectura a la vista de cartera en SAP', area_name: 'Contabilidad' },
    }),
    task({
        key: 'T3',
        project_key: 'P-07',
        title: 'Endpoint de zonas',
        column: 'review',
        labels: ['Backend'],
        assignees: [PEOPLE.leonardo],
        due_at: '2026-10-10',
        subtasks_done: 4,
        subtasks_total: 4,
        position: 1,
        github: { issue: null, pr: { number: 41, state: 'review' }, ci: 'passing' },
    }),
    task({ key: 'T1', project_key: 'P-07', title: 'Diseñar filtros', column: 'done', labels: ['Diseño'], assignees: [PEOPLE.jhon], due_at: null, subtasks_done: 3, subtasks_total: 3, position: 1 }),
    task({
        key: 'T2',
        project_key: 'P-07',
        title: 'Consulta SAP por asesor',
        column: 'done',
        labels: ['SAP'],
        assignees: [PEOPLE.jhon],
        due_at: null,
        subtasks_done: 5,
        subtasks_total: 5,
        position: 2,
        github: { issue: null, pr: { number: 36, state: 'merged' }, ci: 'passing' },
    }),
    task({
        key: 'T2',
        project_key: 'P-05',
        title: 'Conectar la API de WooCommerce al Portafolio',
        column: 'in_progress',
        labels: ['Backend'],
        assignees: [PEOPLE.jhon],
        due_at: '2026-10-22',
        subtasks_done: 1,
        subtasks_total: 4,
        position: 1,
        blocked_by: { id: 57, title: 'API de WooCommerce: credenciales y documentación', area_name: 'Mercadeo' },
    }),
    task({
        key: 'T4',
        project_key: 'P-05',
        title: 'Fichas en la tienda',
        column: 'todo',
        labels: ['Frontend'],
        assignees: [PEOPLE.jhon],
        due_at: '2026-10-30',
        subtasks_done: 0,
        subtasks_total: 3,
        position: 1,
        blocked_by: { id: 65, title: 'Fotos y fichas de 20 productos de prueba', area_name: 'Diseño' },
    }),
];

// ---- requirements ---------------------------------------------------------

export const REQUIREMENTS: Requirement[] = [
    {
        id: 57,
        project_key: 'P-05',
        title: 'API de WooCommerce: credenciales y documentación',
        detail: 'Usuario de la API con permiso de lectura y escritura de productos, y el enlace a la documentación.',
        recipient: null,
        recipient_label: '[RESPONSABLE MERCADEO]',
        area_name: 'Mercadeo',
        company_id: DERIVADOS.id,
        due_date: '2026-10-01',
        status: 'overdue',
        reminders_sent: 3,
        blocks_task: { key: 'T2', title: 'Conectar la API de WooCommerce' },
        answered_at: null,
        answer: null,
        created_at: '2026-09-25T10:00:00-05:00',
    },
    {
        id: 64,
        project_key: 'P-07',
        title: 'Acceso de lectura a la vista de cartera en SAP',
        detail: 'Usuario de solo lectura para la vista de cartera, en pruebas y en producción.',
        recipient: null,
        recipient_label: '[RESPONSABLE CONTABILIDAD]',
        area_name: 'Contabilidad',
        company_id: SEGUREX.id,
        due_date: '2026-10-09',
        status: 'pending',
        reminders_sent: 1,
        blocks_task: { key: 'T7', title: 'Total vencido por rango de días' },
        answered_at: null,
        answer: null,
        created_at: '2026-10-02T10:00:00-05:00',
    },
    {
        id: 65,
        project_key: 'P-05',
        title: 'Fotos y fichas de 20 productos de prueba',
        detail: null,
        recipient: PEOPLE.camila,
        recipient_label: 'Camila Torres',
        area_name: 'Diseño',
        company_id: DERIVADOS.id,
        due_date: '2026-10-14',
        status: 'pending',
        reminders_sent: 0,
        blocks_task: { key: 'T4', title: 'Fichas en la tienda' },
        answered_at: null,
        answer: null,
        created_at: '2026-10-06T10:00:00-05:00',
    },
    {
        id: 58,
        project_key: 'P-07',
        title: 'Lista de zonas y asesores vigentes',
        detail: null,
        recipient: PEOPLE.diego,
        recipient_label: 'Diego Restrepo',
        area_name: 'Cartera',
        company_id: SEGUREX.id,
        due_date: '2026-10-05',
        status: 'answered',
        reminders_sent: 1,
        blocks_task: null,
        answered_at: '2026-10-05T11:00:00-05:00',
        answer: 'Adjunto la lista con 6 zonas y 14 asesores.',
        created_at: '2026-10-01T10:00:00-05:00',
    },
    {
        id: 55,
        project_key: 'P-07',
        title: 'Ejemplo del reporte de cartera que usan hoy',
        detail: null,
        recipient: PEOPLE.cartera,
        recipient_label: 'Gerencia de cartera',
        area_name: 'Cartera',
        company_id: SEGUREX.id,
        due_date: '2026-10-02',
        status: 'answered',
        reminders_sent: 0,
        blocks_task: null,
        answered_at: '2026-10-02T09:00:00-05:00',
        answer: 'Va el Excel que armamos cada lunes.',
        created_at: '2026-09-30T16:00:00-05:00',
    },
];

// ---- time & costs ---------------------------------------------------------

const T4_REF = { key: 'T4', title: 'Consulta de cartera' };

export const TIME_ENTRIES: TimeEntry[] = [
    { id: 501, date: '2026-10-07', person: PEOPLE.jhon, project_key: 'P-07', task: T4_REF, hours: 1.5, origin: 'timer', note: 'Conexión a la vista de cartera en pruebas' },
    { id: 500, date: '2026-10-06', person: PEOPLE.jhon, project_key: 'P-07', task: T4_REF, hours: 2, origin: 'telegram', note: 'Conexión con SAP' },
    {
        id: 499,
        date: '2026-10-06',
        person: PEOPLE.leonardo,
        project_key: 'P-07',
        task: { key: 'T3', title: 'Endpoint de zonas' },
        hours: 2,
        origin: 'timer',
        note: 'Endpoint de zonas y ajustes del PR #41',
    },
    { id: 498, date: '2026-10-05', person: PEOPLE.jhon, project_key: 'P-07', task: null, hours: 1, origin: 'manual', note: 'Reunión con Gerencia de cartera para validar filtros' },
    { id: 497, date: '2026-10-05', person: PEOPLE.jhon, project_key: 'P-07', task: T4_REF, hours: 1.5, origin: 'timer', note: 'Filtro por código de asesor' },
];

/** P-07 hours per person: this week and whole project. */
export const TIME_BY_PERSON_P07 = [
    { person: PEOPLE.jhon, week: 6, total: 62 },
    { person: PEOPLE.leonardo, week: 2, total: 30 },
];

/** Cumulative hours of P-07 for the chart: approved plan, actual and projection. */
export const HOURS_CURVE_P07 = {
    plan: [
        { date: '2026-09-30', value: 0 },
        { date: '2026-10-31', value: 120 },
    ],
    actual: [
        { date: '2026-09-30', value: 0 },
        { date: '2026-10-02', value: 24 },
        { date: '2026-10-05', value: 60 },
        { date: '2026-10-07', value: 92 },
    ],
    projection: [
        { date: '2026-10-07', value: 92 },
        { date: '2026-11-14', value: 134 },
    ],
};

export const COSTS_P07: ProjectCost[] = [
    {
        id: 1,
        type: 'team',
        concept: 'Horas del equipo',
        detail: '92 h con el costo por hora interno de cada persona · Jhon Castro 62 h · Leonardo Herrera 30 h',
        recurrence: 'computed',
        amount: 12_880_000,
        date: '2026-10-07',
        attachment: null,
        editable: false,
    },
    {
        id: 2,
        type: 'external_provider',
        concept: 'Consultor SAP B1: vista de cartera',
        detail: null,
        recurrence: 'once',
        amount: 804_000,
        date: '2026-09-22',
        attachment: { id: 11, name: 'factura-0193.pdf', size: 92_000, mime: 'application/pdf', url: '#' },
        editable: true,
    },
    {
        id: 3,
        type: 'license',
        concept: 'Componente de tablas con exportación a Excel',
        detail: null,
        recurrence: 'once',
        amount: 380_000,
        date: '2026-09-15',
        attachment: { id: 12, name: 'factura-licencia.pdf', size: 64_000, mime: 'application/pdf', url: '#' },
        editable: true,
    },
    {
        id: 4,
        type: 'hosting',
        concept: 'Vercel Pro, parte que usa este proyecto',
        detail: null,
        recurrence: 'monthly',
        amount: 90_000,
        date: '2026-10-01',
        attachment: { id: 13, name: 'recibo-vercel-oct.pdf', size: 40_000, mime: 'application/pdf', url: '#' },
        editable: true,
    },
    {
        id: 5,
        type: 'ai_usage',
        concept: 'Interacciones con IA del proyecto en octubre',
        detail: 'Se suma solo desde el registro de interacciones (ai_interactions); no se edita a mano',
        recurrence: 'monthly',
        amount: 46_000,
        date: '2026-10-01',
        attachment: null,
        editable: false,
    },
];

// ---- products -------------------------------------------------------------

const product = (seed: Omit<Product, 'monitoring_active' | 'url' | 'repository' | 'ai_model'> & Partial<Pick<Product, 'url' | 'repository' | 'ai_model'>>): Product => ({
    monitoring_active: false,
    url: null,
    repository: null,
    ai_model: null,
    ...seed,
});

export const PRODUCTS: Product[] = [
    product({
        slug: 'toma-pedidos',
        name: 'Toma Pedidos SAP',
        company_id: SEGUREX.id,
        description: 'Pedidos de los asesores directo a SAP Business One.',
        state: 'production',
        version: 'v2.4.0',
        stack: 'Laravel',
        host: 'GoDaddy',
        areas: ['Ventas'],
        owner: '[DUEÑO]',
        rating: { average: 4.2, count: 18 },
        hours_saved_per_month: 64,
        monthly_cost: 380_000,
        open_requests: 3,
    }),
    product({
        slug: 'portafolio',
        name: 'Portafolio de productos',
        company_id: DERIVADOS.id,
        description: 'Catálogo de productos con fichas técnicas y precios.',
        state: 'production',
        version: 'v1.8.2',
        stack: 'PHP',
        host: 'GoDaddy',
        areas: ['Compras', 'Ventas'],
        owner: '[DUEÑO]',
        rating: { average: 4.0, count: 9 },
        hours_saved_per_month: 38,
        monthly_cost: 210_000,
        open_requests: 3,
    }),
    product({
        slug: 'cuentas-por-pagar',
        name: 'Cuentas por pagar',
        company_id: SEGUREX.id,
        description: 'Radicación y seguimiento de facturas de proveedores.',
        state: 'pilot',
        version: 'v1.2.0',
        stack: 'Next.js',
        host: 'Vercel',
        areas: ['Contabilidad'],
        owner: '[DUEÑO]',
        rating: { average: 3.8, count: 6 },
        hours_saved_per_month: 22,
        monthly_cost: 90_000,
        open_requests: 0,
    }),
    product({
        slug: 'visualizador-cartera',
        name: 'Visualizador de cartera',
        company_id: SEGUREX.id,
        description: 'consulta en línea de la cartera por cliente, edad y asesor',
        state: 'production',
        version: 'v3.1.0',
        stack: 'Next.js',
        host: 'Vercel',
        areas: ['Cartera', 'Ventas'],
        owner: 'Gerencia de cartera',
        url: '[URL DEL VISUALIZADOR]',
        repository: '[REPOSITORIO]',
        rating: { average: 4.6, count: 12 },
        hours_saved_per_month: 86,
        monthly_cost: 120_000,
        open_requests: 2,
    }),
    product({
        slug: 'robotcamara',
        name: 'Cámaras',
        company_id: SEGUREX.id,
        description: 'Robot local que revisa y guarda las grabaciones de las cámaras.',
        state: 'development',
        version: 'v0.9.3',
        stack: 'Python',
        host: 'Equipo local',
        areas: ['[ÁREA]'],
        owner: '[DUEÑO]',
        rating: null,
        hours_saved_per_month: null,
        monthly_cost: 0,
        open_requests: 0,
    }),
];

export const VERSIONS_VISUALIZADOR: ProductVersion[] = [
    {
        tag: 'v3.1.0',
        date: '2026-09-22',
        summary: 'Totales por edad de cartera y carga más rápida al abrir.',
        request_keys: [
            { key: 'GIA-112', title: 'Totales por rango de días vencidos' },
            { key: 'GIA-115', title: 'Abrir el visualizador en menos de 3 segundos' },
        ],
        current: true,
    },
    {
        tag: 'v3.0.0',
        date: '2026-08-18',
        summary: 'Paso a Next.js en Vercel y entrada con la cuenta de Microsoft.',
        request_keys: [
            { key: 'GIA-095', title: 'Entrar con la cuenta de Microsoft de Segurex' },
            { key: 'GIA-099', title: 'Ver la cartera desde el celular' },
        ],
        current: false,
    },
    {
        tag: 'v2.6.1',
        date: '2026-07-10',
        summary: 'Corrección: clientes con NIT repetido salían dos veces.',
        request_keys: [{ key: 'GIA-088', title: 'Cliente duplicado en el listado de cartera' }],
        current: false,
    },
];

// ---- docs -----------------------------------------------------------------

const P07_OWNER = { kind: 'project' as const, key: 'P-07', name: 'Visualizador de cartera' };
const VIS_OWNER = { kind: 'product' as const, key: 'visualizador-cartera', name: 'Visualizador de cartera' };
const TP_OWNER = { kind: 'product' as const, key: 'toma-pedidos', name: 'Toma Pedidos SAP' };
const PORT_OWNER = { kind: 'product' as const, key: 'portafolio', name: 'Portafolio de productos' };
const P06_OWNER = { kind: 'project' as const, key: 'P-06', name: 'Toma Pedidos · descuentos' };

export const DOCS: DocSummary[] = [
    { id: 1, title: 'Ficha del proyecto', template: 'project_sheet', owner: P07_OWNER, version: 3, not_applicable: false, updated_at: '2026-09-30T10:00:00-05:00' },
    { id: 2, title: 'Requerimientos y criterios', template: 'requirements', owner: P07_OWNER, version: 5, not_applicable: false, updated_at: '2026-10-06T11:00:00-05:00' },
    { id: 3, title: 'Registro de decisiones', template: 'decisions', owner: P07_OWNER, version: 2, not_applicable: false, updated_at: '2026-10-01T15:00:00-05:00' },
    { id: 4, title: 'Manual de usuario', template: 'user_manual', owner: VIS_OWNER, version: 7, not_applicable: false, updated_at: '2026-10-07T09:52:00-05:00' },
    { id: 5, title: 'Manual técnico y de despliegue', template: 'technical_manual', owner: VIS_OWNER, version: 4, not_applicable: false, updated_at: '2026-09-22T10:00:00-05:00' },
    { id: 6, title: 'Prompts y modelos', template: 'prompts', owner: VIS_OWNER, version: 0, not_applicable: true, updated_at: '2026-08-18T10:00:00-05:00' },
    { id: 7, title: 'Ficha del proyecto', template: 'project_sheet', owner: TP_OWNER, version: 6, not_applicable: false, updated_at: '2026-09-02T10:00:00-05:00' },
    { id: 8, title: 'Arquitectura y datos', template: 'architecture', owner: TP_OWNER, version: 3, not_applicable: false, updated_at: '2026-08-20T10:00:00-05:00' },
    { id: 9, title: 'Manual de usuario', template: 'user_manual', owner: TP_OWNER, version: 9, not_applicable: false, updated_at: '2026-09-28T10:00:00-05:00' },
    { id: 10, title: 'Ficha del proyecto', template: 'project_sheet', owner: PORT_OWNER, version: 4, not_applicable: false, updated_at: '2026-09-10T10:00:00-05:00' },
    { id: 11, title: 'Manual de usuario', template: 'user_manual', owner: PORT_OWNER, version: 5, not_applicable: false, updated_at: '2026-09-24T10:00:00-05:00' },
    { id: 12, title: 'Manual técnico y de despliegue', template: 'technical_manual', owner: PORT_OWNER, version: 2, not_applicable: false, updated_at: '2026-08-12T10:00:00-05:00' },
    { id: 13, title: 'Arquitectura y datos', template: 'architecture', owner: PORT_OWNER, version: 2, not_applicable: false, updated_at: '2026-08-12T10:00:00-05:00' },
    { id: 14, title: 'Ficha del proyecto', template: 'project_sheet', owner: P06_OWNER, version: 2, not_applicable: false, updated_at: '2026-09-02T10:00:00-05:00' },
    { id: 15, title: 'Requerimientos y criterios', template: 'requirements', owner: P06_OWNER, version: 3, not_applicable: false, updated_at: '2026-09-29T10:00:00-05:00' },
    { id: 16, title: 'Registro de decisiones', template: 'decisions', owner: P06_OWNER, version: 1, not_applicable: false, updated_at: '2026-09-05T10:00:00-05:00' },
];

export const MANUAL_V7 = `# Visualizador de cartera

Muestra lo que cada cliente le debe a Segurex,
por edad de la deuda y por asesor. Los datos
llegan de SAP B1 cada hora.

## Cómo entrar
1. Abre el Visualizador desde el Portal, en **Mis apps**.
2. Entra con tu cuenta de Microsoft de Segurex.

## Ver la cartera por asesor
Abre **Filtros**, elige el asesor y, si quieres,
la zona. El total se recalcula solo.

| Columna     | Qué significa                  |
|-------------|--------------------------------|
| Al día      | Facturas que aún no vencen     |
| 1 a 30 días | Vencidas hace menos de un mes  |
| 31 a 90     | Requieren llamada del asesor   |
| Más de 90   | Pasan a cobro jurídico         |

¿Falta algo? Usa el botón [Pedir mejora](#) dentro
de la app.
`;

export const DOC_MANUAL: Doc = {
    ...DOCS[3]!,
    markdown: MANUAL_V7,
    updated_by: PEOPLE.jhon,
    visibility: 'company',
    repository_path: 'docs/manual-de-usuario.md',
};

export const DOC_VERSIONS_MANUAL: DocVersion[] = [
    { version: 7, summary: 'Agrega el filtro por zona, el rango de 31 a 90 días y el botón Pedir mejora.', author: PEOPLE.jhon, created_at: '2026-10-07T09:52:00-05:00', current: true },
    { version: 6, summary: 'Tabla de columnas por edad de la deuda.', author: PEOPLE.jhon, created_at: '2026-09-30T10:00:00-05:00', current: false },
    { version: 5, summary: 'Ajustes para la versión 3.1.0 de la app.', author: PEOPLE.diego, created_at: '2026-09-22T10:00:00-05:00', current: false },
    { version: 4, summary: 'Entrada con la cuenta de Microsoft.', author: PEOPLE.jhon, created_at: '2026-08-18T10:00:00-05:00', current: false },
    { version: 3, summary: 'Preguntas frecuentes.', author: PEOPLE.cartera, created_at: '2026-07-10T10:00:00-05:00', current: false },
    { version: 2, summary: 'Capturas de pantalla.', author: PEOPLE.jhon, created_at: '2026-07-02T10:00:00-05:00', current: false },
    { version: 1, summary: 'Primera versión del manual.', author: PEOPLE.jhon, created_at: '2026-06-28T10:00:00-05:00', current: false },
];

/** Manual de usuario, version 6 compared with 7 (lines 10–27). */
export const DIFF_MANUAL_6_7: DiffLine[] = [
    { kind: 'same', old_line: 10, new_line: 10, text: '## Cómo entrar' },
    { kind: 'same', old_line: 11, new_line: 11, text: '1. Abre el Visualizador desde el Portal, en **Mis apps**.' },
    { kind: 'same', old_line: 12, new_line: 12, text: '2. Entra con tu cuenta de Microsoft de Segurex.' },
    { kind: 'same', old_line: 13, new_line: 13, text: '' },
    { kind: 'remove', old_line: 14, new_line: null, text: '## Ver la cartera de un asesor' },
    { kind: 'add', old_line: null, new_line: 14, text: '## Ver la cartera por asesor' },
    { kind: 'remove', old_line: 15, new_line: null, text: 'Abre **Filtros** y elige el asesor.' },
    { kind: 'add', old_line: null, new_line: 15, text: 'Abre **Filtros**, elige el asesor y, si quieres,' },
    { kind: 'add', old_line: null, new_line: 16, text: 'la zona. El total se recalcula solo.' },
    { kind: 'same', old_line: 16, new_line: 17, text: '' },
    { kind: 'same', old_line: 17, new_line: 18, text: '| Columna     | Qué significa                  |' },
    { kind: 'same', old_line: 18, new_line: 19, text: '|-------------|--------------------------------|' },
    { kind: 'same', old_line: 19, new_line: 20, text: '| Al día      | Facturas que aún no vencen     |' },
    { kind: 'same', old_line: 20, new_line: 21, text: '| 1 a 30 días | Vencidas hace menos de un mes  |' },
    { kind: 'add', old_line: null, new_line: 22, text: '| 31 a 90     | Requieren llamada del asesor   |' },
    { kind: 'same', old_line: 21, new_line: 23, text: '| Más de 90   | Pasan a cobro jurídico         |' },
    { kind: 'same', old_line: 22, new_line: 24, text: '' },
    { kind: 'add', old_line: null, new_line: 25, text: '¿Falta algo? Usa el botón [Pedir mejora](#) dentro' },
    { kind: 'add', old_line: null, new_line: 26, text: 'de la app.' },
    { kind: 'same', old_line: 23, new_line: 27, text: '' },
];

// ---- vault ----------------------------------------------------------------

const vault = (seed: Omit<VaultItem, 'username' | 'usage_url' | 'notes' | 'expiry_note'> & Partial<Pick<VaultItem, 'username' | 'usage_url' | 'notes' | 'expiry_note'>>): VaultItem => ({
    username: null,
    usage_url: null,
    notes: null,
    expiry_note: null,
    ...seed,
});

export const VAULT_ITEMS: VaultItem[] = [
    vault({
        id: 1,
        name: 'DEEPSEEK_API_KEY',
        product: 'Gestor TI',
        environment: 'production',
        type: 'api_key',
        username: 'jhon.castro@segurex.com',
        usage_url: 'api.deepseek.com',
        expires_at: '2026-10-19',
        rotation_days: 90,
        notes: 'La usa el Asistente del Gestor TI. Al rotarla, cámbiala también en las variables de entorno del proyecto en Vercel y prueba una pregunta en el Asistente.',
    }),
    vault({ id: 2, name: 'cPanel Toma Pedidos', product: 'Toma Pedidos SAP', environment: 'production', type: 'user_password', expires_at: null, rotation_days: 180 }),
    vault({ id: 3, name: 'SAP B1 Service Layer', product: 'Toma Pedidos SAP', environment: 'testing', type: 'user_password', expires_at: null, rotation_days: 90 }),
    vault({ id: 4, name: 'Base de datos de cartera', product: 'Visualizador de cartera', environment: 'production', type: 'connection_string', expires_at: null, rotation_days: 180 }),
    vault({ id: 5, name: 'Token de Vercel', product: 'Visualizador de cartera', environment: 'production', type: 'token', expires_at: '2027-01-03', rotation_days: 365 }),
    vault({ id: 6, name: 'FTP de GoDaddy', product: 'Portafolio de productos', environment: 'production', type: 'user_password', expires_at: null, rotation_days: 180 }),
    vault({ id: 7, name: 'API de WooCommerce', product: 'Portafolio de productos', environment: 'testing', type: 'api_key', expires_at: null, expiry_note: 'Falta recibirla', rotation_days: 90 }),
    vault({ id: 8, name: 'Cuenta de SAP para robots', product: 'Cuentas por pagar', environment: 'production', type: 'user_password', expires_at: null, rotation_days: 90 }),
    vault({ id: 9, name: 'Cámaras RTSP', product: 'Cámaras', environment: 'testing', type: 'user_password', expires_at: null, rotation_days: null }),
];

/** Sample reveal payload (fake value) for the open-vault mock. */
export const VAULT_REVEALED_SAMPLE: VaultRevealed = {
    item_id: 1,
    value: 'sk-muestra-9c41f0a7d2e84b6b0e31',
    expires_in_seconds: 30,
};

export const VAULT_LOG: VaultAccessLog[] = [
    { id: 5, person: PEOPLE.jhon, action: 'copied', item_name: 'DEEPSEEK_API_KEY', at: '2026-10-07T09:41:00-05:00', from: 'Edge en Windows · red de Segurex' },
    { id: 4, person: PEOPLE.jhon, action: 'viewed', item_name: 'DEEPSEEK_API_KEY', at: '2026-10-07T09:40:00-05:00', from: 'Edge en Windows · red de Segurex' },
    { id: 3, person: PEOPLE.juan, action: 'blocked', item_name: 'DEEPSEEK_API_KEY', at: '2026-10-06T16:12:00-05:00', from: 'Chrome en Android · fuera de la red' },
    { id: 2, person: PEOPLE.jhon, action: 'changed', item_name: 'DEEPSEEK_API_KEY', at: '2026-07-21T10:00:00-05:00', from: 'Edge en Windows · red de Segurex' },
    { id: 1, person: PEOPLE.jhon, action: 'created', item_name: 'DEEPSEEK_API_KEY', at: '2026-04-21T10:00:00-05:00', from: 'Edge en Windows · red de Segurex' },
];

// ---- areas & value --------------------------------------------------------

export const AREA_SUMMARIES_SEGUREX: AreaSummary[] = [
    { area: AREAS.ventas, owner: '[RESPONSABLE]', semaphore: 'attention', reason: 'GIA-148 sin primera respuesta hace 1 día', products_in_use: 1, projects_in_progress: 1, backlog: 2, hours_saved_per_month: 64 },
    { area: AREAS.cartera, owner: 'Gerencia de cartera', semaphore: 'late', reason: 'P-07 va 14 días tarde y pasó el 80 % del costo', products_in_use: 1, projects_in_progress: 1, backlog: 1, hours_saved_per_month: 40 },
    { area: AREAS.contabilidad, owner: '[RESPONSABLE]', semaphore: 'ok', reason: 'GIA-110 cierre de mes, en backlog', products_in_use: 1, projects_in_progress: 0, backlog: 1, hours_saved_per_month: 22 },
    { area: AREAS.gerencia, owner: '[RESPONSABLE]', semaphore: 'ok', reason: 'GIA-116 aviso de cámaras, en backlog', products_in_use: 1, projects_in_progress: 0, backlog: 1, hours_saved_per_month: 6 },
    { area: AREAS.comprasS, owner: '[RESPONSABLE]', semaphore: 'ok', reason: null, products_in_use: 0, projects_in_progress: 0, backlog: 0, hours_saved_per_month: null },
    { area: AREAS.tesoreriaS, owner: '[RESPONSABLE]', semaphore: 'ok', reason: null, products_in_use: 0, projects_in_progress: 0, backlog: 0, hours_saved_per_month: null },
    { area: AREAS.produccionS, owner: '[RESPONSABLE]', semaphore: 'ok', reason: null, products_in_use: 0, projects_in_progress: 0, backlog: 0, hours_saved_per_month: null },
];

export const VALUE_METRICS: ValueMetrics = {
    period: { from: '2026-04-01', to: '2026-09-30', label: 'Últimos 6 meses' },
    updated_at: '2026-10-07T06:00:00-05:00',
    hours_saved_per_month: { current: 210, delta: 24, delta_label: 'frente a agosto' },
    satisfaction: { average: 4.2, ratings: 45, apps: 4 },
    lead_time_days: { current: 34, delta: -6 },
    monthly_cost: 800_000,
    hours_series: [
        { month: '2026-04', value: 96 },
        { month: '2026-05', value: 118 },
        { month: '2026-06', value: 131 },
        { month: '2026-07', value: 152 },
        { month: '2026-08', value: 186 },
        { month: '2026-09', value: 210 },
    ],
    hours_by_area: [
        { area: 'Cartera', hours: 86 },
        { area: 'Ventas', hours: 64 },
        { area: 'Compras', hours: 38 },
        { area: 'Contabilidad', hours: 22 },
    ],
    requests_by_status: [
        { status: 'in_production', count: 9 },
        { status: 'backlog', count: 6 },
        { status: 'rejected', count: 4 },
        { status: 'received', count: 3 },
        { status: 'closed', count: 3 },
        { status: 'pending_approval', count: 2 },
        { status: 'in_development', count: 2 },
        { status: 'in_testing', count: 1 },
    ],
    roi: [
        { product: 'Visualizador de cartera', hours_per_month: 86, total_cost: 30_400_000, saving_per_month: 3_750_000, payback_months: 8.1 },
        { product: 'Toma Pedidos SAP', hours_per_month: 64, total_cost: 22_000_000, saving_per_month: 2_500_000, payback_months: 8.8 },
        { product: 'Cuentas por pagar', hours_per_month: 22, total_cost: 9_000_000, saving_per_month: 900_000, payback_months: 10 },
        { product: 'Portafolio de productos', hours_per_month: 38, total_cost: 18_500_000, saving_per_month: 1_500_000, payback_months: 12.3 },
    ],
    hour_value: 45_000,
};

// ---- helpers --------------------------------------------------------------

function required<T>(value: T | undefined, what: string): T {
    if (value === undefined) throw new Error(`Mock fixture not found: ${what}`);
    return value;
}

export const requestByKey = (key: string): RequestSummary => required(REQUESTS.find((r) => r.key === key), key);

export const projectByKey = (key: string): Project => required(PROJECTS.find((p) => p.key === key), key);

export const tasksOf = (projectKey: string): Task[] => TASKS.filter((t) => t.project_key === projectKey);

export const taskByKey = (projectKey: string, key: string): Task =>
    required(TASKS.find((t) => t.project_key === projectKey && t.key === key), `${projectKey} ${key}`);

export const requirementsOf = (projectKey: string): Requirement[] => REQUIREMENTS.filter((r) => r.project_key === projectKey);

export const productBySlug = (slug: string): Product => required(PRODUCTS.find((p) => p.slug === slug), slug);

export const requestsOf = (requesterId: number): RequestSummary[] => REQUESTS.filter((r) => r.requester.id === requesterId);

/** Requests without a first response yet, most overdue first. */
export const unansweredRequests = (): RequestSummary[] =>
    REQUESTS.filter((r) => r.first_response.answered_at === null).sort((a, b) => a.first_response.due_at.localeCompare(b.first_response.due_at));

/** Ranked backlog (rank ascending). */
export const backlog = (): RequestSummary[] =>
    REQUESTS.filter((r) => r.status === 'backlog').sort((a, b) => (a.rank ?? 99) - (b.rank ?? 99));
