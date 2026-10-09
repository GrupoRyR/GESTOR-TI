/**
 * Mocks for the IT triage pages (boards B5–B10): Jhon Castro (Líder IA,
 * approver, "Todas"), Leonardo Herrera (Súper Admin, no approval) and Edward
 * Ramanan (approver only, requester mode). Entities come from ai-fixtures;
 * only triage-specific copy (history, agenda, week items) lives here.
 */
import type { AreaSummary, PersonRef, RequestDetail, RequestSummary, TimelineEvent } from '@/types/ai';
import type {
    ApprovalDetail,
    ApprovalsIndexProps,
    ApprovalsShowProps,
    AreaDetail,
    AreaOption,
    AreasIndexProps,
    BacklogIndexProps,
    InboxIndexProps,
    InboxQuick,
    ItShowProps,
    TeamMember,
    WeekIndexProps,
    WeekItem,
} from '@/types/pages/ai-triage';
import type { RequestsShowProps } from '@/types/pages/ai-requester';
import type { PageProps, Paginated } from '@/types/shared';
import {
    AREA_SUMMARIES_SEGUREX,
    AREAS,
    backlog,
    ESTIMATES,
    NOW_ISO,
    PEOPLE,
    productBySlug,
    projectByKey,
    REQUEST_GIA_148,
    requestByKey,
    REQUESTS,
    TODAY,
    WIP_LIMIT,
} from './ai-fixtures';
import { DERIVADOS, SEGUREX, sharedFor, type MockVariant } from './shared';

// ---- shared pieces ------------------------------------------------------------

/** Internal hourly rate used for estimates (invented; only for `ai.costs.view`). */
const HOURLY_RATE = 160_000;

const TEAM: TeamMember[] = [
    { person: PEOPLE.jhon, hourly_rate: HOURLY_RATE },
    { person: PEOPLE.leonardo, hourly_rate: HOURLY_RATE },
];

const APPROVERS: PersonRef[] = [PEOPLE.jhon, { ...PEOPLE.edward, job_title: 'Gerencia' }];

const ACTION_DATA = { team: TEAM, approvers: APPROVERS };

const WIP = { in_development: 3, limit: WIP_LIMIT };

/** Business days to answer, as configured for the mocks (fixture deadlines were set with 1). */
const FIRST_RESPONSE_DAYS = 1;

const ALL_AREAS: AreaOption[] = Object.values(AREAS).map((area) => ({ id: area.id, name: area.name, company_id: area.company_id }));

function paginate<T>(rows: T[], perPage = 20): Paginated<T> {
    return {
        data: rows,
        links: [
            { url: null, label: '&laquo; Anterior', active: false },
            { url: '#ai.inbox.index?page=1', label: '1', active: true },
            { url: null, label: 'Siguiente &raquo;', active: false },
        ],
        meta: { current_page: 1, last_page: 1, per_page: perPage, total: rows.length },
    };
}

// ---- Inbox --------------------------------------------------------------------

const unanswered = (request: RequestSummary) => request.first_response.answered_at === null && request.status === 'received';

/** Open requests in the inbox: unanswered by due date, then waiting on the requester, then on approval. */
const INBOX: RequestSummary[] = [
    ...REQUESTS.filter(unanswered).sort((a, b) => a.first_response.due_at.localeCompare(b.first_response.due_at)),
    ...REQUESTS.filter((request) => request.status === 'needs_info').sort((a, b) => b.created_at.localeCompare(a.created_at)),
    ...REQUESTS.filter((request) => request.status === 'pending_approval').sort((a, b) => b.created_at.localeCompare(a.created_at)),
];

const MATCHES: Record<InboxQuick, (request: RequestSummary) => boolean> = {
    unanswered,
    needs_info: (request) => request.status === 'needs_info',
    pending_approval: (request) => request.status === 'pending_approval',
    all: () => true,
};

function countsOf(rows: RequestSummary[]): Record<InboxQuick, number> {
    return {
        unanswered: rows.filter(MATCHES.unanswered).length,
        needs_info: rows.filter(MATCHES.needs_info).length,
        pending_approval: rows.filter(MATCHES.pending_approval).length,
        all: rows.length,
    };
}

type InboxPage = PageProps<InboxIndexProps>;

function inbox(quick: InboxQuick, companyId: number | null = null, user: 'jhon' | 'leonardo' = 'jhon'): InboxPage {
    const scope = companyId === null ? INBOX : INBOX.filter((request) => request.company_id === companyId);
    return {
        ...sharedFor(user, { currentCompanyId: companyId }),
        ...ACTION_DATA,
        requests: paginate(scope.filter(MATCHES[quick])),
        filters: { quick, area_id: null, company_id: null },
        counts: countsOf(scope),
        areas: companyId === null ? ALL_AREAS : ALL_AREAS.filter((area) => area.company_id === companyId),
        first_response_business_days: FIRST_RESPONSE_DAYS,
        now: NOW_ISO,
        // What the `merge_candidates` partial reload returns for GIA-148 ("Unir con otra").
        merge_candidates: [requestByKey('GIA-121'), requestByKey('GIA-139')],
        last_answered: { key: 'GIA-150', created_at: '2026-10-06T16:00:00-05:00', answered_at: '2026-10-07T09:40:00-05:00' },
    };
}

const inboxAll = inbox('all');

const inboxEmpty: InboxPage = {
    ...inbox('unanswered'),
    requests: paginate([]),
    counts: { unanswered: 0, needs_info: 2, pending_approval: 2, all: 4 },
};

const inboxFilteredEmpty: InboxPage = {
    ...inbox('needs_info'),
    requests: paginate([]),
    filters: { quick: 'needs_info', area_id: AREAS.cartera.id, company_id: null },
};

// ---- Request detail (IT) -----------------------------------------------------

type ShowPage = PageProps<RequestsShowProps>;

const showGia148: PageProps<ItShowProps> = {
    ...sharedFor('jhon'),
    ...ACTION_DATA,
    view: 'it',
    request: REQUEST_GIA_148,
    evaluation: {
        impact: { hours_per_week: 6, people: 8, money: null, risk: 'high' },
        size: 'M',
        urgency: 'high',
        priority_suggested: 77,
        priority_final: null,
    },
    duplicates: [requestByKey('GIA-121')],
    merge_candidates: [requestByKey('GIA-121'), requestByKey('GIA-139')],
    area_hour_value: null,
    first_response: REQUEST_GIA_148.first_response,
    first_response_business_days: FIRST_RESPONSE_DAYS,
    now: NOW_ISO,
};

const GIA_151: RequestDetail = {
    ...requestByKey('GIA-151'),
    answers: {
        problem: 'En el Visualizador filtro la cartera por asesor, pero para mandarla al asesor tengo que copiar la tabla a mano en Excel.',
        current_process: 'Copio la tabla filtrada, la pego en Excel y arreglo el formato de los valores antes de enviarla.',
        expected_result: 'Un botón “Exportar a Excel” que baje exactamente lo que estoy viendo con los filtros puestos.',
        success_criteria: 'Mando la cartera de un asesor en menos de un minuto.',
        systems: ['Visualizador de cartera', 'Excel'],
        systems_notes: null,
        deadline: null,
        deadline_reason: null,
    },
    attachments: [],
    criteria: [],
    timeline: [{ id: 51, kind: 'status', family: 'gray', title: 'Recibida', quote: null, detail: null, actor: null, at: '2026-10-07T08:50:00-05:00' }],
    comments: [],
    internal_notes: [],
    estimate: null,
    approval: null,
    rating: null,
    followers_count: 2,
};

const showGia151: PageProps<ItShowProps> = {
    ...sharedFor('leonardo'),
    ...ACTION_DATA,
    view: 'it',
    request: GIA_151,
    evaluation: { impact: { hours_per_week: 3, people: 2, money: null, risk: 'low' }, size: null, urgency: 'medium', priority_suggested: null, priority_final: null },
    duplicates: [],
    area_hour_value: 45_000,
    first_response: GIA_151.first_response,
    first_response_business_days: FIRST_RESPONSE_DAYS,
    now: NOW_ISO,
};

// ---- Approvals ----------------------------------------------------------------

const HISTORY_GIA_144: TimelineEvent[] = [
    {
        id: 64,
        kind: 'notification',
        family: 'amber',
        title: 'Telegram enviado a Edward Ramanan',
        quote: null,
        detail: 'sin respuesta todavía',
        actor: null,
        at: '2026-10-07T08:41:00-05:00',
    },
    {
        id: 63,
        kind: 'approval',
        family: 'amber',
        title: 'Leonardo Herrera la mandó a aprobación',
        quote: null,
        detail: 'aprobadores: Jhon Castro y Edward Ramanan',
        actor: 'Leonardo Herrera',
        at: '2026-10-07T08:40:00-05:00',
    },
    { id: 62, kind: 'estimate', family: 'gray', title: 'Jhon Castro la evaluó', quote: null, detail: 'talla L, prioridad 71', actor: 'Jhon Castro', at: '2026-10-06T10:00:00-05:00' },
    { id: 61, kind: 'status', family: 'gray', title: 'Recibida', quote: null, detail: 'respondida el mismo día', actor: null, at: '2026-10-02T09:00:00-05:00' },
];

const HISTORY_GIA_146: TimelineEvent[] = [
    { id: 74, kind: 'approval', family: 'amber', title: 'Jhon Castro la mandó a aprobación', quote: null, detail: 'aprobadores: Jhon Castro y Edward Ramanan', actor: 'Jhon Castro', at: '2026-10-02T10:00:00-05:00' },
    { id: 73, kind: 'estimate', family: 'gray', title: 'Jhon Castro la evaluó', quote: null, detail: 'talla M, prioridad 68', actor: 'Jhon Castro', at: '2026-09-30T16:00:00-05:00' },
    { id: 72, kind: 'question', family: 'amber', title: 'TI preguntó', quote: '¿El PDF lleva el logo de Derivados, el del proveedor o los dos?', detail: 'respondida el 30 sep', actor: 'Jhon Castro', at: '2026-09-29T11:00:00-05:00' },
    { id: 71, kind: 'status', family: 'gray', title: 'Recibida', quote: null, detail: 'respondida en 1 día', actor: null, at: '2026-09-26T10:00:00-05:00' },
];

function approvalDetail(key: 'GIA-144' | 'GIA-146'): ApprovalDetail {
    const request = requestByKey(key);
    const is144 = key === 'GIA-144';
    return {
        request: {
            ...request,
            problem: is144
                ? 'Tesorería concilia a mano los extractos del banco contra SAP B1 cada semana. Toma horas y deja errores de digitación.'
                : 'Para mandar la ficha técnica de un producto a un cliente hay que armar un PDF a mano con la foto, las medidas y los logos.',
        },
        estimate: ESTIMATES[key]!,
        approval: {
            decision: 'pending',
            approvers: APPROVERS,
            decided_by: null,
            decided_at: null,
            comment: null,
            requested_by: is144 ? PEOPLE.leonardo : PEOPLE.jhon,
            requested_at: is144 ? '2026-10-07T08:40:00-05:00' : '2026-10-02T10:00:00-05:00',
            is_reapproval: false,
        },
        evaluation: is144
            ? { by: PEOPLE.jhon, at: '2026-10-06T10:00:00-05:00', urgency: 'medium', size: 'L', priority: 71 }
            : { by: PEOPLE.jhon, at: '2026-09-30T16:00:00-05:00', urgency: 'high', size: 'M', priority: 68 },
        hours_saved_per_week: (request.hours_per_week ?? 0) * (request.people ?? 0),
        // Tesorería (Derivados) has no hour value yet; Compras does (invented 9,4 months).
        payback_months: is144 ? null : 9.4,
        history: is144 ? HISTORY_GIA_144 : HISTORY_GIA_146,
    };
}

const PENDING = (['GIA-144', 'GIA-146'] as const).map((key) => {
    const detail = approvalDetail(key);
    return {
        key,
        title: detail.request.title,
        company_id: detail.request.company_id,
        requested_at: detail.approval.requested_at,
        hours_total: detail.estimate.hours_total,
        total_cost: detail.estimate.total_cost,
        due_date: detail.estimate.due_date,
    };
});

type ApprovalsPage = PageProps<ApprovalsIndexProps>;

const approvalsJhon: ApprovalsPage = { ...sharedFor('jhon'), pending: PENDING, selected: approvalDetail('GIA-144'), wip: WIP, now: NOW_ISO };

type ApprovalPage = PageProps<ApprovalsShowProps>;

const approvalEdward: ApprovalPage = {
    ...sharedFor('edward'),
    approval: approvalDetail('GIA-144'),
    from_telegram: true,
    wip: WIP,
    decision: null,
    now: NOW_ISO,
};

// ---- Backlog ------------------------------------------------------------------

type BacklogPage = PageProps<BacklogIndexProps>;

const backlogManual: BacklogPage = {
    ...sharedFor('jhon'),
    items: backlog(),
    manual_order: {
        by: PEOPLE.jhon,
        at: '2026-10-07T08:40:00-05:00',
        moved_key: 'GIA-102',
        above_key: 'GIA-121',
        note: 'Compras lo necesita para el inventario de fin de año.',
    },
    wip: WIP,
    filters: { area_id: null, company_id: null },
    areas: ALL_AREAS,
    now: NOW_ISO,
};

/** Suggested order: final priority, highest first (GIA-102 back at its suggested 58). */
const SUGGESTED = backlog()
    .map((item) => (item.key === 'GIA-102' ? { ...item, priority_final: item.priority_suggested } : item))
    .sort((a, b) => (b.priority_final ?? 0) - (a.priority_final ?? 0))
    .map((item, index) => ({ ...item, rank: index + 1 }));

// ---- Areas --------------------------------------------------------------------

const product = (slug: string) => {
    const { name, version, stack, host, owner, state, monitoring_active } = productBySlug(slug);
    return { slug, name, version, stack, host, owner, state, monitoring_active };
};

const areaProject = (key: string, note: string | null, note_level: AreaDetail['projects'][number]['note_level']) => {
    const project = projectByKey(key);
    return {
        key,
        name: project.name,
        request_key: project.request_keys[0] ?? '',
        status: project.status,
        hours: project.budget.hours,
        cost: project.budget.cost,
        note,
        note_level,
    };
};

const summaryOf = (summaries: AreaSummary[], id: number) => summaries.find((summary) => summary.area.id === id)!;

const VENTAS_DETAIL: AreaDetail = {
    summary: summaryOf(AREA_SUMMARIES_SEGUREX, AREAS.ventas.id),
    products: [product('toma-pedidos')],
    projects: [areaProject('P-06', 'Falta validar 2 de 4 criterios con Paola', 'attention')],
    backlog: [requestByKey('GIA-121'), requestByKey('GIA-113')],
    inbox_keys: ['GIA-148', 'GIA-149', 'GIA-147'],
};

const CARTERA_DETAIL: AreaDetail = {
    summary: summaryOf(AREA_SUMMARIES_SEGUREX, AREAS.cartera.id),
    products: [product('visualizador-cartera')],
    projects: [areaProject('P-07', 'Va 14 días tarde y pasó el 80 % del costo', 'late')],
    backlog: [requestByKey('GIA-104')],
    inbox_keys: ['GIA-151'],
};

/** Derivados areas (counts follow the fixtures; reasons are mock copy). */
const AREA_SUMMARIES_DERIVADOS: AreaSummary[] = [
    { area: AREAS.compras, owner: '[RESPONSABLE]', semaphore: 'attention', reason: 'GIA-150 espera la respuesta de Andrés', products_in_use: 1, projects_in_progress: 0, backlog: 1, hours_saved_per_month: 38 },
    { area: AREAS.ventasD, owner: '[RESPONSABLE]', semaphore: 'ok', reason: 'P-05 WooCommerce, en desarrollo', products_in_use: 1, projects_in_progress: 1, backlog: 0, hours_saved_per_month: null },
    { area: AREAS.tesoreria, owner: '[RESPONSABLE]', semaphore: 'attention', reason: 'GIA-144 espera aprobación', products_in_use: 0, projects_in_progress: 0, backlog: 0, hours_saved_per_month: null },
    { area: AREAS.mercadeo, owner: '[RESPONSABLE MERCADEO]', semaphore: 'late', reason: 'Requerimiento de la API de WooCommerce vencido hace 6 días', products_in_use: 0, projects_in_progress: 0, backlog: 0, hours_saved_per_month: null },
    { area: AREAS.diseno, owner: '[RESPONSABLE]', semaphore: 'ok', reason: 'GIA-152 fotos en lote, en la bandeja', products_in_use: 0, projects_in_progress: 0, backlog: 0, hours_saved_per_month: null },
    { area: AREAS.produccion, owner: '[RESPONSABLE]', semaphore: 'ok', reason: 'GIA-108 tablero de órdenes, en backlog', products_in_use: 0, projects_in_progress: 0, backlog: 1, hours_saved_per_month: null },
];

type AreasPage = PageProps<AreasIndexProps>;

const AREA_COMPANIES = [SEGUREX, DERIVADOS].map(({ id, short_name }) => ({ id, short_name }));

const areasPage = (selected: AreaDetail | null): AreasPage => ({
    ...sharedFor('jhon'),
    company_id: SEGUREX.id,
    area_companies: AREA_COMPANIES,
    areas: AREA_SUMMARIES_SEGUREX,
    selected_area: selected,
});

// ---- My week ------------------------------------------------------------------

const WEEK_ITEMS: WeekItem[] = [
    {
        id: 'fr-148',
        column: 'due',
        company_id: SEGUREX.id,
        ref_label: 'GIA-148',
        title: 'Primera respuesta a Paola Ríos',
        person: 'Jhon Castro',
        due_at: requestByKey('GIA-148').first_response.due_at,
        since: null,
        reason: null,
        target: { type: 'request', key: 'GIA-148' },
    },
    {
        id: 'fr-149',
        column: 'due',
        company_id: SEGUREX.id,
        ref_label: 'GIA-149',
        title: 'Primera respuesta a Juan Pérez',
        person: 'Jhon Castro',
        due_at: requestByKey('GIA-149').first_response.due_at,
        since: null,
        reason: null,
        target: { type: 'request', key: 'GIA-149' },
    },
    {
        id: 'task-p07-t3',
        column: 'due',
        company_id: SEGUREX.id,
        ref_label: 'P-07 · T3',
        title: 'Endpoint de zonas: revisar PR #41',
        person: 'Leonardo Herrera revisa',
        due_at: '2026-10-08',
        since: null,
        reason: null,
        target: { type: 'task', project_key: 'P-07', task_key: 'T3' },
    },
    {
        id: 'project-p07',
        column: 'due',
        company_id: SEGUREX.id,
        ref_label: 'P-07',
        title: 'Replanear: la fecha proyectada es 14 nov',
        person: 'Jhon Castro',
        due_at: '2026-10-09',
        since: null,
        reason: null,
        target: { type: 'project', key: 'P-07' },
    },
    {
        id: 'blocked-p05-t2',
        column: 'blocked',
        company_id: DERIVADOS.id,
        ref_label: 'P-05 · T2',
        title: 'Conectar la API de WooCommerce al Portafolio',
        person: 'Jhon Castro',
        due_at: null,
        since: '2026-10-01T10:00:00-05:00',
        reason: 'Espera el requerimiento “API de WooCommerce” a Mercadeo',
        target: { type: 'requirement', id: 57, area_name: 'Mercadeo' },
    },
    {
        id: 'wait-p06',
        column: 'waiting',
        company_id: SEGUREX.id,
        ref_label: 'P-06',
        title: 'Validar descuentos por volumen (faltan 2 de 4 criterios)',
        person: 'Paola Ríos',
        due_at: null,
        since: '2026-10-05T09:00:00-05:00',
        reason: null,
        target: { type: 'request', key: 'GIA-127' },
    },
    {
        id: 'wait-147',
        column: 'waiting',
        company_id: SEGUREX.id,
        ref_label: 'GIA-147',
        title: 'Cupo de crédito en Toma Pedidos: ¿qué cupo mostrar?',
        person: 'Juan Pérez',
        due_at: null,
        since: '2026-10-04T14:00:00-05:00',
        reason: null,
        target: { type: 'request', key: 'GIA-147' },
    },
    {
        id: 'wait-144',
        column: 'waiting',
        company_id: DERIVADOS.id,
        ref_label: 'GIA-144',
        title: 'Aprobación de la conciliación bancaria',
        person: 'Edward Ramanan',
        due_at: null,
        since: '2026-10-07T08:40:00-05:00',
        reason: null,
        target: { type: 'approval', key: 'GIA-144' },
    },
];

type WeekPage = PageProps<WeekIndexProps>;

const weekJhon: WeekPage = {
    ...sharedFor('jhon'),
    today: TODAY,
    week_ends_on: '2026-10-11',
    now: NOW_ISO,
    items: WEEK_ITEMS,
    running_timer: {
        project_key: 'P-07',
        task_key: 'T4',
        task_title: 'Consulta de cartera por asesor desde SAP B1',
        started_at: '2026-10-07T08:27:20-05:00',
        subtasks_done: 2,
        subtasks_total: 5,
        due_at: '2026-10-18',
    },
    review: {
        title: 'Revisión semanal con Leonardo',
        at: '2026-10-08T15:00:00-05:00',
        agenda: [
            { id: 'wip', text: 'Qué entra cuando haya cupo: hoy 3 de 3 en desarrollo' },
            { id: 'p07', text: 'P-07 pasó el 80 % del costo y va 14 días tarde' },
            { id: 'approvals', text: 'Aprobaciones pendientes: GIA-146 y GIA-144' },
            { id: 'manual', text: 'Orden manual del backlog: GIA-102 arriba de GIA-121' },
        ],
        previous: { at: '2026-10-01', decisions: 3 },
    },
};

// ---- variants -----------------------------------------------------------------

export const variants: Record<
    string,
    MockVariant<InboxPage> | MockVariant<ShowPage> | MockVariant<ApprovalsPage> | MockVariant<ApprovalPage> | MockVariant<BacklogPage> | MockVariant<AreasPage> | MockVariant<WeekPage>
> = {
    // Inbox
    inbox: { page: 'Ai/Inbox/Index', label: 'Bandeja · todas (Jhon, Todas)', props: inboxAll },
    'inbox-unanswered': { page: 'Ai/Inbox/Index', label: 'Bandeja · sin primera respuesta (vista de celular)', props: inbox('unanswered') },
    'inbox-derivados': { page: 'Ai/Inbox/Index', label: 'Bandeja · solo Derivados (Leonardo)', props: inbox('all', DERIVADOS.id, 'leonardo') },
    'inbox-empty': { page: 'Ai/Inbox/Index', label: 'Bandeja · nada sin responder', props: inboxEmpty },
    'inbox-filter-empty': { page: 'Ai/Inbox/Index', label: 'Bandeja · filtro sin resultados', props: inboxFilteredEmpty },

    // Request detail (IT)
    'show-it': { page: 'Ai/Requests/Show', label: 'Solicitud · vista de TI (GIA-148, duplicada)', props: showGia148 },
    'show-it-hour-value': { page: 'Ai/Requests/Show', label: 'Solicitud · vista de TI con valor de hora (GIA-151, Leonardo)', props: showGia151 },
    'show-it-save-error': {
        page: 'Ai/Requests/Show',
        label: 'Solicitud · error al guardar la evaluación (GIA-148)',
        props: {
            ...showGia148,
            ...sharedFor('jhon', {
                flash: {
                    error: 'No se guardó la evaluación de GIA-148: el servidor no respondió a tiempo. Tus respuestas siguen en el formulario; no cierres la pestaña y vuelve a intentar. Si falla otra vez, copia la evaluación y avísale a Leonardo Herrera.',
                },
            }),
        },
    },

    // Approvals
    approvals: { page: 'Ai/Approvals/Index', label: 'Aprobaciones · escritorio (Jhon)', props: approvalsJhon },
    'approvals-146': {
        page: 'Ai/Approvals/Index',
        label: 'Aprobaciones · GIA-146 con retorno',
        props: { ...approvalsJhon, selected: approvalDetail('GIA-146') },
    },
    'approvals-edward': { page: 'Ai/Approvals/Index', label: 'Aprobaciones · Edward (sin pestañas de TI)', props: { ...approvalsJhon, ...sharedFor('edward') } },
    'approvals-empty': { page: 'Ai/Approvals/Index', label: 'Aprobaciones · nada pendiente', props: { ...approvalsJhon, pending: [], selected: null } },
    'approvals-no-permission': { page: 'Ai/Approvals/Index', label: 'Aprobaciones · sin permiso (Leonardo)', props: { ...approvalsJhon, ...sharedFor('leonardo') } },
    approval: { page: 'Ai/Approvals/Show', label: 'Aprobar GIA-144 · celular, desde Telegram (Edward)', props: approvalEdward },
    'approval-done': {
        page: 'Ai/Approvals/Show',
        label: 'Aprobación lista (Edward aprobó)',
        props: { ...approvalEdward, decision: { result: 'approved', at: '2026-10-07T10:12:00-05:00', comment: 'Adelante. Que arranque cuando haya cupo.' } },
    },
    'approval-rejected': {
        page: 'Ai/Approvals/Show',
        label: 'Aprobación lista (Edward rechazó)',
        props: {
            ...approvalEdward,
            decision: { result: 'rejected', at: '2026-10-07T10:12:00-05:00', comment: 'Primero cerremos P-07; la retomamos en enero.' },
        },
    },
    'approval-jhon': {
        page: 'Ai/Approvals/Show',
        label: 'Aprobar GIA-146 · Jhon, con retorno',
        props: { ...approvalEdward, ...sharedFor('jhon'), approval: approvalDetail('GIA-146'), from_telegram: false },
    },
    'approval-no-permission': { page: 'Ai/Approvals/Show', label: 'Aprobar · sin permiso (Leonardo)', props: { ...approvalEdward, ...sharedFor('leonardo') } },

    // Backlog
    backlog: { page: 'Ai/Backlog/Index', label: 'Backlog · orden manual', props: backlogManual },
    'backlog-suggested': { page: 'Ai/Backlog/Index', label: 'Backlog · orden sugerido', props: { ...backlogManual, items: SUGGESTED, manual_order: null } },
    'backlog-derivados': {
        page: 'Ai/Backlog/Index',
        label: 'Backlog · solo Derivados',
        props: {
            ...backlogManual,
            ...sharedFor('jhon', { currentCompanyId: DERIVADOS.id }),
            items: backlog().filter((item) => item.company_id === DERIVADOS.id),
            areas: ALL_AREAS.filter((area) => area.company_id === DERIVADOS.id),
        },
    },
    'backlog-empty': { page: 'Ai/Backlog/Index', label: 'Backlog · vacío', props: { ...backlogManual, items: [], manual_order: null } },

    // Areas
    areas: { page: 'Ai/Areas/Index', label: 'Por área · Segurex, Ventas abierta', props: areasPage(VENTAS_DETAIL) },
    'areas-cartera': { page: 'Ai/Areas/Index', label: 'Por área · Cartera atrasada', props: areasPage(CARTERA_DETAIL) },
    'areas-closed': { page: 'Ai/Areas/Index', label: 'Por área · sin área abierta', props: areasPage(null) },
    'areas-derivados': {
        page: 'Ai/Areas/Index',
        label: 'Por área · Derivados',
        props: { ...areasPage(null), company_id: DERIVADOS.id, areas: AREA_SUMMARIES_DERIVADOS },
    },

    // My week
    week: { page: 'Ai/Week/Index', label: 'Mi semana · con cronómetro (Jhon)', props: weekJhon },
    'week-quiet': {
        page: 'Ai/Week/Index',
        label: 'Mi semana · sin cronómetro ni revisión',
        props: { ...weekJhon, items: WEEK_ITEMS.filter((item) => item.column !== 'blocked'), running_timer: null, review: null },
    },
};
