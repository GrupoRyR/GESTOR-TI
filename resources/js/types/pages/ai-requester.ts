/**
 * Gestor IA · requester pages (boards B1–B4). Phone-first; the person who
 * asks never sees costs, rates, internal notes or the IT tabs.
 *
 * Inertia components and their props:
 * - `Ai/Requests/Create`     RequestsCreateProps     "Pedir un desarrollo o una mejora" (7-step wizard)
 * - `Ai/Requests/Submitted`  RequestsSubmittedProps  "Recibimos tu solicitud"
 * - `Ai/Requests/Mine`       RequestsMineProps       "Mis solicitudes" (+ empty state)
 * - `Ai/Requests/Show`       RequestsShowProps       request detail; this file defines `view: 'requester'`,
 *                                                    ai-triage.ts defines `view: 'it'`
 * - `Ai/Requests/Validate`   RequestsValidateProps   acceptance validation while "En pruebas"
 * - `Ai/Ratings/Create`      RatingsCreateProps      "Calificar la entrega"
 * - `Ai/Ratings/HoursSaved`  RatingsHoursSavedProps  "30 días después"
 * - `Ai/Roadmap/Index`       RoadmapIndexProps       "Hoja de ruta" (requesters and IT)
 *
 * Ziggy routes used:
 * - `ai.requests.create`           GET    query `{ producto?: slug }`                 wizard; `producto` preselects the app
 * - `ai.requests.create`           GET    partial reload `only: ['similar']`, data `{ q: string }`
 *                                         Create asks for similar requests while the problem is typed
 *                                         (debounced 700 ms, from 20 characters). Inertia keeps the
 *                                         path and query; the server answers with only `similar`.
 * - `ai.requests.draft.update`     PUT    body `RequestDraftPayload` (no files)       autosave "Se guarda solo", 2 s after the last change;
 *                                         partial reload `only: ['draft_saved_at']`
 * - `ai.requests.store`            POST   multipart `RequestStorePayload`             "Enviar solicitud"; redirect to `ai.requests.submitted`
 * - `ai.requests.vote`             POST   `{ request: key }`                          "+1 Es lo mismo" (wizard), +1 on the roadmap
 * - `ai.requests.vote`             DELETE `{ request: key }`                          roadmap: remove my vote (button pressed again)
 * - `ai.requests.submitted`        GET    `{ request: key }`                          (the page itself, after store)
 * - `ai.requests.show`             GET    `{ request: key }`                          cards, "Ver mi solicitud", roadmap titles; `#conversacion` for "Responder"
 * - `ai.requests.mine`             GET    query `{ filter: 'active'|'done'|'all', page? }`  filter chips, breadcrumb, "Mis solicitudes"
 * - `ai.requests.comments.store`   POST   `{ request: key }` multipart `{ body: string, attachments: File[] }`  "Enviar" in Conversación
 * - `ai.requests.follow`           POST   `{ request: key }`                          "Seguir"
 * - `ai.requests.follow`           DELETE `{ request: key }`                          "Siguiendo" pressed again
 * - `ai.requests.validation.update` PUT   `{ request: key }` multipart `RequestValidationPayload`  "Enviar validación"
 * - `ai.ratings.create`            GET    `{ request: key }`                          "Calificar" on Mis solicitudes
 * - `ai.ratings.store`             POST   `{ request: key }` body `{ need_score: 1–5, ease_score: 1–5, comment: string|null }`
 * - `ai.ratings.hours.store`       POST   `{ request: key }` body `{ hours_per_week: number }` (0–60, step 0,5)
 * - `ai.roadmap.index`             GET    query `{ area_id?, company_id?, mine?: 1 }` filters (null/false omitted);
 *                                         also "Ver … en la hoja de ruta" from the request detail (`area_id`)
 * - `home`                         GET    —                                           wizard close, "Volver al inicio"
 * - `profile.telegram.create`      GET    —                                           "Vincular Telegram"
 *
 * Permissions:
 * - `ai.requests.create`  every requester page and the "Pedir…" buttons.
 * - `ai.manage`           Roadmap renders GestorLayout in IT mode (tabs, no roadmap tab); the server sends the
 *                         same item shape to everyone: RoadmapItem never carries costs.
 * - Requester pages never receive `internal_notes`, `estimate.total_cost`, `estimate.external_costs`,
 *   rates or per-person hours: the server omits them (see RequesterRequestDetail).
 * - First response: within the configured number of business days (configurable; example 3).
 */
import type { Rating } from '@/Components/ui/RatingScale';
import type { RequestStatusKey } from '@/lib/status';
import type { RequestDraft, RequestType, WizardStepNumber } from '@/lib/wizard';
import type { AreaRef, ProductRef, RequestDetail, RequestSummary } from '@/types/ai';
import type { ItShowProps } from '@/types/pages/ai-triage';
import type { Company, FormErrors, Paginated } from '@/types/shared';

type AreaOption = Pick<AreaRef, 'id' | 'name'>;

// ---- Create (wizard) --------------------------------------------------------

/** Step 1 cards. The server decides which types the person may pick. */
export interface RequestTypeOption {
    value: RequestType;
    title: string;
    description: string;
}

/** Autosaved draft: the wizard fields without files. */
export type RequestDraftPayload = Omit<RequestDraft, 'attachments'>;

/** `ai.requests.store` body (multipart). `systems` are display names from `systems_options` plus free text. */
export type RequestStorePayload = Omit<RequestDraft, 'attachments' | 'plus_one_key'> & { attachments: File[] };

export interface RequestsCreateProps {
    /** App preselected from `?producto=` (the "Pedir mejora" button inside each app). */
    product: ProductRef | null;
    types: RequestTypeOption[];
    /** Apps the person can ask an improvement or report a bug for. */
    apps: ProductRef[];
    /** Systems catalog shown as chips on step 5: "SAP Business One", "Excel", … */
    systems_options: string[];
    /** Area from the person's profile; preselected on step 7. */
    area_suggestion: AreaOption;
    /** Areas of the person's company, to change it on step 7. */
    areas: AreaOption[];
    /** Shown in the attachments hint; the client also caps files at 10 MB (lib/wizard). */
    max_attachment_mb: number;
    /** Possible duplicates for the problem typed so far (partial reload with `q`). Empty = none. */
    similar: RequestSummary[];
    /** Bogotá calendar date (YYYY-MM-DD) for the deadline check. */
    today: string;
    /** Server-saved draft to resume. */
    draft?: Partial<RequestDraftPayload> | null;
    /** When the draft was last saved (ISO); refreshed by the autosave partial reload. */
    draft_saved_at?: string | null;
    /** Step to open (resume a draft); defaults to the first step with errors, else 1. */
    initial_step?: WizardStepNumber;
    /** Laravel validation errors from `ai.requests.store`. */
    errors?: FormErrors;
}

// ---- Submitted --------------------------------------------------------------

export interface RequestsSubmittedProps {
    request: { key: string; title: string };
    /** End of the first-response window (ISO). */
    first_response_due_at: string;
    /** Hides "Vincular Telegram" when already linked. */
    telegram_linked: boolean;
}

// ---- Mine -------------------------------------------------------------------

export type MineFilter = 'active' | 'done' | 'all';

export interface MyRequestRow extends RequestSummary {
    /** Plain sentence under the phase rail: "La evaluación sigue cuando respondas." */
    next_step: string;
    /** TI asked something and waits for the requester. */
    needs_reply: boolean;
    /** The open question when `needs_reply` (null otherwise). */
    question: { text: string; asked_by: string; asked_at: string } | null;
    /** In production and not rated yet. */
    can_rate: boolean;
}

export interface RequestsMineProps {
    requests: Paginated<MyRequestRow>;
    filter: MineFilter;
    counts: Record<MineFilter, number>;
}

// ---- Show (requester view) --------------------------------------------------

/**
 * What a requester receives. No internal notes; the estimate keeps the
 * proposed due date and total hours only (no costs, no hours per person).
 */
export type RequesterRequestDetail = Omit<RequestDetail, 'internal_notes' | 'estimate'> & {
    estimate: { due_date: string; hours_total: number } | null;
};

export interface RequesterShowProps {
    view: 'requester';
    request: RequesterRequestDetail;
    /** "Dónde está": "Esperando la aprobación de un aprobador de proyectos" + plain explanation. */
    where: { label: string; explanation: string };
    /** Place in the area roadmap while queued; null when not ranked. */
    roadmap_position: { rank: number; label: string; detail: string | null } | null;
    following: boolean;
    /** False once closed or rejected: the conversation becomes read-only. */
    can_comment: boolean;
}

/** The server picks the view: `requester` here, `it` in ai-triage.ts (holders of `ai.manage`). */
export type RequestsShowProps = RequesterShowProps | ItShowProps;

// ---- Validate ---------------------------------------------------------------

export interface ValidationCriterion {
    id: number;
    text: string;
    /** Previous answer when the requester comes back. */
    result: 'pass' | 'fail' | null;
    comment: string | null;
}

export interface RequestsValidateProps {
    request: {
        key: string;
        title: string;
        /** Testing version of the app ("Abrir Toma Pedidos SAP"). */
        product: { name: string; url: string } | null;
    };
    criteria: ValidationCriterion[];
}

/** `ai.requests.validation.update` body (multipart: one optional photo per failed criterion). */
export interface RequestValidationPayload {
    criteria: { id: number; result: 'pass' | 'fail'; comment: string | null; photo: File | null }[];
}

// ---- Ratings ----------------------------------------------------------------

export interface RatedRequest {
    key: string;
    title: string;
    product_name: string;
    /** Date it reached production. */
    in_production_since: string;
}

export interface RatingsCreateProps {
    request: RatedRequest;
}

export interface RatingStorePayload {
    need_score: Rating | null;
    ease_score: Rating | null;
    comment: string;
}

export interface RatingsHoursSavedProps {
    request: RatedRequest;
    /** What the requester estimated when asking (hours per person per week). */
    estimated_hours_per_week: number;
}

// ---- Roadmap ----------------------------------------------------------------

export type RoadmapColumn = 'now' | 'next' | 'later';

/** Public roadmap card. Never carries costs or estimates. */
export interface RoadmapItem {
    key: string;
    title: string;
    area: AreaOption;
    company_id: number;
    status: RequestStatusKey;
    votes: number;
    /** The viewer asked for it ("Tuya"). */
    mine: boolean;
    /** The viewer already +1'd it. */
    voted: boolean;
    /** Server-written second line: "Entrega estimada 14 nov", "Puesto 3 en Compras". */
    note: string | null;
    planned_start: string | null;
}

export interface RoadmapFilters {
    area_id: number | null;
    company_id: number | null;
    mine: boolean;
}

export interface RoadmapIndexProps {
    columns: Record<RoadmapColumn, RoadmapItem[]>;
    filters: RoadmapFilters;
    areas: AreaOption[];
    /** Companies whose items appear (for the marks and the filter); not the shared `companies`. */
    roadmap_companies: Pick<Company, 'id' | 'name' | 'short_name' | 'colors'>[];
    /** Items in "Más adelante" in total (the column shows the first ones). */
    later_total: number;
    /** WIP limit: projects in construction at once (3). */
    wip_limit: number;
    /** Count for "Solo las mías". */
    mine_count: number;
    updated_at: string;
}
