/**
 * Gestor IA · IT triage pages (boards B5–B10). Desktop-first, usable on
 * tablet and phone; approvals are phone-first (they open from Telegram).
 *
 * Inertia components and their props:
 * - `Ai/Inbox/Index`      InboxIndexProps      "Bandeja de solicitudes" (desktop table, phone cards, empty)
 * - `Ai/Requests/Show`    ItShowProps          request detail with `view: 'it'` (union in RequestsShowProps)
 * - `Ai/Approvals/Index`  ApprovalsIndexProps  "Esperan tu aprobación" (list + selected detail + history)
 * - `Ai/Approvals/Show`   ApprovalsShowProps   one approval, phone-first; `decision` shows the done screen
 * - `Ai/Backlog/Index`    BacklogIndexProps    "Backlog priorizado" (manual order)
 * - `Ai/Areas/Index`      AreasIndexProps      "Por área" (company switch, selected area)
 * - `Ai/Week/Index`       WeekIndexProps       "Mi semana" (three columns, timer, weekly review)
 *
 * Ziggy routes used:
 * - `ai.inbox.index`             GET    query `{ quick?: InboxQuick, area_id?, company_id?, page? }`  quick chips and filters
 *                                       (`quick=all` and null filters are omitted)
 * - `ai.requests.show`           GET    `{ request: key }`                       "Evaluar", row link, cards, week items
 * - `ai.requests.show`           GET    partial reload `only: ['merge_candidates']`, data `{ q: string }`
 *                                       MergeDialog: once on open (`q` = suggested duplicate key or the title), then while
 *                                       typing (debounced 400 ms, from 3 characters). Same contract on
 *                                       `ai.inbox.index` when the dialog opens from the inbox.
 * - `ai.requests.evaluation.update` PUT `{ request: key }` body `EvaluationPayload`  "Guardar evaluación" (counts as first response)
 * - `ai.requests.comments.store` POST   `{ request: key }` multipart `{ body, attachments: File[] }`  public reply (IT tab)
 * - `ai.requests.notes.store`    POST   `{ request: key }` body `{ body: string }`  "Guardar nota interna" (only `ai.manage`)
 * - `ai.requests.reject`         POST   `{ request: key }` body `RejectPayload`     RejectDialog
 * - `ai.requests.ask`            POST   `{ request: key }` body `{ question: string }`  AskInfoDialog; status → needs_info
 * - `ai.requests.bulk.ask`       POST   body `{ keys: string[], question: string }`  AskInfoDialog from the bulk bar
 * - `ai.requests.bulk.assign`    POST   body `{ keys: string[], user_id: number }`   bulk bar "Asignar a…"
 * - `ai.requests.merge`          POST   `{ request: key }` body `{ into: key }`       MergeDialog, duplicate banner "Unir"
 * - `ai.requests.approval.store` POST   `{ request: key }` body `SendToApprovalPayload`  SendToApprovalDialog
 * - `ai.requests.convert`        POST   `{ request: key }` body `{ name: string, start_date: 'YYYY-MM-DD' }`  ConvertToProjectDialog
 * - `ai.approvals.index`         GET    query `{ request?: key }`; selecting keeps the list (`only: ['selected']`)
 * - `ai.approvals.show`          GET    `{ request: key }` query `{ desde?: 'telegram' }`  Telegram button lands here
 * - `ai.approvals.approve`       POST   `{ request: key }` body `{ comment: string|null }`
 * - `ai.approvals.reject`        POST   `{ request: key }` body `{ comment: string }` (required)
 * - `ai.approvals.remind`        POST   `{ request: key }`                          Mi semana "Recordar" on an approval
 * - `ai.backlog.index`           GET    query `{ area_id?, company_id? }`
 * - `ai.backlog.reorder`         PUT    body `{ order: string[] }` (request keys, rank 1 first)
 * - `ai.backlog.manual`          DELETE —                                           "Volver al orden sugerido"
 * - `ai.areas.index`             GET    query `{ company_id, area_id? }`             company switch, area cards (`only: ['selected_area']`)
 * - `ai.week.index`              GET    —
 * - `ai.timer.start`             POST   `{ project: key, task: key }`               "Iniciar" (task keys are unique per project)
 * - `ai.timer.stop`              POST   —                                           "Parar" (stops the viewer's running timer)
 * - `ai.requirements.remind`     POST   `{ requirement: id }`                        "Recordar a Mercadeo"
 * - `ai.requests.remind`         POST   `{ request: key }`                           "Recordar" (question or validation waiting on a user)
 * - `ai.reviews.store`           POST   body `{ decided: string[], notes: string|null }`  "Registrar lo decidido"
 * - `ai.projects.show`           GET    `{ project: key }`                          project links (areas, week)
 * - `ai.products.show`           GET    `{ product: slug }`                         product links (areas)
 * - `ai.roadmap.index`           GET    —                                           inbox header
 * - `admin.areas.index`          GET    —                                           "Agregar" an area hour value (needs `ai.costs.view`)
 *
 * Permissions:
 * - `ai.manage`           every page here except approvals; IT actions, internal notes.
 * - `ai.projects.approve` Approvals pages and their buttons (Jhon Castro, Edward Ramanan). Without it: NoPermission.
 * - `ai.costs.view`       costs, hourly rates, area hour value, payback. Without it the server sends `null`
 *                         (`Estimate.total_cost`, `external_costs`, `TeamMember.hourly_rate`, `area_hour_value`,
 *                         `payback_months`, `AreaProject.cost`) and the UI hides those blocks.
 */
import type { SemaphoreLevel } from '@/Components/ui/Semaphore';
import type { EffortSize, Urgency } from '@/lib/priority';
import type {
    AreaRef,
    AreaSummary,
    Approval,
    Estimate,
    PersonRef,
    Product,
    ProjectBudget,
    ProjectStatus,
    RequestDetail,
    RequestSummary,
    TimelineEvent,
} from '@/types/ai';
import type { Company, Paginated } from '@/types/shared';

export type AreaOption = Pick<AreaRef, 'id' | 'name' | 'company_id'>;

/** Person who can estimate work. `hourly_rate` is null without `ai.costs.view`. */
export interface TeamMember {
    person: PersonRef;
    hourly_rate: number | null;
}

/** Data every action dialog needs (inbox and IT detail). */
export interface TriageActionData {
    /** IT people for the estimate rows and the bulk "Asignar a…". */
    team: TeamMember[];
    /** Holders of `ai.projects.approve` (Jhon Castro, Edward Ramanan). */
    approvers: PersonRef[];
    /** MergeDialog results; filled by the `only: ['merge_candidates']` partial reload. */
    merge_candidates?: RequestSummary[];
}

// ---- Inbox ------------------------------------------------------------------

export type InboxQuick = 'unanswered' | 'needs_info' | 'pending_approval' | 'all';

export interface InboxFilters {
    quick: InboxQuick;
    area_id: number | null;
    /** Only used in "Todas"; the selected company otherwise. */
    company_id: number | null;
}

export interface InboxIndexProps extends TriageActionData {
    /** Ordered by first response: unanswered (soonest due first), then the rest. */
    requests: Paginated<RequestSummary>;
    filters: InboxFilters;
    counts: Record<InboxQuick, number>;
    /** Areas of the companies in view (company_id tells them apart in "Todas"). */
    areas: AreaOption[];
    /** Configured first-response window in business days (example: 3). */
    first_response_business_days: number;
    /** Server time (ISO) the deadline and waiting labels are measured from; avoids device clock skew. */
    now: string;
    /** Last request answered, for the positive empty state; null when none. */
    last_answered: { key: string; created_at: string; answered_at: string } | null;
}

// ---- Request detail (IT view) ------------------------------------------------

export type RiskLevel = 'none' | 'low' | 'medium' | 'high';

export interface Evaluation {
    impact: {
        hours_per_week: number | null;
        people: number | null;
        /** Server-written money impact ("$ 2.100.000 al mes"); null without an area hour value or `ai.costs.view`. */
        money: string | null;
        risk: RiskLevel;
    };
    size: EffortSize | null;
    urgency: Urgency | null;
    /** Stored suggestion (lib/priority); the panel recomputes it live while editing. */
    priority_suggested: number | null;
    /** Null until IT saves an evaluation. */
    priority_final: number | null;
}

export interface EvaluationPayload {
    size: EffortSize | null;
    urgency: Urgency | null;
    risk: RiskLevel;
    /** Live suggestion at save time (the server recomputes it too). */
    priority_suggested: number | null;
    priority_final: number | null;
}

export interface ItShowProps extends TriageActionData {
    view: 'it';
    /** Full detail including `internal_notes` (only for `ai.manage`). */
    request: RequestDetail;
    evaluation: Evaluation;
    /** Possible duplicates (first one shows the banner). */
    duplicates: RequestSummary[];
    /** Hour value of the request's area (COP); null when unset or without `ai.costs.view`. */
    area_hour_value: number | null;
    first_response: { due_at: string; answered_at: string | null };
    first_response_business_days: number;
    /** Server time (ISO) the deadline and waiting labels are measured from; avoids device clock skew. */
    now: string;
}

export type RejectReason = 'exists' | 'not_it' | 'cost' | 'duplicate' | 'other';

export interface RejectPayload {
    reason: RejectReason | '';
    message: string;
}

export interface SendToApprovalPayload {
    hours: { person_id: number; hours: number }[];
    /** COP; 0 when none. */
    external_costs: number;
    due_date: string;
    approver_ids: number[];
    /** "Si apruebas tú, puedes pedir la del otro": true waits for every selected approver. */
    require_all: boolean;
}

// ---- Approvals --------------------------------------------------------------

/** Row of "Esperan tu aprobación". */
export interface ApprovalSummary {
    key: string;
    title: string;
    company_id: number;
    /** When it was sent to approval. */
    requested_at: string;
    hours_total: number;
    /** Null without `ai.costs.view`. */
    total_cost: number | null;
    due_date: string;
}

export interface ApprovalDetail {
    request: RequestSummary & {
        /** The requester's problem, shown as the description. */
        problem: string;
    };
    estimate: Estimate;
    approval: Approval;
    /** Who evaluated it and when. */
    evaluation: { by: PersonRef; at: string; urgency: Urgency | null; size: EffortSize; priority: number };
    /** Requester's hours/week × people. */
    hours_saved_per_week: number | null;
    /** Months to pay back the cost with the hours saved; null = the area has no hour value. Hidden without `ai.costs.view`. */
    payback_months: number | null;
    /** Newest first. */
    history: TimelineEvent[];
}

export interface ApprovalsIndexProps {
    pending: ApprovalSummary[];
    /** Detail of `?request=` or the first pending one; null when nothing waits. */
    selected: ApprovalDetail | null;
    /** Projects "En desarrollo" vs. the WIP limit, for the note under the list. */
    wip: { in_development: number; limit: number };
    /** Server time (ISO) the deadline and waiting labels are measured from; avoids device clock skew. */
    now: string;
}

export interface ApprovalDecision {
    result: 'approved' | 'rejected';
    at: string;
    comment: string | null;
}

export interface ApprovalsShowProps {
    approval: ApprovalDetail;
    /** Opened from the Telegram button ("Desde Telegram" in the header). */
    from_telegram: boolean;
    wip: { in_development: number; limit: number };
    /** Set after deciding: shows the done screen with the Telegram confirmation preview. */
    decision: ApprovalDecision | null;
    /** Server time (ISO) the deadline and waiting labels are measured from; avoids device clock skew. */
    now: string;
}

// ---- Backlog ----------------------------------------------------------------

export interface BacklogFilters {
    area_id: number | null;
    company_id: number | null;
}

export interface ManualOrder {
    by: PersonRef;
    at: string;
    /** "Jhon movió GIA-102 arriba de GIA-121". */
    moved_key: string;
    above_key: string | null;
    note: string | null;
}

export interface BacklogIndexProps {
    /** Ranked (rank 1 first); every item has `size` and priorities. */
    items: RequestSummary[];
    /** Null while the order is the suggested one. */
    manual_order: ManualOrder | null;
    wip: { in_development: number; limit: number };
    filters: BacklogFilters;
    areas: AreaOption[];
    /** Server time (ISO) for "hoy 8:40 a. m." in the manual-order banner. */
    now: string;
}

// ---- Areas ------------------------------------------------------------------

export interface AreaProject {
    key: string;
    name: string;
    request_key: string;
    status: ProjectStatus;
    hours: ProjectBudget['hours'];
    /** Null without `ai.costs.view`. */
    cost: ProjectBudget['cost'];
    /** "Falta validar 2 de 4 criterios con Paola". */
    note: string | null;
    note_level: SemaphoreLevel | null;
}

export interface AreaDetail {
    summary: AreaSummary;
    products: Pick<Product, 'slug' | 'name' | 'version' | 'stack' | 'host' | 'owner' | 'state' | 'monitoring_active'>[];
    projects: AreaProject[];
    backlog: RequestSummary[];
    /** Requests of the area still in the inbox. */
    inbox_keys: string[];
}

export interface AreasIndexProps {
    /** Company whose areas are shown (the switch value). */
    company_id: number;
    /** Companies the viewer may switch between; not the shared `companies`. */
    area_companies: Pick<Company, 'id' | 'short_name'>[];
    areas: AreaSummary[];
    selected_area: AreaDetail | null;
}

// ---- My week ----------------------------------------------------------------

export type WeekColumn = 'due' | 'blocked' | 'waiting';

/** What the item points to; decides its action button. */
export type WeekTarget =
    | { type: 'request'; key: string }
    | { type: 'task'; project_key: string; task_key: string }
    | { type: 'project'; key: string }
    | { type: 'requirement'; id: number; area_name: string }
    | { type: 'approval'; key: string };

export interface WeekItem {
    id: string;
    column: WeekColumn;
    company_id: number;
    /** "GIA-148", "P-07 · T3", "P-07". */
    ref_label: string;
    title: string;
    /** Second line: who does it or who we wait for. */
    person: string;
    /** `due`: deadline (ISO date or instant). */
    due_at: string | null;
    /** `blocked` / `waiting`: since when. */
    since: string | null;
    /** `blocked`: what it waits on ("Espera el requerimiento “API de WooCommerce” a Mercadeo"). */
    reason: string | null;
    target: WeekTarget;
}

export interface RunningTimer {
    project_key: string;
    task_key: string;
    task_title: string;
    started_at: string;
    subtasks_done: number;
    subtasks_total: number;
    due_at: string | null;
}

export interface WeeklyReview {
    title: string;
    at: string;
    agenda: { id: string; text: string }[];
    previous: { at: string; decisions: number } | null;
}

export interface WeekIndexProps {
    /** Bogotá date (YYYY-MM-DD). */
    today: string;
    /** Server time (ISO) the deadlines, waiting times and the running timer start from; avoids device clock skew. */
    now: string;

    /** Last day of the week shown (Sunday). */
    week_ends_on: string;
    items: WeekItem[];
    running_timer: RunningTimer | null;
    review: WeeklyReview | null;
}
