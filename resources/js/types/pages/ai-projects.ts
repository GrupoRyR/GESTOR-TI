/**
 * Gestor IA · project pages (boards B11–B17). Desktop-first except the task
 * page, the time entry and the requirement answer, which are phone-first.
 *
 * Inertia components and their props:
 * - `Ai/Projects/Index`         ProjectsIndexProps        project list (filters, WIP, mini budget bar with costs)
 * - `Ai/Projects/Show`          ProjectsShowProps         B-ProjectSummary (ProjectLayout tab "summary")
 * - `Ai/Projects/Board`         ProjectsBoardProps        B-Kanban + B-TaskDetail drawer + B-KanbanPhone (tab "board")
 * - `Ai/Tasks/Show`             TasksShowProps            B-TaskPhone (timer hero, subtasks, quick comment)
 * - `Ai/Projects/Requirements`  ProjectsRequirementsProps B-Requirements (tab "requirements")
 * - `Ai/Requirements/Respond`   RequirementsRespondProps  B-RequirementRespond, for the person who must answer (requester mode)
 * - `Ai/Projects/Costs`         ProjectsCostsProps        B-ProjectCosts (tab "costs"; whole page needs `ai.costs.view`)
 * - `Ai/TimeEntries/Create`     TimeEntriesCreateProps    B-TimeEntryPhone
 * - `Ai/Projects/Report`        ProjectsReportProps       B-ProjectReport (tab "report")
 *
 * Ziggy routes used:
 * - `ai.projects.index`            GET    query `{ status?: ProjectStatus, company_id? }` (null filters omitted)
 * - `ai.projects.show`             GET    `{ project: key }`                        row link, "Resumen" tab, breadcrumbs
 * - `ai.projects.reapproval.store` POST   `{ project: key }`                        "Pedir nueva aprobación" (sends to the approvers)
 * - `ai.projects.board`            GET    `{ project: key }` query `{ assignee_id?, label?, blocked?: 1, tarea?: task key }`
 *                                         filters use `router.get` (preserveState); clicking a card is a partial reload
 *                                         `only: ['selected_task', 'selected_task_github', 'selected_task_brief']` with
 *                                         `{ tarea: key }` (the URL keeps `?tarea=T4`).
 * - `ai.tasks.move`                PUT    `{ project: key, task: key }` body `{ column: KanbanColumnKey, position: number }` (1-based)
 *                                         drag and drop, and the keyboard "Mover a…" menu (position = end of the column)
 * - `ai.tasks.store`               POST   `{ project: key }` body `{ title: string, column: KanbanColumnKey }`  "Agregar tarea"
 * - `ai.tasks.update`              PUT    `{ project: key, task: key }` body `TaskUpdatePayload` (only the changed fields)
 * - `ai.tasks.show`                GET    `{ project: key, task: key }`             phone task page; "Abrir en página" in the drawer
 * - `ai.subtasks.toggle`           PATCH  `{ project: key, task: key, subtask: id }` body `{ done: boolean }`
 * - `ai.subtasks.store`            POST   `{ project: key, task: key }` body `{ title: string }`  "Agregar subtarea"
 * - `ai.tasks.comments.store`      POST   `{ project: key, task: key }` body `{ body: string }`
 * - `ai.tasks.attachments.store`   POST   `{ project: key, task: key }` multipart `{ file: File }`  "Adjuntar archivo"
 * - `ai.timer.start`               POST   `{ project: key, task: key }`             "Iniciar" (same contract as Mi semana)
 * - `ai.timer.stop`                POST   —                                         "Parar" (stops the viewer's running timer)
 * - `ai.projects.requirements`     GET    `{ project: key }` query `{ scope?: 'all' }`  "Todos mis proyectos" (omitted = only this one)
 * - `ai.requirements.store`        POST   `{ project: key }` body `RequirementStorePayload`  "Enviar requerimiento"
 * - `ai.requirements.remind`       POST   `{ requirement: id }`                     "Recordar" (same as Mi semana)
 * - `ai.requirements.respond`      GET    `{ requirement: id }`                     link in the e-mail / Telegram notice
 * - `ai.requirements.answer`       POST   `{ requirement: id }` multipart `{ answer: string, attachment: File | null }`
 * - `ai.requirements.reassign`     POST   `{ requirement: id }` body `{ user_id: number, note: string | null }`  "No me corresponde"
 * - `ai.projects.costs`            GET    `{ project: key }` query `{ week?: 'YYYY-MM-DD' }` (Monday of the week; omitted = this week)
 * - `ai.projects.costs.export`     GET    `{ project: key }` query `{ week: 'YYYY-MM-DD' }`  "Exportar" (Excel of the hours log)
 * - `ai.time-entries.create`       GET    query `{ project?: key, task?: key }`     "Agregar horas a mano" (drawer, phone)
 * - `ai.time-entries.store`        POST   `{ project: key }` body `TimeEntryPayload`  "Agregar horas" dialog and phone page
 * - `ai.costs.store`               POST   `{ project: key }` multipart `CostPayload`  "Agregar costo"
 * - `ai.projects.report`           GET    `{ project: key }`
 * - `ai.projects.report.pdf`       GET    `{ project: key }` query `{ version: ReportVersion }`  "Descargar PDF"
 * - `ai.projects.report.send`      POST   `{ project: key }` body `ReportPayload`    "Enviar ahora"
 * - `ai.projects.report.schedule`  PUT    `{ project: key }` body `ReportPayload`    "Programar"
 * - `ai.projects.docs`             GET    `{ project: key }`                        ProjectLayout tab
 * - `ai.projects.repository.store` POST  `{ project: key }` body `RepoLinkPayload`  "Vincular" in the repo dialog of the
 *                                         summary (Stage D, see ai-github.ts)
 * - `ai.tasks.issue.store`         POST   `{ project: key, task: key }` body `IssuePayload`  "Crear issue" (board drawer and
 *                                         task page). Back to the same page: the task page gets flash success, the board gets
 *                                         `issue_created` (toast) instead. The drawer partial reload becomes `only: ['selected_task',
 *                                         'selected_task_github']`.
 * - `ai.projects.repository.create` POST `{ project: key }` body `RepoCreatePayload`  Stage D · "Crear repositorio con
 *                                         este CLAUDE.md" in the brief panel of the summary (needs `ai.github.manage`,
 *                                         only while `project.repository` is null). The server creates a private repo
 *                                         in the organization with README.md, the given CLAUDE.md and docs/, links it to
 *                                         the project and comes back to `ai.projects.show` with flash success. Errors
 *                                         keyed `name` (invalid or taken) and `claude_md`.
 * - `ai.products.show`             GET    `{ product: slug }`                       "Ver actividad en GitHub" on the summary
 * - `ai.requests.show`             GET    `{ request: key }`                        linked request card, header
 * - `admin.costs.index`            GET    —                                         "Tarifas en Administración"
 * - `home`, `ai.requests.mine`     GET    —                                         Respond page (requester mode)
 *
 * Permissions:
 * - `ai.manage`       every page here except `Ai/Requirements/Respond` (any signed-in recipient).
 * - `ai.costs.view`   money: `ProjectBudget.cost`, `ProjectListItem.budget.cost`, the costs page, the report's
 *                     "Tiempo y costo". Without it the server sends `null` and the UI shows hours and dates only;
 *                     `Ai/Projects/Costs` renders NoPermission. Report recipients without it never get the internal version.
 * - Requesters never see costs, rates or internal notes: `RequirementForRecipient` carries none.
 * - `ai.github.manage` linking the repository and creating issues (Stage D). Without it `repo_link` and
 *                     `TaskGitHub.issue_form` are null and the buttons are hidden; GitHub data shows to `ai.manage`.
 *                     Also creating the repository from the brief: without it `repo_create` is null.
 *
 * Stage D · "Brief para Claude" (D-BriefTask, D-BriefProject, D-BriefPhone):
 * - Summary (`brief`, all six sections, "CLAUDE.md inicial" tab), board drawer (`selected_task_brief`) and task page
 *   (`brief`, 4 of 6 sections). No routes for copying or downloading: the page builds the Markdown with
 *   `lib/brief.ts` and copies or saves it (`P-07-brief.md`, `P-07-T4-brief.md`, `CLAUDE.md`) in the browser.
 *   The task brief adds the task's issue/PR from the GitHub prop and the suggested branch (`gia-139-t4`).
 * - Briefs are sent ONLY with `ai.manage`, filled with the whitelisted fields of `ProjectBriefInput` /
 *   `TaskBriefInput`: never vault items or links, credentials, costs, rates, hours logged or internal notes.
 *   `brief_omitted` only counts what was left out, for the note under the preview.
 */
import type { RunningTimer } from '@/types/pages/ai-triage';
import type {
    KanbanColumnKey,
    PersonRef,
    Project,
    ProjectCost,
    ProjectStatus,
    RequestSummary,
    Requirement,
    Task,
    TaskDetail,
    TimeEntry,
} from '@/types/ai';
import type { ProjectHeader } from '@/Layouts/ProjectLayout';
import type { GitHubIssue } from '@/types/github';
import type { RepoLinkSetup, TaskGitHub } from '@/types/pages/ai-github';
import type { BriefOpenState } from '@/types/pages/ai-triage';
import type { BriefOmitted, ProjectBriefInput, TaskBriefInput } from '@/lib/brief';

export type { BriefOpenState, ProjectHeader, RunningTimer };

export type WipCount = { in_development: number; limit: number };

export type TaskRef = Pick<Task, 'key' | 'title'>;

// ---- Index ------------------------------------------------------------------

/** Row of the project list. `budget.cost` is null without `ai.costs.view`. */
export type ProjectListItem = Pick<
    Project,
    'key' | 'name' | 'status' | 'company_id' | 'area' | 'request_keys' | 'team' | 'budget' | 'tasks_by_column' | 'blocked_tasks' | 'open_requirements'
>;

export interface ProjectFilters {
    status: ProjectStatus | null;
    /** Only used in "Todas". */
    company_id: number | null;
}

export interface ProjectsIndexProps {
    /** Active first (en desarrollo, en pruebas, planificada), then the rest. */
    projects: ProjectListItem[];
    filters: ProjectFilters;
    wip: WipCount;
    /** Server time (ISO). */
    now: string;
}

// ---- Summary ----------------------------------------------------------------

export type ActivityKind = 'task' | 'github' | 'requirement' | 'budget' | 'comment';

export interface ProjectActivity {
    id: number;
    kind: ActivityKind;
    /** Null for system events ("Aviso del 80 %…"). */
    actor: PersonRef | null;
    /** Server-written sentence without the actor: "movió T3 Endpoint de zonas a En revisión". */
    text: string;
    at: string;
}

export interface ProjectsShowProps {
    project: Project;
    /** Request that started the project, with who approved it. */
    origin_request: (RequestSummary & { approved_by: PersonRef | null; approved_at: string | null }) | null;
    /** Pending and overdue requirements of this project, soonest first. */
    open_requirements: Requirement[];
    /** Newest first, at most 6. */
    activity: ProjectActivity[];
    /** Holders of `ai.projects.approve`, named in the re-approval banner. */
    approvers: PersonRef[];
    /** Re-approval threshold from Administración (0.2 = 20 %). */
    reapproval_threshold: number;
    /** Set once someone asked for the new approval (the banner then says it is waiting). */
    reapproval_requested_at: string | null;
    /** "Sigue Entrega, con pruebas de Gerencia de cartera." Null when there is nothing to add. */
    next_step: string | null;
    /** Bogotá date (YYYY-MM-DD). */
    today: string;
    /** Stage D · "Vincular repositorio" when `project.repository` is null; null or omitted without `ai.github.manage`. */
    repo_link?: RepoLinkSetup | null;
    /** Stage D · data of "Brief para Claude"; only with `ai.manage`. Null or omitted hides the button. */
    brief?: ProjectBriefInput | null;
    /** Stage D · what the server left out of the brief ("el tiempo y costos y 2 notas internas"). */
    brief_omitted?: BriefOmitted | null;
    /** Stage D · initial state of the brief panel (`?brief=1` opens it); omitted = closed. */
    brief_state?: BriefOpenState | null;
    /** Stage D · "Crear repositorio con este CLAUDE.md": only with `ai.github.manage` and no repository yet; else null. */
    repo_create?: RepoCreateSetup | null;
    now: string;
}

export interface RepoCreateSetup {
    /** "GrupoRyR", shown as the fixed prefix of the name field. */
    organization: string;
    /** Proposed name, the project name as a slug ("toma-pedidos-descuentos-por-volumen"). */
    default_name: string;
}

/** Body of `ai.projects.repository.create`. */
export interface RepoCreatePayload {
    /** Repository name without the organization. */
    name: string;
    /** The CLAUDE.md shown in the panel, as Markdown. */
    claude_md: string;
}

// ---- Board ------------------------------------------------------------------

export interface BoardFilters {
    assignee_id: number | null;
    label: string | null;
    blocked: boolean;
}

export interface TaskUpdatePayload {
    column?: KanbanColumnKey;
    assignee_ids?: number[];
    due_at?: string | null;
    labels?: string[];
    title?: string;
    description?: string;
}

export interface ProjectsBoardProps {
    project: ProjectHeader;
    /** Tasks after the filters, every column. */
    tasks: Task[];
    /** Total tasks without filters ("9 tareas"). */
    total_tasks: number;
    /** Blocked tasks without filters (count on the "Solo bloqueadas" chip). */
    blocked_total: number;
    /** WIP limit per column; Stage B only limits "En progreso" (3). */
    column_limits: Partial<Record<KanbanColumnKey, number>>;
    filters: BoardFilters;
    /** People for the "Responsable" filter and the drawer's assignee picker. */
    people: PersonRef[];
    /** Labels in use in the project. */
    labels: string[];
    /** Task of `?tarea=` with its detail; null when the drawer is closed. */
    selected_task: TaskDetail | null;
    /** The viewer's running timer (any project), for the phone bar. */
    running_timer: RunningTimer | null;
    /** Stage D · GitHub block of the drawer task (issue, PR, create issue). Sent with `selected_task`; null when closed. */
    selected_task_github?: TaskGitHub | null;
    /** Stage D · brief data of the drawer task, sent with `selected_task` (`only` includes it); null when closed or without `ai.manage`. */
    selected_task_brief?: TaskBriefInput | null;
    /** Stage D · initial state of the drawer task's brief panel (gallery); omitted = closed. */
    selected_task_brief_state?: BriefOpenState | null;
    /** Stage D · right after "Crear issue": toast with the link and "nuevo" on the card. Null or omitted otherwise. */
    issue_created?: { task_key: string; issue: GitHubIssue; repo_full_name: string } | null;
    now: string;
}

// ---- Task page (phone) ------------------------------------------------------

export interface TasksShowProps {
    project: Pick<Project, 'key' | 'name' | 'company_id'>;
    task: TaskDetail;
    /** Hours the viewer logged today in this project, without the running timer. */
    today_project_hours: number;
    /** Stage D · PR card, history and "Crear issue en GitHub" (D-GitHubPhone). Null or omitted = no GitHub block. */
    github?: TaskGitHub | null;
    /** Stage D · data of "Brief para Claude"; only with `ai.manage`. Null or omitted hides the button. */
    brief?: TaskBriefInput | null;
    /** Stage D · initial state of the brief panel (`?brief=1` opens it); omitted = closed. */
    brief_state?: BriefOpenState | null;
    now: string;
}

// ---- Requirements -----------------------------------------------------------

/** Someone a requirement can go to. */
export interface RecipientOption {
    person: PersonRef;
    area_name: string;
    company_id: number;
}

export interface RequirementStorePayload {
    /** "Qué necesitas". */
    what: string;
    recipient_id: number | null;
    due_date: string;
    /** Task key in the chosen project; null = blocks nothing. */
    blocks_task: string | null;
}

export type RequirementScope = 'project' | 'all';

export interface ProjectsRequirementsProps {
    project: ProjectHeader;
    /** Sorted by due date; `scope: 'all'` adds the other projects of the viewer. */
    requirements: Requirement[];
    scope: RequirementScope;
    /** Projects the viewer works on (segment label "P-07 y P-05", form "Proyecto"), with their open tasks. */
    my_projects: (Pick<Project, 'key' | 'name'> & { tasks: TaskRef[] })[];
    recipients: RecipientOption[];
    /** Reminder cadence in days (configured; 2). */
    reminder_every_days: number;
    today: string;
    now: string;
}

// ---- Respond (person who must answer) ---------------------------------------

/** What the recipient sees: no costs, no internal notes, no task names. */
export interface RequirementForRecipient {
    id: number;
    title: string;
    detail: string | null;
    due_date: string;
    status: Requirement['status'];
    requested_by: PersonRef;
    project: {
        name: string;
        /** Plain-language "why": "Es la herramienta que pidió la Gerencia de cartera…". */
        context: string;
    };
    answered_at: string | null;
    answer: string | null;
    answer_attachment: { name: string; size: number } | null;
}

export interface RequirementsRespondProps {
    requirement: RequirementForRecipient;
    /** Coworkers the viewer can pass it to; `suggested` first. */
    reassign_candidates: RecipientOption[];
    /** Person IT thinks can answer when the viewer cannot; null when none. */
    suggested_user_id: number | null;
    /** Max upload in MB (10). */
    max_upload_mb: number;
    today: string;
    now: string;
}

// ---- Time and costs ---------------------------------------------------------

export interface HoursCurve {
    plan: { date: string; value: number }[];
    actual: { date: string; value: number }[];
    projection: { date: string; value: number }[];
}

export interface TimeEntryPayload {
    task_key: string | null;
    date: string;
    hours: number;
    note: string;
}

export interface CostPayload {
    type: ProjectCost['type'] | '';
    concept: string;
    recurrence: Exclude<ProjectCost['recurrence'], 'computed'>;
    amount: number | null;
    date: string;
    attachment: File | null;
}

export interface ProjectsCostsProps {
    project: ProjectHeader;
    /** Always with `cost` here (the page needs `ai.costs.view`). */
    budget: Project['budget'];
    curve: HoursCurve;
    /** Hours the open tasks still need (team estimate). */
    remaining_hours: number;
    /** Hours logged in the last `recent_weeks` weeks, for the pace. */
    recent_hours: number;
    recent_weeks: number;
    /** "porque el equipo reparte el tiempo con P-05 y P-06"; null when nothing to explain. */
    pace_note: string | null;
    /** Monday–Sunday shown in the log. */
    week: { from: string; to: string };
    /** Entries of that week, newest first. */
    entries: TimeEntry[];
    /** Whole-project hours per person. */
    total_by_person: { person: PersonRef; hours: number }[];
    /** Timers running right now on this project (not in the log until stopped). */
    running_timers: { person: PersonRef; task_key: string; started_at: string }[];
    costs: ProjectCost[];
    /** Open tasks for the "Agregar horas" dialog. */
    tasks: TaskRef[];
    /** Telegram example: "/horas GIA-139 2 conexión con SAP". */
    telegram_example: string;
    today: string;
    now: string;
}

export interface TimeEntriesCreateProps {
    projects: (Pick<Project, 'key' | 'name'> & { tasks: TaskRef[] })[];
    /** Preselected from `?project=&task=`. */
    defaults: { project_key: string | null; task_key: string | null };
    /** Request key used in the Telegram example of the selected project. */
    telegram_keys: Record<string, string>;
    today: string;
    now: string;
}

// ---- Report -----------------------------------------------------------------

export type ReportVersion = 'internal' | 'requester';

export type ReportSection = 'status' | 'done' | 'next' | 'blockers' | 'budget';

export interface ReportRecipient {
    person: PersonRef;
    /** "Líder IA", "Aprobador · Gerencia", "Solicitante · Cartera". */
    role_label: string;
    can_view_costs: boolean;
    /** Gets "Tiempo y costo" (ignored without `can_view_costs`). */
    include_costs: boolean;
}

export interface ReportSchedule {
    periodicity: 'manual' | 'weekly';
    /** 1 = lunes … 7 = domingo. */
    weekday: number;
    /** "07:00". */
    time: string;
    last_sent_at: string | null;
    next_send_at: string | null;
}

/** Content of the report as of `as_of`; the e-mail and the PDF use the same data. */
export interface ReportContent {
    as_of: string;
    company_id: number;
    project: Pick<Project, 'key' | 'name' | 'status'>;
    requester_name: string;
    request_key: string | null;
    tasks: { done: number; total: number };
    criteria: { done: number; total: number };
    done: string[];
    next: string[];
    blockers: string[];
    /** Null without `ai.costs.view` (the requester version never shows it). */
    budget: Project['budget'] | null;
    project_url: string;
    sender_email: string;
}

export interface ReportPayload {
    recipients: { user_id: number; include_costs: boolean }[];
    periodicity: ReportSchedule['periodicity'];
    weekday: number;
    time: string;
    sections: ReportSection[];
    version: ReportVersion;
}

export interface ProjectsReportProps {
    project: ProjectHeader;
    recipients: ReportRecipient[];
    /** People who can be added (followers, area owners). */
    recipient_candidates: ReportRecipient[];
    schedule: ReportSchedule;
    sections: ReportSection[];
    content: ReportContent;
    pdf: { pages: number; size: number };
    now: string;
}

