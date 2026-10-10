/**
 * Mocks for the project pages (boards B11–B17): Jhon Castro (Líder IA, sees
 * costs), Leonardo Herrera, a viewer without `ai.costs.view` and the
 * Contabilidad user who must answer a requirement. Entities come from
 * ai-fixtures; only page copy (activity, report text, task detail) lives here.
 */
import type { PersonRef, Project, Requirement, Task, TaskDetail } from '@/types/ai';
import type {
    ProjectActivity,
    ProjectHeader,
    ProjectListItem,
    ProjectsBoardProps,
    ProjectsCostsProps,
    ProjectsIndexProps,
    ProjectsReportProps,
    ProjectsRequirementsProps,
    ProjectsShowProps,
    RecipientOption,
    ReportRecipient,
    RequirementsRespondProps,
    RunningTimer,
    TaskRef,
    TasksShowProps,
    TimeEntriesCreateProps,
} from '@/types/pages/ai-projects';
import type { PageProps, SharedProps } from '@/types/shared';
import {
    COSTS_P07,
    CRITERIA_P07,
    HOURS_CURVE_P07,
    NOW_ISO,
    PEOPLE,
    PROJECTS,
    projectByKey,
    requestByKey,
    REQUIREMENTS,
    requirementsOf,
    TIME_BY_PERSON_P07,
    TIME_ENTRIES,
    TODAY,
    tasksOf,
    WIP_LIMIT,
} from './ai-fixtures';
import { DERIVADOS, SEGUREX, sharedFor, type MockUserKey, type MockVariant } from './shared';

// ---- shared pieces ------------------------------------------------------------

const WIP = { in_development: 3, limit: WIP_LIMIT };

const P07 = projectByKey('P-07');

const header = (project: Project): ProjectHeader => ({
    key: project.key,
    name: project.name,
    status: project.status,
    company_id: project.company_id,
    area: project.area,
    request_keys: project.request_keys,
    open_requirements: project.open_requirements,
});

/** Same user without `ai.costs.view`: what IT staff without the cost permission see. */
function withoutCosts(key: MockUserKey): SharedProps {
    const shared = sharedFor(key);
    const user = shared.auth.user;
    return { ...shared, auth: { user: { ...user, permissions: user.permissions.filter((permission) => permission !== 'ai.costs.view' && permission !== 'ai.costs.manage') } } };
}

const hideCost = <T extends { budget: Project['budget'] }>(item: T): T => ({ ...item, budget: { ...item.budget, cost: null } });

const taskRefs = (projectKey: string): TaskRef[] => tasksOf(projectKey).filter((task) => task.column !== 'done').map((task) => ({ key: task.key, title: task.title }));

const RUNNING_T4: RunningTimer = {
    project_key: 'P-07',
    task_key: 'T4',
    task_title: 'Consulta de cartera por asesor desde SAP B1',
    started_at: '2026-10-07T08:27:20-05:00',
    subtasks_done: 2,
    subtasks_total: 5,
    due_at: '2026-10-18',
};

// ---- Index --------------------------------------------------------------------

const listItem = (project: Project): ProjectListItem => ({
    key: project.key,
    name: project.name,
    status: project.status,
    company_id: project.company_id,
    area: project.area,
    request_keys: project.request_keys,
    team: project.team,
    budget: project.budget,
    tasks_by_column: project.tasks_by_column,
    blocked_tasks: project.blocked_tasks,
    open_requirements: project.open_requirements,
});

type IndexPage = PageProps<ProjectsIndexProps>;

const indexJhon: IndexPage = {
    ...sharedFor('jhon'),
    projects: PROJECTS.map(listItem),
    filters: { status: null, company_id: null },
    wip: WIP,
    now: NOW_ISO,
};

// ---- Summary ------------------------------------------------------------------

const ACTIVITY_P07: ProjectActivity[] = [
    { id: 1, kind: 'task', actor: PEOPLE.jhon, text: 'movió T3 Endpoint de zonas a En revisión', at: '2026-10-07T09:40:00-05:00' },
    { id: 2, kind: 'github', actor: PEOPLE.leonardo, text: 'abrió el PR #41 en GitHub', at: '2026-10-06T17:12:00-05:00' },
    { id: 3, kind: 'requirement', actor: PEOPLE.diego, text: 'respondió el requerimiento Lista de zonas y asesores', at: '2026-10-05T11:00:00-05:00' },
    { id: 4, kind: 'budget', actor: null, text: 'Aviso del 80 % del costo enviado a Jhon Castro y Edward Ramanan', at: '2026-10-05T08:00:00-05:00' },
];

const APPROVERS: PersonRef[] = [PEOPLE.jhon, PEOPLE.edward];

type ShowPage = PageProps<ProjectsShowProps>;

const summaryJhon: ShowPage = {
    ...sharedFor('jhon'),
    project: P07,
    origin_request: { ...requestByKey('GIA-139'), approved_by: PEOPLE.edward, approved_at: '2026-09-30T15:00:00-05:00' },
    open_requirements: requirementsOf('P-07').filter((requirement) => requirement.status !== 'answered'),
    activity: ACTIVITY_P07,
    approvers: APPROVERS,
    reapproval_threshold: 0.2,
    reapproval_requested_at: null,
    next_step: 'Sigue Entrega, con pruebas de Gerencia de cartera.',
    today: TODAY,
    now: NOW_ISO,
};

const P06 = projectByKey('P-06');

const summaryP06: ShowPage = {
    ...summaryJhon,
    project: P06,
    origin_request: { ...requestByKey('GIA-127'), approved_by: PEOPLE.jhon, approved_at: '2026-08-28T10:00:00-05:00' },
    open_requirements: [],
    activity: [
        { id: 11, kind: 'task', actor: PEOPLE.leonardo, text: 'movió T7 Pruebas con Ventas a En revisión', at: '2026-10-05T17:00:00-05:00' },
        { id: 12, kind: 'comment', actor: PEOPLE.paola, text: 'validó 2 de 4 criterios de aceptación', at: '2026-10-05T11:30:00-05:00' },
    ],
    next_step: 'Sigue Entrega cuando Paola valide los 2 criterios que faltan.',
};

// ---- Board --------------------------------------------------------------------

const T4_DETAIL: TaskDetail = {
    ...tasksOf('P-07').find((task) => task.key === 'T4')!,
    description:
        'Traer de SAP B1 la cartera de cada asesor con su zona y los días vencidos, para que el filtro del visualizador responda sin exportar Excel. Usa la vista de cartera de solo lectura.',
    subtasks: [
        { id: 1, title: 'Conectar a la vista de cartera de SAP B1 en pruebas', done: true },
        { id: 2, title: 'Filtrar por código de asesor', done: true },
        { id: 3, title: 'Agrupar por rango de días vencidos', done: false },
        { id: 4, title: 'Probar con 3 asesores reales', done: false },
        { id: 5, title: 'Documentar la consulta', done: false },
    ],
    criteria: CRITERIA_P07.filter((criterion) => criterion.id === 13 || criterion.id === 15),
    comments: [
        {
            id: 71,
            author: PEOPLE.leonardo,
            is_it: true,
            body: 'La vista trae la zona como texto libre. Mejor cruzarla con la lista que mandó Diego.',
            created_at: '2026-10-06T16:30:00-05:00',
        },
        { id: 72, author: PEOPLE.jhon, is_it: true, body: 'De acuerdo. Lo hago en la subtarea de agrupar.', created_at: '2026-10-07T08:15:00-05:00' },
    ],
    time_by_person: [
        { person: PEOPLE.jhon, hours: 6 + 20 / 60 },
        { person: PEOPLE.leonardo, hours: 1.5 },
    ],
    attachments: [
        { id: 21, name: 'consulta-cartera.sql', size: 4_096, mime: 'application/sql', url: '#' },
        { id: 22, name: 'vista-sap-cartera.png', size: 215_040, mime: 'image/png', url: '#' },
    ],
    started_at: '2026-10-05',
};

const T7_DETAIL: TaskDetail = {
    ...tasksOf('P-07').find((task) => task.key === 'T7')!,
    description: 'Sumar la cartera vencida de cada asesor en rangos de 1–30, 31–60, 61–90 y más de 90 días.',
    subtasks: [
        { id: 11, title: 'Definir los rangos con Gerencia de cartera', done: false },
        { id: 12, title: 'Consulta por rango en SAP B1', done: false },
        { id: 13, title: 'Totales por asesor en el visualizador', done: false },
    ],
    criteria: CRITERIA_P07.filter((criterion) => criterion.id === 13),
    comments: [],
    time_by_person: [],
    attachments: [],
    started_at: null,
};

type BoardPage = PageProps<ProjectsBoardProps>;

const P07_TASKS = tasksOf('P-07');

const boardJhon: BoardPage = {
    ...sharedFor('jhon'),
    project: header(P07),
    tasks: P07_TASKS,
    total_tasks: P07_TASKS.length,
    blocked_total: P07_TASKS.filter((task) => task.blocked_by !== null).length,
    column_limits: { in_progress: 3 },
    filters: { assignee_id: null, label: null, blocked: false },
    people: [PEOPLE.jhon, PEOPLE.leonardo],
    labels: ['Backend', 'Diseño', 'Documentación', 'Frontend', 'Pruebas', 'SAP'],
    selected_task: null,
    running_timer: RUNNING_T4,
    now: NOW_ISO,
};

// ---- Task page ----------------------------------------------------------------

type TaskPage = PageProps<TasksShowProps>;

const taskT4: TaskPage = {
    ...sharedFor('jhon'),
    project: { key: P07.key, name: 'Visualizador de cartera · filtros', company_id: P07.company_id },
    task: T4_DETAIL,
    today_project_hours: 2 + 40 / 60,
    now: NOW_ISO,
};

// ---- Requirements -------------------------------------------------------------

const CONTABILIDAD: PersonRef = { id: 14, name: '[RESPONSABLE CONTABILIDAD]', job_title: null, avatar_url: null };
const JEFE_CONTABILIDAD: PersonRef = { id: 15, name: '[JEFE DE CONTABILIDAD]', job_title: 'Contabilidad', avatar_url: null };

const RECIPIENTS: RecipientOption[] = [
    { person: PEOPLE.paola, area_name: 'Ventas', company_id: SEGUREX.id },
    { person: PEOPLE.juan, area_name: 'Ventas', company_id: SEGUREX.id },
    { person: PEOPLE.diego, area_name: 'Cartera', company_id: SEGUREX.id },
    { person: PEOPLE.cartera, area_name: 'Cartera', company_id: SEGUREX.id },
    { person: CONTABILIDAD, area_name: 'Contabilidad', company_id: SEGUREX.id },
    { person: JEFE_CONTABILIDAD, area_name: 'Contabilidad', company_id: SEGUREX.id },
    { person: PEOPLE.andres, area_name: 'Compras', company_id: DERIVADOS.id },
    { person: PEOPLE.monica, area_name: 'Tesorería', company_id: DERIVADOS.id },
    { person: PEOPLE.camila, area_name: 'Diseño', company_id: DERIVADOS.id },
];

/** Open ones by due date, then the answered ones (newest answer first), as the server sorts them. */
function sortRequirements(list: Requirement[]): Requirement[] {
    const open = list.filter((item) => item.status !== 'answered').sort((a, b) => a.due_date.localeCompare(b.due_date));
    const answered = list.filter((item) => item.status === 'answered').sort((a, b) => (b.answered_at ?? '').localeCompare(a.answered_at ?? ''));
    return [...open, ...answered];
}

type RequirementsPage = PageProps<ProjectsRequirementsProps>;

const requirementsAll: RequirementsPage = {
    ...sharedFor('jhon'),
    project: header(P07),
    requirements: sortRequirements(REQUIREMENTS.filter((item) => item.project_key === 'P-07' || item.project_key === 'P-05')),
    scope: 'all',
    my_projects: [
        { key: 'P-07', name: P07.name, tasks: taskRefs('P-07') },
        { key: 'P-05', name: projectByKey('P-05').name, tasks: taskRefs('P-05') },
    ],
    recipients: RECIPIENTS,
    reminder_every_days: 2,
    today: TODAY,
    now: NOW_ISO,
};

// ---- Respond ------------------------------------------------------------------

type RespondPage = PageProps<RequirementsRespondProps>;

const respondPending: RespondPage = {
    ...sharedFor('contabilidad'),
    requirement: {
        id: 64,
        title: 'Acceso de lectura a la vista de cartera en SAP',
        detail: 'Un usuario de SAP Business One que solo pueda consultar la vista de cartera, sin modificar nada. Basta con el nombre del usuario y la confirmación de que ya tiene el permiso.',
        due_date: '2026-10-09',
        status: 'pending',
        requested_by: PEOPLE.jhon,
        project: {
            name: 'Visualizador de cartera',
            context: 'Es la herramienta que pidió la Gerencia de cartera para ver la cartera por asesor y por zona. Sin esto, esa parte queda en pausa.',
        },
        answered_at: null,
        answer: null,
        answer_attachment: null,
    },
    reassign_candidates: [
        { person: JEFE_CONTABILIDAD, area_name: 'Contabilidad', company_id: SEGUREX.id },
        { person: PEOPLE.diego, area_name: 'Cartera', company_id: SEGUREX.id },
        { person: PEOPLE.cartera, area_name: 'Cartera', company_id: SEGUREX.id },
        { person: PEOPLE.paola, area_name: 'Ventas', company_id: SEGUREX.id },
    ],
    suggested_user_id: JEFE_CONTABILIDAD.id,
    max_upload_mb: 10,
    today: TODAY,
    now: NOW_ISO,
};

// ---- Time and costs -----------------------------------------------------------

type CostsPage = PageProps<ProjectsCostsProps>;

const costsJhon: CostsPage = {
    ...sharedFor('jhon'),
    project: header(P07),
    budget: P07.budget,
    curve: HOURS_CURVE_P07,
    remaining_hours: 42,
    recent_hours: 15.6,
    recent_weeks: 2,
    pace_note: 'porque el equipo reparte el tiempo con P-05 y P-06',
    week: { from: '2026-10-05', to: '2026-10-11' },
    entries: TIME_ENTRIES,
    total_by_person: TIME_BY_PERSON_P07.map((row) => ({ person: row.person, hours: row.total })),
    running_timers: [{ person: PEOPLE.jhon, task_key: 'T4', started_at: RUNNING_T4.started_at }],
    costs: COSTS_P07,
    tasks: taskRefs('P-07'),
    telegram_example: '/horas GIA-139 2 conexión con SAP',
    today: TODAY,
    now: NOW_ISO,
};

type TimeEntryPage = PageProps<TimeEntriesCreateProps>;

const timeEntry: TimeEntryPage = {
    ...sharedFor('jhon'),
    projects: (['P-07', 'P-05', 'P-06'] as const).map((key) => ({ key, name: projectByKey(key).name, tasks: taskRefs(key) })),
    defaults: { project_key: 'P-07', task_key: 'T7' },
    telegram_keys: { 'P-07': 'GIA-139', 'P-05': 'GIA-131', 'P-06': 'GIA-127' },
    today: TODAY,
    now: NOW_ISO,
};

// ---- Report -------------------------------------------------------------------

const RECIPIENTS_REPORT: ReportRecipient[] = [
    { person: PEOPLE.jhon, role_label: 'Líder IA', can_view_costs: true, include_costs: true },
    { person: PEOPLE.edward, role_label: 'Aprobador · Gerencia', can_view_costs: true, include_costs: true },
    { person: PEOPLE.leonardo, role_label: 'Gerente de TI', can_view_costs: true, include_costs: true },
    { person: PEOPLE.cartera, role_label: 'Solicitante · Cartera', can_view_costs: false, include_costs: false },
];

type ReportPage = PageProps<ProjectsReportProps>;

const reportJhon: ReportPage = {
    ...sharedFor('jhon'),
    project: header(P07),
    recipients: RECIPIENTS_REPORT,
    recipient_candidates: [
        { person: PEOPLE.diego, role_label: 'Auxiliar de cartera · Cartera', can_view_costs: false, include_costs: false },
        { person: PEOPLE.paola, role_label: 'Coordinadora de ventas · Ventas', can_view_costs: false, include_costs: false },
    ],
    schedule: { periodicity: 'weekly', weekday: 1, time: '07:00', last_sent_at: '2026-10-05T07:00:00-05:00', next_send_at: '2026-10-12T07:00:00-05:00' },
    sections: ['status', 'done', 'next', 'blockers', 'budget'],
    content: {
        as_of: TODAY,
        company_id: SEGUREX.id,
        project: { key: P07.key, name: P07.name, status: P07.status },
        requester_name: 'Gerencia de cartera',
        request_key: 'GIA-139',
        tasks: { done: 2, total: 9 },
        criteria: { done: 2, total: 5 },
        done: [
            'El filtro por zona quedó listo y está en revisión.',
            'La consulta por asesor ya trae datos de SAP en el ambiente de pruebas.',
            'Gerencia de cartera validó los filtros por asesor y por zona.',
        ],
        next: ['Agrupar la cartera por rango de días vencidos.', 'Pruebas con la cartera real junto con 3 asesores.'],
        blockers: [
            'Esperamos de Contabilidad el acceso de lectura a la vista de cartera en SAP. Vence el viernes 9 oct; sin eso, el total vencido por rango de días queda en pausa.',
        ],
        budget: P07.budget,
        project_url: '#ai.projects.show',
        sender_email: 'segurex.info@segurex.com',
    },
    pdf: { pages: 1, size: 184_320 },
    now: NOW_ISO,
};

// ---- variants -----------------------------------------------------------------

const leonardoNoCosts = withoutCosts('leonardo');

const blockedOnly: Task[] = P07_TASKS.filter((task) => task.blocked_by !== null);

export const variants: Record<
    string,
    | MockVariant<IndexPage>
    | MockVariant<ShowPage>
    | MockVariant<BoardPage>
    | MockVariant<TaskPage>
    | MockVariant<RequirementsPage>
    | MockVariant<RespondPage>
    | MockVariant<CostsPage>
    | MockVariant<TimeEntryPage>
    | MockVariant<ReportPage>
> = {
    // Index
    projects: { page: 'Ai/Projects/Index', label: 'Proyectos · todos (Jhon, Todas)', props: indexJhon },
    'projects-segurex': {
        page: 'Ai/Projects/Index',
        label: 'Proyectos · solo Segurex',
        props: { ...indexJhon, ...sharedFor('jhon', { currentCompanyId: SEGUREX.id }), projects: PROJECTS.filter((p) => p.company_id === SEGUREX.id).map(listItem) },
    },
    'projects-no-costs': {
        page: 'Ai/Projects/Index',
        label: 'Proyectos · sin permiso de costos',
        props: { ...indexJhon, ...leonardoNoCosts, projects: PROJECTS.map((project) => hideCost(listItem(project))) },
    },
    'projects-empty': {
        page: 'Ai/Projects/Index',
        label: 'Proyectos · filtro sin resultados',
        props: { ...indexJhon, projects: [], filters: { status: 'closed', company_id: null } },
    },

    // Summary
    summary: { page: 'Ai/Projects/Show', label: 'Resumen · P-07 pide nueva aprobación', props: summaryJhon },
    'summary-requested': {
        page: 'Ai/Projects/Show',
        label: 'Resumen · nueva aprobación ya pedida',
        props: { ...summaryJhon, reapproval_requested_at: '2026-10-07T09:20:00-05:00' },
    },
    'summary-no-costs': {
        page: 'Ai/Projects/Show',
        label: 'Resumen · sin permiso de costos (solo horas y fechas)',
        props: { ...summaryJhon, ...leonardoNoCosts, project: hideCost(P07) },
    },
    'summary-on-time': { page: 'Ai/Projects/Show', label: 'Resumen · P-06 a tiempo, en pruebas', props: summaryP06 },

    // Board
    board: { page: 'Ai/Projects/Board', label: 'Tablero · P-07 con cronómetro', props: boardJhon },
    'board-task': { page: 'Ai/Projects/Board', label: 'Tablero · detalle de T4 abierto', props: { ...boardJhon, selected_task: T4_DETAIL } },
    'board-task-blocked': { page: 'Ai/Projects/Board', label: 'Tablero · detalle de T7 bloqueada', props: { ...boardJhon, selected_task: T7_DETAIL } },
    'board-blocked': {
        page: 'Ai/Projects/Board',
        label: 'Tablero · solo bloqueadas',
        props: { ...boardJhon, tasks: blockedOnly, filters: { assignee_id: null, label: null, blocked: true } },
    },
    'board-no-timer': {
        page: 'Ai/Projects/Board',
        label: 'Tablero · sin cronómetro (Leonardo)',
        props: {
            ...boardJhon,
            ...sharedFor('leonardo'),
            tasks: P07_TASKS.map((task) => (task.key === 'T4' ? { ...task, timer: null } : task)),
            running_timer: null,
        },
    },

    // Task page
    task: { page: 'Ai/Tasks/Show', label: 'Tarea en celular · T4 con cronómetro', props: taskT4 },
    'task-stopped': {
        page: 'Ai/Tasks/Show',
        label: 'Tarea en celular · T7 bloqueada, sin cronómetro',
        props: { ...taskT4, task: T7_DETAIL, today_project_hours: 0 },
    },

    // Requirements
    requirements: { page: 'Ai/Projects/Requirements', label: 'Requerimientos · todos mis proyectos', props: requirementsAll },
    'requirements-project': {
        page: 'Ai/Projects/Requirements',
        label: 'Requerimientos · solo P-07',
        props: { ...requirementsAll, scope: 'project', requirements: sortRequirements(requirementsOf('P-07')) },
    },
    'requirements-empty': {
        page: 'Ai/Projects/Requirements',
        label: 'Requerimientos · ninguno',
        props: { ...requirementsAll, scope: 'project', requirements: [], project: { ...header(P07), open_requirements: 0 } },
    },

    // Respond
    respond: { page: 'Ai/Requirements/Respond', label: 'Responder requerimiento · pendiente (Contabilidad)', props: respondPending },
    'respond-overdue': {
        page: 'Ai/Requirements/Respond',
        label: 'Responder requerimiento · vencido',
        props: { ...respondPending, today: '2026-10-12', requirement: { ...respondPending.requirement, status: 'overdue' } },
    },
    'respond-answered': {
        page: 'Ai/Requirements/Respond',
        label: 'Responder requerimiento · ya respondido',
        props: {
            ...respondPending,
            requirement: {
                ...respondPending.requirement,
                status: 'answered',
                answered_at: '2026-10-07T11:05:00-05:00',
                answer: 'Creé el usuario CONSULTA_CARTERA con permiso solo de lectura en pruebas y en producción.',
                answer_attachment: { name: 'permiso-sap.png', size: 182_000 },
            },
        },
    },

    // Time and costs
    costs: { page: 'Ai/Projects/Costs', label: 'Tiempo y costos · P-07', props: costsJhon },
    'costs-empty-week': {
        page: 'Ai/Projects/Costs',
        label: 'Tiempo y costos · semana sin horas',
        props: { ...costsJhon, week: { from: '2026-10-12', to: '2026-10-18' }, entries: [], running_timers: [] },
    },
    'costs-no-permission': { page: 'Ai/Projects/Costs', label: 'Tiempo y costos · sin permiso de costos', props: { ...costsJhon, ...leonardoNoCosts } },
    'time-entry': { page: 'Ai/TimeEntries/Create', label: 'Agregar horas en celular · P-07 T7', props: timeEntry },
    'time-entry-blank': {
        page: 'Ai/TimeEntries/Create',
        label: 'Agregar horas en celular · sin proyecto elegido',
        props: { ...timeEntry, defaults: { project_key: null, task_key: null } },
    },

    // Report
    report: { page: 'Ai/Projects/Report', label: 'Informe · semanal, versión interna', props: reportJhon },
    'report-manual': {
        page: 'Ai/Projects/Report',
        label: 'Informe · manual, nunca enviado',
        props: { ...reportJhon, schedule: { periodicity: 'manual', weekday: 1, time: '07:00', last_sent_at: null, next_send_at: null } },
    },
    'report-no-costs': {
        page: 'Ai/Projects/Report',
        label: 'Informe · quien envía no tiene permiso de costos',
        props: {
            ...reportJhon,
            ...leonardoNoCosts,
            recipients: RECIPIENTS_REPORT.map((recipient) => ({ ...recipient, include_costs: false })),
            sections: ['status', 'done', 'next', 'blockers'],
            content: { ...reportJhon.content, budget: null },
        },
    },
};

