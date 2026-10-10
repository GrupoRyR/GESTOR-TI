/**
 * Mocks for the requester pages (boards B1–B4): Andrés Méndez (Derivados,
 * Compras) and Paola Ríos (Segurex, Ventas). Entities come from
 * ai-fixtures; only page-specific copy (next steps, notes) lives here.
 */
import type { RequestSummary } from '@/types/ai';
import type {
    MyRequestRow,
    RatingsCreateProps,
    RatingsHoursSavedProps,
    RequestDraftPayload,
    RequesterRequestDetail,
    RequestsCreateProps,
    RequestsMineProps,
    RequestsShowProps,
    RequestsSubmittedProps,
    RequestsValidateProps,
    RequestTypeOption,
    RoadmapIndexProps,
    RoadmapItem,
} from '@/types/pages/ai-requester';
import type { PageProps, Paginated } from '@/types/shared';
import { AREAS, CRITERIA_GIA_146, NOW_ISO, PEOPLE, PRODUCT_REFS, requestByKey, TIMELINE_GIA_146, TODAY, WIP_LIMIT } from './ai-fixtures';
import { DERIVADOS, SEGUREX, sharedFor, type MockVariant } from './shared';

const areaOption = (area: { id: number; name: string }) => ({ id: area.id, name: area.name });

// ---- Create -----------------------------------------------------------------

const TYPES: RequestTypeOption[] = [
    { value: 'new', title: 'Algo nuevo', description: 'Una app, un reporte o una automatización que no existe.' },
    { value: 'improvement', title: 'Mejorar una app que ya usamos', description: 'Agregar o cambiar algo en una app que ya funciona.' },
    { value: 'bug', title: 'Algo no funciona en una app', description: 'Un error, algo lento o un dato que sale mal.' },
    { value: 'idea', title: 'Tengo una idea o una pregunta', description: 'No sé si se puede. Quiero que TI lo mire.' },
];

const SYSTEMS = ['SAP Business One', 'Excel', 'Correo', 'Toma Pedidos SAP', 'Portafolio de productos'];

const DERIVADOS_AREAS = [AREAS.compras, AREAS.ventasD, AREAS.tesoreria, AREAS.diseno, AREAS.mercadeo, AREAS.produccion].map(areaOption);
const SEGUREX_AREAS = [AREAS.ventas, AREAS.cartera, AREAS.contabilidad, AREAS.gerencia, AREAS.comprasS, AREAS.tesoreriaS, AREAS.produccionS].map(areaOption);

/** GIA-150 as Andrés fills it, step by step (boards B-NewType … B-NewReview). */
const ANDRES_DRAFT: RequestDraftPayload = {
    type: 'improvement',
    product_slug: PRODUCT_REFS.portafolio.slug,
    problem: 'Los precios de los proveedores llegan en Excel separados. Para comparar una referencia hay que abrir 3 archivos y copiar a mano.',
    plus_one_key: null,
    hours_per_week: 6,
    people: 8,
    expected_result: 'Ver los precios de 3 proveedores en una sola tabla del Portafolio',
    success_criteria: 'Comparo una referencia en menos de 1 minuto, sin abrir Excel',
    systems: ['SAP Business One', 'Excel', 'Correo', 'Portafolio de productos'],
    systems_notes: 'Los precios llegan por correo en Excel, uno por proveedor.',
    urgency: 'high',
    deadline: '2026-10-30',
    deadline_reason: 'El 1 de noviembre cambian las listas de precios y hay que comparar antes.',
    area_id: AREAS.compras.id,
};

type CreatePage = PageProps<RequestsCreateProps>;

const createAndres: CreatePage = {
    ...sharedFor('andres'),
    product: PRODUCT_REFS.portafolio,
    types: TYPES,
    apps: Object.values(PRODUCT_REFS).filter((app) => app.slug === 'portafolio'),
    systems_options: SYSTEMS,
    area_suggestion: areaOption(AREAS.compras),
    areas: DERIVADOS_AREAS,
    max_attachment_mb: 10,
    similar: [],
    today: TODAY,
    draft: null,
    draft_saved_at: null,
};

const draftUpTo = (step: 2 | 3 | 4 | 5 | 6 | 7): Partial<RequestDraftPayload> => {
    const { hours_per_week, people, expected_result, success_criteria, systems, systems_notes, urgency, deadline, deadline_reason, ...first } = ANDRES_DRAFT;
    return {
        ...first,
        ...(step >= 3 ? { hours_per_week, people } : {}),
        ...(step >= 5 ? { expected_result, success_criteria } : {}),
        ...(step >= 6 ? { systems, systems_notes } : {}),
        ...(step >= 7 ? { urgency, deadline, deadline_reason } : {}),
    };
};

/** Paola writes about blocked orders: the server finds GIA-121 (board B-NewProblem). */
const createPaola: CreatePage = {
    ...sharedFor('paola'),
    product: null,
    types: TYPES,
    apps: Object.values(PRODUCT_REFS).filter((app) => app.slug !== 'portafolio'),
    systems_options: SYSTEMS,
    area_suggestion: areaOption(AREAS.ventas),
    areas: SEGUREX_AREAS,
    max_attachment_mb: 10,
    similar: [requestByKey('GIA-121')],
    today: TODAY,
    draft: {
        type: 'improvement',
        product_slug: PRODUCT_REFS.tomaPedidos.slug,
        problem:
            'Cuando un pedido queda bloqueado por cartera el asesor no se entera. Lo descubre cuando el cliente llama a preguntar por qué no le ha llegado. Nos pasa a los 8 asesores',
        area_id: AREAS.ventas.id,
    },
    draft_saved_at: NOW_ISO,
    initial_step: 2,
};

// ---- Submitted --------------------------------------------------------------

type SubmittedPage = PageProps<RequestsSubmittedProps>;

const submitted: SubmittedPage = {
    ...sharedFor('andres'),
    request: { key: 'GIA-150', title: requestByKey('GIA-150').title },
    // Three business days; Monday 12 Oct is a holiday in Colombia.
    first_response_due_at: '2026-10-13T17:00:00-05:00',
    telegram_linked: false,
};

// ---- Mine -------------------------------------------------------------------

function paginate<T>(rows: T[], total = rows.length, perPage = 10): Paginated<T> {
    const lastPage = Math.max(1, Math.ceil(total / perPage));
    const url = (page: number) => `#ai.requests.mine?page=${page}`;
    return {
        data: rows,
        links: [
            { url: null, label: '&laquo; Anterior', active: false },
            ...Array.from({ length: Math.min(lastPage, 3) }, (_, index) => ({ url: url(index + 1), label: String(index + 1), active: index === 0 }),
            ),
            { url: lastPage > 1 ? url(2) : null, label: 'Siguiente &raquo;', active: false },
        ],
        meta: { current_page: 1, last_page: lastPage, per_page: perPage, total },
    };
}

const row = (key: string, next_step: string, extra: Partial<MyRequestRow> = {}): MyRequestRow => ({
    ...requestByKey(key),
    next_step,
    needs_reply: false,
    question: null,
    can_rate: false,
    ...extra,
});

const ANDRES_ROWS: MyRequestRow[] = [
    row('GIA-150', 'La evaluación sigue cuando respondas.', {
        needs_reply: true,
        question: { text: '¿Qué proveedores entran en la comparación? ¿Solo los 3 de tornillería?', asked_by: 'Jhon Castro', asked_at: NOW_ISO },
    }),
    row('GIA-146', 'TI ya la evaluó. Falta el visto bueno de un aprobador.'),
    row('GIA-118', 'En producción desde el 2 oct, versión 1.8.2.', { can_rate: true }),
    row('GIA-131', 'En construcción. Espera un dato de Mercadeo para seguir.'),
    row('GIA-133', 'Planificada: empieza el 17 nov.'),
];

const PAOLA_ROWS: MyRequestRow[] = [
    row('GIA-127', 'En pruebas. Te toca validar 2 de 4 criterios.'),
    row('GIA-148', 'TI la revisa: la primera respuesta ya se venció y te escriben hoy.'),
    row('GIA-141', 'Aprobada. Empieza cuando se libere un cupo de construcción.'),
    row('GIA-113', 'En backlog: puesto 2 en la hoja de ruta de Ventas.'),
];

type MinePage = PageProps<RequestsMineProps>;

const mineAndres: MinePage = {
    ...sharedFor('andres'),
    requests: paginate(ANDRES_ROWS),
    filter: 'active',
    counts: { active: 5, done: 3, all: 8 },
};

// ---- Show (requester) -------------------------------------------------------

const detail = (summary: RequestSummary, extra: Partial<RequesterRequestDetail>): RequesterRequestDetail => ({
    ...summary,
    answers: {
        problem: '',
        current_process: null,
        expected_result: '',
        success_criteria: null,
        systems: [],
        systems_notes: null,
        deadline: null,
        deadline_reason: null,
    },
    attachments: [],
    criteria: [],
    timeline: [],
    comments: [],
    estimate: null,
    approval: null,
    rating: null,
    followers_count: 1,
    ...extra,
});

const GIA_146 = detail(requestByKey('GIA-146'), {
    answers: {
        problem: 'Para mandar cotizaciones copio la ficha de cada producto a Word y la paso a PDF a mano.',
        current_process: 'Abro la ficha en el Portafolio, copio foto y medidas a Word y exporto.',
        expected_result: 'Bajar la ficha técnica en PDF desde el Portafolio.',
        success_criteria: 'Armo el PDF de una cotización en menos de 2 minutos.',
        systems: ['Portafolio de productos'],
        systems_notes: null,
        deadline: null,
        deadline_reason: null,
    },
    criteria: CRITERIA_GIA_146,
    timeline: TIMELINE_GIA_146,
    comments: [
        { id: 51, author: PEOPLE.jhon, is_it: true, body: 'Andrés, ya quedó evaluada. Dejamos el logo como nos dijiste.', created_at: '2026-10-02T10:05:00-05:00' },
        {
            id: 52,
            author: PEOPLE.andres,
            is_it: false,
            body: '¡Gracias! ¿Se pueden bajar varias fichas en un solo PDF? Lo necesito para mandar cotizaciones.',
            created_at: '2026-10-02T11:30:00-05:00',
        },
        { id: 53, author: PEOPLE.jhon, is_it: true, body: 'Sí. Lo agregué como el criterio 4.', created_at: '2026-10-03T09:00:00-05:00' },
    ],
    estimate: { due_date: '2026-10-31', hours_total: 40 },
    followers_count: 3,
});

const GIA_150 = detail(requestByKey('GIA-150'), {
    timeline: [
        {
            id: 63,
            kind: 'question',
            family: 'amber',
            title: 'TI preguntó',
            quote: '¿Qué proveedores entran en la comparación? ¿Solo los 3 de tornillería?',
            detail: 'Jhon Castro · responde en la conversación',
            actor: 'Jhon Castro',
            at: NOW_ISO,
        },
        { id: 62, kind: 'status', family: 'amber', title: 'Necesita información', quote: null, detail: 'La evaluación sigue cuando respondas', actor: null, at: NOW_ISO },
        { id: 61, kind: 'status', family: 'gray', title: 'Recibida', quote: null, detail: 'desde el botón “Pedir mejora” del Portafolio de productos', actor: null, at: '2026-10-06T16:00:00-05:00' },
    ],
    comments: [
        {
            id: 64,
            author: PEOPLE.jhon,
            is_it: true,
            body: '¿Qué proveedores entran en la comparación? ¿Solo los 3 de tornillería?',
            created_at: NOW_ISO,
        },
    ],
});

const GIA_127 = detail(requestByKey('GIA-127'), {
    criteria: [
        { id: 71, text: 'Al pedir 50 unidades o más de una referencia, el descuento se aplica solo.', validated: { by: 'Paola Ríos', at: '2026-10-06T10:00:00-05:00' }, note: null },
        { id: 72, text: 'El descuento se ve en el resumen del pedido antes de enviarlo.', validated: { by: 'Paola Ríos', at: '2026-10-06T10:05:00-05:00' }, note: null },
        { id: 73, text: 'El pedido llega a SAP con el descuento en la línea.', validated: null, note: null },
        { id: 74, text: 'Un asesor no puede cambiar el descuento a mano.', validated: null, note: null },
    ],
    timeline: [
        { id: 83, kind: 'status', family: 'amber', title: 'En pruebas', quote: null, detail: 'Te toca validar los criterios', actor: null, at: '2026-10-05T15:00:00-05:00' },
        { id: 82, kind: 'status', family: 'blue', title: 'En desarrollo', quote: null, detail: 'Proyecto P-06', actor: 'Jhon Castro', at: '2026-09-15T09:00:00-05:00' },
        { id: 81, kind: 'approval', family: 'green', title: 'Aprobada por Edward Ramanan', quote: null, detail: null, actor: 'Edward Ramanan', at: '2026-09-10T11:00:00-05:00' },
    ],
    comments: [],
    estimate: { due_date: '2026-10-15', hours_total: 48 },
    followers_count: 2,
});

type ShowPage = PageProps<RequestsShowProps>;

// ---- Validate -----------------------------------------------------------------

type ValidatePage = PageProps<RequestsValidateProps>;

const validateProps: ValidatePage = {
    ...sharedFor('paola'),
    request: {
        key: 'GIA-127',
        title: requestByKey('GIA-127').title,
        product: { name: 'Toma Pedidos SAP', url: 'https://pruebas.tomapedidos.segurex.com' },
    },
    criteria: [
        { id: 71, text: 'Al pedir 50 unidades o más de una referencia, el descuento se aplica solo.', result: 'pass', comment: null },
        {
            id: 72,
            text: 'El descuento se ve en el resumen del pedido antes de enviarlo.',
            result: 'fail',
            comment: 'En el resumen sale el precio sin descuento. Solo aparece en el PDF del pedido.',
        },
        { id: 73, text: 'El pedido llega a SAP con el descuento en la línea.', result: null, comment: null },
        { id: 74, text: 'Un asesor no puede cambiar el descuento a mano.', result: null, comment: null },
    ],
};

// ---- Ratings --------------------------------------------------------------------

const GIA_118 = { key: 'GIA-118', title: requestByKey('GIA-118').title, product_name: PRODUCT_REFS.portafolio.name, in_production_since: '2026-10-02' };

// ---- Roadmap --------------------------------------------------------------------

const item = (key: string, viewerId: number, note: string | null, extra: Partial<RoadmapItem> = {}): RoadmapItem => {
    const source = requestByKey(key);
    return {
        key: source.key,
        title: source.title,
        area: source.area,
        company_id: source.company_id,
        status: source.status,
        votes: source.votes,
        mine: source.requester.id === viewerId,
        voted: false,
        note,
        planned_start: null,
        ...extra,
    };
};

const roadmapColumns = (viewerId: number): RoadmapIndexProps['columns'] => ({
    now: [
        item('GIA-139', viewerId, 'Entrega estimada 14 nov'),
        item('GIA-131', viewerId, 'Espera un dato de Mercadeo'),
        item('GIA-127', viewerId, 'Ventas la está probando'),
    ],
    next: [
        item('GIA-141', viewerId, 'Empieza cuando se libere un cupo'),
        item('GIA-136', viewerId, null, { planned_start: '2026-11-03' }),
        item('GIA-133', viewerId, null, { planned_start: '2026-11-17' }),
    ],
    later: [
        item('GIA-121', viewerId, 'Puesto 1 en Ventas', { voted: viewerId === PEOPLE.paola.id }),
        item('GIA-144', viewerId, 'Puesto 1 en Tesorería'),
        item('GIA-146', viewerId, 'Puesto 3 en Compras'),
        item('GIA-102', viewerId, 'Puesto 6 en Compras'),
    ],
});

type RoadmapPage = PageProps<RoadmapIndexProps>;

const roadmapAndres: RoadmapPage = {
    ...sharedFor('andres'),
    columns: roadmapColumns(PEOPLE.andres.id),
    filters: { area_id: null, company_id: null, mine: false },
    areas: [AREAS.compras, AREAS.ventasD, AREAS.cartera, AREAS.tesoreria].map(areaOption),
    roadmap_companies: [SEGUREX, DERIVADOS],
    later_total: 14,
    wip_limit: WIP_LIMIT,
    mine_count: 3,
    updated_at: NOW_ISO,
};

const onlyMine = (columns: RoadmapIndexProps['columns']): RoadmapIndexProps['columns'] => ({
    now: columns.now.filter((entry) => entry.mine),
    next: columns.next.filter((entry) => entry.mine),
    later: columns.later.filter((entry) => entry.mine),
});

// ---- variants ---------------------------------------------------------------------

export const variants: Record<
    string,
    | MockVariant<CreatePage>
    | MockVariant<SubmittedPage>
    | MockVariant<MinePage>
    | MockVariant<ShowPage>
    | MockVariant<ValidatePage>
    | MockVariant<PageProps<RatingsCreateProps>>
    | MockVariant<PageProps<RatingsHoursSavedProps>>
    | MockVariant<RoadmapPage>
> = {
    // Wizard
    'create-from-product': {
        page: 'Ai/Requests/Create',
        label: 'Pedir mejora · paso 1, desde el Portafolio (Andrés)',
        props: createAndres,
    },
    'create-blank': {
        page: 'Ai/Requests/Create',
        label: 'Pedir · paso 1 sin app (Paola)',
        props: { ...createPaola, similar: [], draft: null, draft_saved_at: null, initial_step: undefined },
    },
    'create-similar': {
        page: 'Ai/Requests/Create',
        label: 'Pedir · paso 2 con una parecida, GIA-121 (Paola)',
        props: createPaola,
    },
    'create-problem': {
        page: 'Ai/Requests/Create',
        label: 'Pedir mejora · paso 2, el problema',
        props: { ...createAndres, draft: draftUpTo(2), initial_step: 2, draft_saved_at: NOW_ISO },
    },
    'create-time': {
        page: 'Ai/Requests/Create',
        label: 'Pedir mejora · paso 3, tiempo (6 h × 8)',
        props: { ...createAndres, draft: draftUpTo(3), initial_step: 3, draft_saved_at: NOW_ISO },
    },
    'create-result': {
        page: 'Ai/Requests/Create',
        label: 'Pedir mejora · paso 4, qué esperas',
        props: { ...createAndres, draft: draftUpTo(5), initial_step: 4, draft_saved_at: NOW_ISO },
    },
    'create-systems': {
        page: 'Ai/Requests/Create',
        label: 'Pedir mejora · paso 5, sistemas',
        props: { ...createAndres, draft: draftUpTo(6), initial_step: 5, draft_saved_at: NOW_ISO },
    },
    'create-urgency': {
        page: 'Ai/Requests/Create',
        label: 'Pedir mejora · paso 6, urgencia con fecha',
        props: { ...createAndres, draft: draftUpTo(7), initial_step: 6, draft_saved_at: NOW_ISO },
    },
    'create-review': {
        page: 'Ai/Requests/Create',
        label: 'Pedir mejora · paso 7, adjuntar y revisar',
        props: { ...createAndres, draft: ANDRES_DRAFT, initial_step: 7, draft_saved_at: NOW_ISO },
    },
    'create-errors': {
        page: 'Ai/Requests/Create',
        label: 'Pedir mejora · errores del servidor',
        props: {
            ...createAndres,
            draft: { ...ANDRES_DRAFT, problem: 'Comparar precios' },
            errors: {
                problem: 'Cuéntalo en una o dos frases (mínimo 20 caracteres).',
                'attachments.0': 'El archivo «precios-octubre.xlsx» pesa 14 MB; cada archivo puede pesar hasta 10 MB.',
            },
        },
    },

    // Submitted
    submitted: { page: 'Ai/Requests/Submitted', label: 'Solicitud recibida (GIA-150)', props: submitted },
    'submitted-telegram': {
        page: 'Ai/Requests/Submitted',
        label: 'Solicitud recibida · ya tiene Telegram',
        props: { ...submitted, telegram_linked: true },
    },

    // Mine
    mine: { page: 'Ai/Requests/Mine', label: 'Mis solicitudes · con una pregunta de TI (Andrés)', props: mineAndres },
    'mine-paola': {
        page: 'Ai/Requests/Mine',
        label: 'Mis solicitudes · Paola (Segurex)',
        props: { ...sharedFor('paola'), requests: paginate(PAOLA_ROWS), filter: 'active', counts: { active: 4, done: 2, all: 6 } },
    },
    'mine-done-empty': {
        page: 'Ai/Requests/Mine',
        label: 'Mis solicitudes · filtro sin resultados',
        props: { ...mineAndres, requests: paginate([]), filter: 'done', counts: { active: 5, done: 0, all: 5 } },
    },
    'mine-empty': {
        page: 'Ai/Requests/Mine',
        label: 'Mis solicitudes · todavía nada',
        props: { ...mineAndres, requests: paginate([]), counts: { active: 0, done: 0, all: 0 } },
    },

    // Show (requester)
    'show-pending': {
        page: 'Ai/Requests/Show',
        label: 'Solicitud · pendiente de aprobación (Andrés, GIA-146)',
        props: {
            ...sharedFor('andres'),
            view: 'requester',
            request: GIA_146,
            where: {
                label: 'Esperando la aprobación de un aprobador de proyectos',
                explanation: 'TI ya la evaluó y la dejó lista. Ahora Gerencia decide si entra a la hoja de ruta. No tienes que hacer nada; te avisamos por correo.',
            },
            roadmap_position: { rank: 3, label: 'Puesto 3 en la hoja de ruta de Compras', detail: 'Si se aprueba, empieza después de 2 solicitudes.' },
            following: true,
            can_comment: true,
        },
    },
    'show-needs-info': {
        page: 'Ai/Requests/Show',
        label: 'Solicitud · TI pregunta algo (Andrés, GIA-150)',
        props: {
            ...sharedFor('andres'),
            view: 'requester',
            request: GIA_150,
            where: { label: 'TI necesita que le respondas', explanation: 'La evaluación sigue cuando contestes en la conversación de abajo.' },
            roadmap_position: null,
            following: true,
            can_comment: true,
        },
    },
    'show-testing': {
        page: 'Ai/Requests/Show',
        label: 'Solicitud · en pruebas (Paola, GIA-127)',
        props: {
            ...sharedFor('paola'),
            view: 'requester',
            request: GIA_127,
            where: { label: 'Te toca validarla', explanation: 'Prueba la versión de pruebas y marca cada criterio. Faltan 2 de 4.' },
            roadmap_position: null,
            following: false,
            can_comment: true,
        },
    },

    // Validate
    validate: { page: 'Ai/Requests/Validate', label: 'Validar GIA-127 · 2 de 4 (Paola)', props: validateProps },
    'validate-ready': {
        page: 'Ai/Requests/Validate',
        label: 'Validar GIA-127 · lista para enviar',
        props: {
            ...validateProps,
            criteria: validateProps.criteria.map((criterion) => (criterion.result === null ? { ...criterion, result: 'pass' as const } : criterion)),
        },
    },

    // Ratings
    rate: { page: 'Ai/Ratings/Create', label: 'Calificar la entrega (GIA-118)', props: { ...sharedFor('andres'), request: GIA_118 } },
    'hours-saved': {
        page: 'Ai/Ratings/HoursSaved',
        label: 'Horas que ahorra, 30 días después (GIA-118)',
        props: { ...sharedFor('andres'), request: GIA_118, estimated_hours_per_week: 3 },
    },

    // Roadmap
    roadmap: { page: 'Ai/Roadmap/Index', label: 'Hoja de ruta · solicitante (Andrés)', props: roadmapAndres },
    'roadmap-mine': {
        page: 'Ai/Roadmap/Index',
        label: 'Hoja de ruta · solo las mías',
        props: { ...roadmapAndres, columns: onlyMine(roadmapAndres.columns), filters: { ...roadmapAndres.filters, mine: true }, later_total: 2 },
    },
    'roadmap-it': {
        page: 'Ai/Roadmap/Index',
        label: 'Hoja de ruta · TI (Jhon, Todas)',
        props: {
            ...roadmapAndres,
            ...sharedFor('jhon'),
            columns: roadmapColumns(PEOPLE.jhon.id),
            areas: [...SEGUREX_AREAS, ...DERIVADOS_AREAS].map((area) => ({ ...area, name: `${area.name} · ${area.id < 20 ? 'Segurex' : 'Derivados'}` })),
            mine_count: 0,
        },
    },
};
