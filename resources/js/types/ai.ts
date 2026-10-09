/**
 * Gestor IA domain types (Stage B). Every Stage B page receives these shapes
 * from Laravel. Conventions:
 * - Dates: ISO 8601 strings (`YYYY-MM-DD` for plain dates, full date-time
 *   with offset for instants). The UI formats them in America/Bogota.
 * - Money: integer Colombian pesos.
 * - Cost fields are `null` when the viewer lacks `ai.costs.view`; the server
 *   must omit the real value, never just hide it.
 * - Requesters never receive `internal_notes`, rates or costs.
 */
import type { RequestStatusKey } from '@/lib/status';
import type { EffortSize, Urgency } from '@/lib/priority';
import type { RequestType } from '@/lib/wizard';

export type { EffortSize, RequestType, Urgency };

// ---- shared refs ----------------------------------------------------------

export interface PersonRef {
    id: number;
    name: string;
    /** "Coordinadora de ventas". */
    job_title?: string | null;
    avatar_url?: string | null;
}

export interface AreaRef {
    id: number;
    name: string;
    company_id: number;
}

export interface ProductRef {
    slug: string;
    name: string;
    /** Version the requester was on, e.g. "v1.8.2". */
    version: string;
}

export interface FileRef {
    id: number;
    name: string;
    /** Bytes. */
    size: number;
    mime: string;
    /** Signed download URL. */
    url: string;
}

// ---- requests -------------------------------------------------------------

/** Row in inbox, backlog, roadmap and "Mis solicitudes". */
export interface RequestSummary {
    /** "GIA-148". */
    key: string;
    title: string;
    type: RequestType;
    status: RequestStatusKey;
    company_id: number;
    area: Pick<AreaRef, 'id' | 'name'>;
    requester: PersonRef;
    /** Time each person loses per week today (requester's estimate). */
    hours_per_week: number | null;
    people: number | null;
    /** +1 votes from other people. */
    votes: number;
    /** TI effort size; null until evaluated. */
    size: EffortSize | null;
    /** Formula value (lib/priority); null until evaluated. */
    priority_suggested: number | null;
    /** What TI decided; equals the suggested one unless someone changed it. */
    priority_final: number | null;
    urgency: Urgency | null;
    /** First-response SLA: 1 business day from creation. */
    first_response: { due_at: string; answered_at: string | null };
    /** App the request is about (improvement / bug). */
    product: ProductRef | null;
    /** Project key once converted ("P-07"). */
    project_key: string | null;
    /** Position in the area roadmap / global backlog, when queued. */
    rank: number | null;
    /** Key of a request this one probably duplicates. */
    similar_to: { key: string; title: string; votes: number } | null;
    created_at: string;
}

export interface WizardAnswers {
    problem: string;
    current_process: string | null;
    expected_result: string;
    success_criteria: string | null;
    /** Display names: "SAP Business One", "Excel". */
    systems: string[];
    systems_notes: string | null;
    deadline: string | null;
    deadline_reason: string | null;
}

export interface AcceptanceCriterion {
    id: number;
    text: string;
    /** Validated by the requester while in testing. */
    validated: { by: string; at: string } | null;
    /** "Depende de T4 y T7". */
    note: string | null;
}

/** Timeline event family: drives the icon tone (status families). */
export type TimelineFamily = 'gray' | 'amber' | 'blue' | 'green' | 'slate' | 'red';

export type TimelineKind =
    | 'status'
    | 'question'
    | 'answer'
    | 'approval'
    | 'rejection'
    | 'comment'
    | 'notification'
    | 'estimate'
    | 'merge'
    | 'priority';

export interface TimelineEvent {
    id: number;
    kind: TimelineKind;
    family: TimelineFamily;
    /** "Aprobada por Edward Ramanan". */
    title: string;
    /** Optional quoted content (a question, an answer, a reason). */
    quote: string | null;
    /** Secondary line after the date: "40 h · $ 6.400.000 · entrega 31 oct". */
    detail: string | null;
    actor: string | null;
    at: string;
}

export interface Comment {
    id: number;
    author: PersonRef;
    /** "TI" badge on IT staff messages in the public conversation. */
    is_it: boolean;
    body: string;
    created_at: string;
}

/** Full request: requester view omits `internal_notes` and `estimate.cost`. */
export interface RequestDetail extends RequestSummary {
    answers: WizardAnswers;
    attachments: FileRef[];
    criteria: AcceptanceCriterion[];
    timeline: TimelineEvent[];
    comments: Comment[];
    /** Only with `ai.manage`; absent for requesters. */
    internal_notes?: Comment[];
    estimate: Estimate | null;
    approval: Approval | null;
    /** Requester satisfaction after production. */
    rating: RequestRating | null;
    /** People following (requester + +1 voters + IT). */
    followers_count: number;
}

export interface RequestRating {
    solved: 1 | 2 | 3 | 4 | 5;
    ease: 1 | 2 | 3 | 4 | 5;
    comment: string | null;
    /** Hours/week the requester says it saves 30 days after release. */
    hours_saved_per_week: number | null;
    rated_at: string;
}

// ---- estimate & approval --------------------------------------------------

export interface Estimate {
    hours_by_person: { person: PersonRef; hours: number }[];
    hours_total: number;
    /** Licences, external providers… Null without `ai.costs.view`. */
    external_costs: number | null;
    due_date: string;
    /** Hours × internal rates + external costs. Null without `ai.costs.view`. */
    total_cost: number | null;
}

export type ApprovalDecision = 'pending' | 'approved' | 'rejected';

export interface Approval {
    decision: ApprovalDecision;
    /** Who may decide (holders of `ai.projects.approve`): Jhon Castro, Edward Ramanan. */
    approvers: PersonRef[];
    decided_by: PersonRef | null;
    decided_at: string | null;
    /** Required to reject. */
    comment: string | null;
    requested_by: PersonRef;
    requested_at: string;
    /** Re-approval after a budget/date increase above the threshold. */
    is_reapproval: boolean;
}

// ---- projects -------------------------------------------------------------

export type ProjectStatus = Extract<RequestStatusKey, 'planned' | 'in_development' | 'in_testing' | 'in_production' | 'closed'>;

export interface ProjectBudget {
    hours: { estimate: number; actual: number };
    /** Null without `ai.costs.view`. */
    cost: { estimate: number; actual: number } | null;
    /** Approved due date vs. date projected at the current pace. */
    due_date: string;
    projected_due_date: string;
    approved_at: string;
    /** Server-computed with lib/budget rules and the configured threshold. */
    needs_reapproval: boolean;
}

export interface Project {
    /** "P-07". */
    key: string;
    name: string;
    status: ProjectStatus;
    objective: string;
    request_keys: string[];
    company_id: number;
    area: Pick<AreaRef, 'id' | 'name'>;
    benefited_areas: { area: Pick<AreaRef, 'id' | 'name'>; note: string | null }[];
    team: { person: PersonRef; role: string }[];
    /** Global WIP: projects "En desarrollo" vs. the limit (3). */
    wip: { in_development: number; limit: number };
    budget: ProjectBudget;
    repository: { url: string; branch: string; open_prs: number; last_change_at: string } | null;
    tasks_by_column: Record<KanbanColumnKey, number>;
    blocked_tasks: number;
    criteria: AcceptanceCriterion[];
    open_requirements: number;
    product_slug: string | null;
    started_at: string | null;
}

// ---- kanban ---------------------------------------------------------------

export type KanbanColumnKey = 'backlog' | 'todo' | 'in_progress' | 'review' | 'done';

export interface GithubLink {
    issue: { number: number; state: 'open' | 'closed' } | null;
    pr: { number: number; state: 'open' | 'review' | 'merged' | 'closed' } | null;
    ci: 'passing' | 'failing' | 'running' | null;
}

export interface TaskTimer {
    running: boolean;
    /** Instant the current run started (only while running). */
    started_at: string | null;
    /** Who runs it: a timer belongs to one person. */
    person_id: number;
}

export interface Task {
    /** "T4" (unique inside its project). */
    key: string;
    project_key: string;
    title: string;
    column: KanbanColumnKey;
    labels: string[];
    assignees: PersonRef[];
    due_at: string | null;
    subtasks_done: number;
    subtasks_total: number;
    /** Requirement this task waits on; the card shows it as blocked. */
    blocked_by: Pick<Requirement, 'id' | 'title' | 'area_name'> | null;
    github: GithubLink | null;
    timer: TaskTimer | null;
    /** Order inside its column. */
    position: number;
}

export interface Subtask {
    id: number;
    title: string;
    done: boolean;
}

export interface TaskDetail extends Task {
    description: string;
    subtasks: Subtask[];
    criteria: AcceptanceCriterion[];
    comments: Comment[];
    /** Per person; hours only. */
    time_by_person: { person: PersonRef; hours: number }[];
    attachments: FileRef[];
    started_at: string | null;
}

// ---- requirements ---------------------------------------------------------

export type RequirementStatus = 'pending' | 'overdue' | 'answered';

/** Something IT needs from a user (data, access, validation). */
export interface Requirement {
    id: number;
    project_key: string;
    title: string;
    /** Free text the recipient sees. */
    detail: string | null;
    recipient: PersonRef | null;
    /** Placeholder when the person is not known yet: "[RESPONSABLE MERCADEO]". */
    recipient_label: string;
    area_name: string;
    company_id: number;
    due_date: string;
    status: RequirementStatus;
    reminders_sent: number;
    blocks_task: Pick<Task, 'key' | 'title'> | null;
    answered_at: string | null;
    answer: string | null;
    created_at: string;
}

// ---- time & costs ---------------------------------------------------------

export type TimeEntryOrigin = 'timer' | 'manual' | 'telegram';

export interface TimeEntry {
    id: number;
    date: string;
    person: PersonRef;
    project_key: string;
    /** Null = project time without a task. */
    task: Pick<Task, 'key' | 'title'> | null;
    hours: number;
    origin: TimeEntryOrigin;
    note: string;
}

export type CostType = 'team' | 'license' | 'hosting' | 'ai_usage' | 'external_provider' | 'other';

export type CostRecurrence = 'once' | 'monthly' | 'computed';

/** Only ever sent to holders of `ai.costs.view`. */
export interface ProjectCost {
    id: number;
    type: CostType;
    concept: string;
    detail: string | null;
    recurrence: CostRecurrence;
    amount: number;
    date: string;
    attachment: FileRef | null;
    /** `ai_usage` and `team` rows are computed, not editable. */
    editable: boolean;
}

// ---- products -------------------------------------------------------------

export type ProductState = 'production' | 'pilot' | 'development' | 'retired';

export interface ProductVersion {
    tag: string;
    date: string;
    summary: string;
    request_keys: { key: string; title: string }[];
    current: boolean;
}

export interface Product {
    slug: string;
    name: string;
    company_id: number;
    description: string;
    state: ProductState;
    version: string;
    /** "Next.js". */
    stack: string;
    /** "Vercel", "GoDaddy", "Equipo local". */
    host: string;
    areas: string[];
    /** Person or area; placeholder "[DUEÑO]" while unknown. */
    owner: string;
    url: string | null;
    repository: string | null;
    ai_model: string | null;
    rating: { average: number; count: number } | null;
    hours_saved_per_month: number | null;
    /** Null without `ai.costs.view`. */
    monthly_cost: number | null;
    open_requests: number;
    /** Monitoring arrives later; Stage B always shows "Monitoreo aún no activo". */
    monitoring_active: boolean;
}

export interface ProductDetail extends Product {
    versions: ProductVersion[];
    next_version: { tag: string; request_key: string } | null;
    requests: RequestSummary[];
    docs: DocSummary[];
    requests_from_button_this_month: number;
}

// ---- documentation --------------------------------------------------------

export type DocTemplate = 'project_sheet' | 'requirements' | 'decisions' | 'user_manual' | 'technical_manual' | 'prompts' | 'architecture' | 'blank';

export interface DocSummary {
    id: number;
    title: string;
    template: DocTemplate;
    /** Belongs to a project or a product. */
    owner: { kind: 'project' | 'product'; key: string; name: string };
    version: number;
    /** "No aplica" docs (e.g. prompts for an app without AI). */
    not_applicable: boolean;
    updated_at: string;
}

export interface Doc extends DocSummary {
    markdown: string;
    updated_by: PersonRef;
    visibility: 'company' | 'it' | 'project';
    /** Path in the repository when synced (`docs/manual.md`). */
    repository_path: string | null;
}

export interface DocVersion {
    version: number;
    summary: string;
    author: PersonRef;
    created_at: string;
    current: boolean;
}

export type DiffKind = 'same' | 'add' | 'remove';

/** One line of a server-computed diff between two doc versions. */
export interface DiffLine {
    kind: DiffKind;
    /** Line number in the older version (null for added lines). */
    old_line: number | null;
    /** Line number in the newer version (null for removed lines). */
    new_line: number | null;
    text: string;
}

// ---- vault ----------------------------------------------------------------

export type VaultEnvironment = 'production' | 'testing';

export type VaultSecretType = 'api_key' | 'user_password' | 'connection_string' | 'token';

/**
 * Credential metadata. There is deliberately NO value field: secrets travel
 * only inside `VaultRevealed`, after identity confirmation, one at a time.
 */
export interface VaultItem {
    id: number;
    name: string;
    product: string;
    environment: VaultEnvironment;
    type: VaultSecretType;
    username: string | null;
    usage_url: string | null;
    /** Null = no expiry. */
    expires_at: string | null;
    /** "Falta recibirla" and similar states. */
    expiry_note: string | null;
    rotation_days: number | null;
    notes: string | null;
}

/** Response of the reveal endpoint: the only shape that carries a secret. */
export interface VaultRevealed {
    item_id: number;
    value: string;
    /** The UI hides the value at this instant (30 s after reveal). */
    visible_until: string;
}

export type VaultAction = 'viewed' | 'copied' | 'changed' | 'created' | 'blocked';

export interface VaultAccessLog {
    id: number;
    person: PersonRef;
    action: VaultAction;
    item_name: string;
    at: string;
    /** "Edge en Windows · red de Segurex". */
    from: string;
}

export interface VaultSession {
    /** Identity confirmed with Microsoft at… */
    confirmed_at: string;
    /** Auto-lock instant (10 min without use). */
    locks_at: string;
}

// ---- areas & value --------------------------------------------------------

export type SemaphoreLevel = 'ok' | 'attention' | 'late';

export interface AreaSummary {
    area: AreaRef;
    owner: string;
    semaphore: SemaphoreLevel;
    /** Why the semaphore is not green. */
    reason: string | null;
    products_in_use: number;
    projects_in_progress: number;
    backlog: number;
    hours_saved_per_month: number | null;
}

export interface MonthPoint {
    /** YYYY-MM. */
    month: string;
    value: number;
}

export interface ValueMetrics {
    period: { from: string; to: string; label: string };
    updated_at: string;
    hours_saved_per_month: { current: number; delta: number; delta_label: string };
    satisfaction: { average: number; ratings: number; apps: number };
    lead_time_days: { current: number; delta: number };
    /** Null without `ai.costs.view`. */
    monthly_cost: number | null;
    hours_series: MonthPoint[];
    hours_by_area: { area: string; hours: number }[];
    requests_by_status: { status: RequestStatusKey; count: number }[];
    /** Null without `ai.costs.view`. */
    roi: { product: string; hours_per_month: number; total_cost: number; saving_per_month: number; payback_months: number }[] | null;
    hour_value: number | null;
}
