/**
 * Gestor IA · "Proponer proyecto" (boards B-ProposeSelf, B-ProposeApproval,
 * B-ProposePhone, B-ProposeEntry). An IT shortcut: one form with the wizard
 * questions, the evaluation and the estimate, saved in one server transaction.
 *
 * Inertia components and their props:
 * - `Ai/Proposals/Create`  ProposalsCreateProps  "Proponer proyecto" (desktop aside, phone sticky bar)
 *
 * Ziggy routes used:
 * - `ai.proposals.create`  GET    —                              the page; "Proponer proyecto" in the Bandeja and Proyectos headers
 * - `ai.proposals.store`   POST   body `ProposalStorePayload`    "Crear proyecto" (self-approve) / "Enviar a aprobación".
 *                                 One transaction; if anything fails nothing is created.
 *                                 Redirects: self-approve → `ai.projects.show { project }`; otherwise → `ai.requests.show { request }`.
 *                                 Validation errors come back as Inertia `errors`, keyed by payload field
 *                                 (`hours.0.hours` style keys for the estimate rows).
 * - `ai.projects.index`    GET    —                              breadcrumb "Proyectos", "Cancelar"
 * - `ai.requests.create`   GET    —                              "Pedir un desarrollo" (GestorLayout tab strip)
 *
 * Permissions:
 * - `ai.manage`            the page and every entry point (Bandeja, Proyectos, "Pedir un desarrollo" link).
 * - `ai.projects.approve`  `can_self_approve`: the Proyecto section and "Crear proyecto" render only with it.
 *                          The server creates the request in the proposer's name, records the evaluation and the
 *                          estimate, records the approval "Aprobada por quien la propuso" and converts it to a
 *                          project "Planificada". `notify_on_self_approve` (the other holders of the permission)
 *                          get an informative notice (event `ai.project.proposed`).
 *                          Without it the request is created already evaluated and goes to the normal approval
 *                          round with `approver_ids`.
 * - The proposer receives neither "Recibimos tu solicitud" nor "Llegó una solicitud nueva".
 * - No costs or rates on this page: `team` carries people only.
 */
import type { EffortSize, Urgency } from '@/lib/priority';
import type { ProposalForm } from '@/lib/proposal';
import type { RequestType } from '@/lib/wizard';
import type { AreaRef, PersonRef, ProductRef } from '@/types/ai';
import type { RequestTypeOption } from '@/types/pages/ai-requester';
import type { WipCount } from '@/types/pages/ai-projects';
import type { RiskLevel } from '@/types/pages/ai-triage';
import type { Company, FormErrors } from '@/types/shared';

/** One company and its areas, for the grouped "Área principal" select. The company comes from the area. */
export interface ProposalAreaGroup {
    company: Pick<Company, 'id' | 'name' | 'short_name'>;
    areas: AreaRef[];
}

export interface ProposalsCreateProps {
    /** Viewer holds `ai.projects.approve`: self-approve path (Proyecto section, "Crear proyecto"). */
    can_self_approve: boolean;
    /** Holders of `ai.projects.approve`, for "Quién aprueba" (only used when NOT self-approving). */
    approvers: PersonRef[];
    /** Who gets the informative notice when the viewer self-approves (the other approvers). Empty otherwise. */
    notify_on_self_approve: PersonRef[];
    /** IT people for "Horas por persona". No rates on this page. */
    team: PersonRef[];
    /** Same step 1 options as the wizard ("Algo nuevo", "Mejorar una app que ya usamos", …). */
    types: RequestTypeOption[];
    /** Areas grouped by company. */
    areas: ProposalAreaGroup[];
    /** Apps for improvements and bugs (required for those types, like `StoreAiRequestRequest`). */
    apps: ProductRef[];
    /** Projects in development vs the WIP limit. */
    wip: WipCount;
    /** Bogotá calendar date (YYYY-MM-DD): "Este mes · Antes del 31 oct" and date checks. */
    today: string;
    /** Key the request will get ("GIA-154"); copy falls back to generic wording when absent. */
    next_request_key?: string | null;
    /** Key the project will get ("P-08"); only meaningful with `can_self_approve`. */
    next_project_key?: string | null;
    /** Laravel validation errors from `ai.proposals.store`. */
    errors?: FormErrors;
    /**
     * Gallery only: values to start the form from. The server does not send it (after a failed
     * POST Inertia keeps the `useForm` state on the same page).
     */
    draft?: Partial<ProposalForm>;
}

/** `ai.proposals.store` body. Project fields only when self-approving; `approver_ids` only when not. */
export interface ProposalStorePayload {
    type: RequestType;
    /** Required for `improvement` and `bug`. */
    product_slug: string | null;
    /** Max 120 characters. */
    title: string;
    /** Min 20 characters. */
    problem: string;
    current_process: string;
    expected_result: string;
    area_id: number;
    benefited_area_ids: number[];
    /** 0,5–60 (empty allowed for `idea`, together with `people`). */
    hours_per_week: number | null;
    /** 1–500. */
    people: number | null;
    risk: RiskLevel;
    urgency: Urgency;
    size: EffortSize;
    /** Live suggestion at submit time (the server recomputes it). */
    priority_suggested: number | null;
    priority_final: number | null;
    /** Estimate: hours per person (rows with 0 h are dropped). */
    hours: { person_id: number; hours: number }[];
    /** YYYY-MM-DD. */
    due_date: string;
    /** Only when NOT self-approving. */
    approver_ids?: number[];
    /** Only when self-approving (`ai.requests.convert` labels: "Nombre del proyecto"). */
    project_name?: string;
    /** Only when self-approving ("Empieza"), YYYY-MM-DD. */
    start_date?: string;
}
