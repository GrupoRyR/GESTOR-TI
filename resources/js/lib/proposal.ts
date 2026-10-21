/**
 * "Proponer proyecto" (Ai/Proposals/Create): form state, client checks that
 * mirror the server where cheap, the store payload and the "Qué pasa al
 * crear / al enviar" lines. The wizard (lib/wizard) and the evaluation
 * (lib/priority) stay the source of the shared rules and messages; Laravel
 * validates everything again.
 */
import type { PersonRef } from '../types/ai';
import type { ProposalStorePayload } from '../types/pages/ai-proposals';
import type { RiskLevel } from '../types/pages/ai-triage';
import { formatShortDate } from './format';
import { suggestedPriority, type EffortSize, type Urgency } from './priority';
import type { RequestStatusKey } from './status';
import { emptyDraft, validateStep, type RequestDraft, type RequestType, type StepErrors } from './wizard';

export const TITLE_MAX = 120;

/** What the form holds. Numbers stay as typed ("1,5") so an empty box stays empty. */
export interface ProposalForm {
    type: RequestType | null;
    product_slug: string | null;
    title: string;
    problem: string;
    current_process: string;
    expected_result: string;
    area_id: number | null;
    benefited_area_ids: number[];
    hours_per_week: string;
    people: string;
    risk: RiskLevel;
    urgency: Urgency | null;
    size: EffortSize | null;
    /** Null = use the suggested priority. */
    priority_final: number | null;
    /** Hours per team member id, as typed. */
    hours: Record<number, string>;
    due_date: string;
    approver_ids: number[];
    project_name: string;
    start_date: string;
}

export type ProposalErrors = Partial<Record<keyof ProposalForm, string>>;

export function emptyProposal(team: PersonRef[], approvers: PersonRef[]): ProposalForm {
    return {
        type: null,
        product_slug: null,
        title: '',
        problem: '',
        current_process: '',
        expected_result: '',
        area_id: null,
        benefited_area_ids: [],
        hours_per_week: '',
        people: '',
        risk: 'none',
        urgency: null,
        size: null,
        priority_final: null,
        hours: Object.fromEntries(team.map((person) => [person.id, ''])),
        due_date: '',
        approver_ids: approvers.map((approver) => approver.id),
        project_name: '',
        start_date: '',
    };
}

/** "5", "1,5" or "1.5" → number; empty or not a number → null. */
export function parseDecimal(text: string): number | null {
    const trimmed = text.trim();
    if (trimmed === '') return null;
    const parsed = Number(trimmed.replace(',', '.'));
    return Number.isFinite(parsed) ? parsed : null;
}

const positive = (value: number | null) => (value !== null && value > 0 ? value : 0);

/** Total of "Horas por persona"; empty, invalid or negative boxes count as 0. */
export function estimateTotal(hours: Record<number, string>): number {
    return Number(Object.values(hours).reduce((sum, value) => sum + positive(parseDecimal(value)), 0).toFixed(2));
}

/** The form as a wizard draft, to reuse the wizard checks and messages. */
function asDraft(form: ProposalForm): RequestDraft {
    return {
        ...emptyDraft(),
        type: form.type,
        product_slug: form.product_slug,
        problem: form.problem,
        hours_per_week: parseDecimal(form.hours_per_week),
        people: parseDecimal(form.people),
        expected_result: form.expected_result,
        urgency: form.urgency,
        area_id: form.area_id,
    };
}

/** Suggested priority while typing (no votes yet); null without a size or with invalid time. */
export function livePriority(form: ProposalForm): number | null {
    const draft = asDraft(form);
    if (Object.keys(validateStep(3, draft, '')).length > 0) return null;
    return suggestedPriority({
        hours_per_week: draft.hours_per_week ?? 0,
        people: draft.people ?? 0,
        votes: 0,
        size: form.size,
        urgency: form.urgency ?? 'medium',
    });
}

const blank = (text: string) => text.trim() === '';

/** Client checks; empty object = ready to send. Wizard rules keep the wizard's words. */
export function validateProposal(form: ProposalForm, context: { canSelfApprove: boolean }): ProposalErrors {
    const draft = asDraft(form);
    const wizard: StepErrors = { ...validateStep(1, draft, ''), ...validateStep(2, draft, ''), ...validateStep(3, draft, ''), ...validateStep(4, draft, ''), ...validateStep(6, draft, '') };
    const errors: ProposalErrors = {};

    if (wizard.type) errors.type = wizard.type;
    if (wizard.product_slug) errors.product_slug = wizard.product_slug;
    if (blank(form.title)) errors.title = 'Escribe un título.';
    else if (form.title.trim().length > TITLE_MAX) errors.title = 'Usa hasta 120 caracteres.';
    if (wizard.problem) errors.problem = wizard.problem;
    if (wizard.expected_result) errors.expected_result = wizard.expected_result;
    if (form.area_id === null) errors.area_id = 'Escoge el área principal.';
    if (wizard.hours_per_week) errors.hours_per_week = wizard.hours_per_week;
    if (wizard.people) errors.people = wizard.people;
    if (wizard.urgency) errors.urgency = wizard.urgency;
    if (form.size === null) errors.size = 'Elige la talla.';
    if (estimateTotal(form.hours) <= 0) errors.hours = 'Escribe las horas de al menos una persona.';
    if (!form.due_date) errors.due_date = 'Elige la fecha estimada de entrega.';

    if (context.canSelfApprove) {
        if (blank(form.project_name)) errors.project_name = 'Escribe el nombre del proyecto.';
        if (!form.start_date) errors.start_date = 'Elige cuándo empieza.';
    } else if (form.approver_ids.length === 0) {
        errors.approver_ids = 'Elige al menos un aprobador.';
    }

    return errors;
}

/** Form order and visible label of every field, for the aside summary and focus. */
export const FIELD_LABELS: [keyof ProposalForm, string][] = [
    ['type', 'Tipo de solicitud'],
    ['product_slug', 'App'],
    ['title', 'Título'],
    ['problem', 'El problema'],
    ['current_process', '¿Cómo se hace hoy?'],
    ['expected_result', 'Resultado esperado'],
    ['area_id', 'Área principal'],
    ['benefited_area_ids', 'También se benefician'],
    ['hours_per_week', 'Horas por semana'],
    ['people', 'Cuántas personas'],
    ['risk', 'Riesgo que reduce'],
    ['urgency', 'Urgencia'],
    ['size', 'Talla'],
    ['priority_final', 'Prioridad final'],
    ['hours', 'Horas por persona'],
    ['due_date', 'Fecha estimada de entrega'],
    ['approver_ids', 'Quién aprueba'],
    ['project_name', 'Nombre del proyecto'],
    ['start_date', 'Empieza'],
];

/** Form field of a Laravel error key: `hours.0.hours` → `hours`. */
export function fieldOfError(key: string): string {
    return key.split('.')[0] ?? key;
}

/** Errors keyed by form field (server row keys folded), first message wins. */
export function fieldErrors(errors: Record<string, string | undefined>): ProposalErrors {
    const result: Record<string, string> = {};
    for (const [key, message] of Object.entries(errors)) {
        if (!message) continue;
        const field = fieldOfError(key);
        if (result[field] === undefined) result[field] = message;
    }
    return result as ProposalErrors;
}

/** "Falta corregir N campos": how many fields have errors and the first one in form order. */
export function errorSummary(errors: Record<string, string | undefined>): { count: number; first: { field: string; label: string } | null } {
    const fields = Object.keys(fieldErrors(errors));
    if (fields.length === 0) return { count: 0, first: null };
    const ordered = FIELD_LABELS.find(([field]) => fields.includes(field));
    const first = ordered ? { field: ordered[0], label: ordered[1] } : { field: fields[0] ?? '', label: fields[0] ?? '' };
    return { count: fields.length, first };
}

/** `ai.proposals.store` body: project fields only when self-approving, approvers only when not. */
export function buildProposalPayload(form: ProposalForm, context: { canSelfApprove: boolean }): ProposalStorePayload {
    const live = livePriority(form);
    const needsApp = form.type === 'improvement' || form.type === 'bug';
    const payload: ProposalStorePayload = {
        type: form.type as RequestType,
        product_slug: needsApp ? form.product_slug : null,
        title: form.title.trim(),
        problem: form.problem.trim(),
        current_process: form.current_process.trim(),
        expected_result: form.expected_result.trim(),
        area_id: form.area_id as number,
        benefited_area_ids: form.benefited_area_ids,
        hours_per_week: parseDecimal(form.hours_per_week),
        people: parseDecimal(form.people),
        risk: form.risk,
        urgency: form.urgency as Urgency,
        size: form.size as EffortSize,
        priority_suggested: live,
        priority_final: form.priority_final ?? live,
        hours: Object.entries(form.hours)
            .map(([id, value]) => ({ person_id: Number(id), hours: positive(parseDecimal(value)) }))
            .filter((row) => row.hours > 0),
        due_date: form.due_date,
    };
    if (context.canSelfApprove) {
        payload.project_name = form.project_name.trim();
        payload.start_date = form.start_date;
    } else {
        payload.approver_ids = form.approver_ids;
    }
    return payload;
}

// ---- "Qué pasa al crear / al enviar" ------------------------------------------

export interface OutcomeInput {
    canSelfApprove: boolean;
    requestKey: string | null;
    projectKey: string | null;
    size: EffortSize | null;
    urgency: Urgency | null;
    priority: number | null;
    totalHours: number;
    dueDate: string;
    projectName: string;
    startDate: string;
    /** Selected approvers (approval path). */
    approvers: PersonRef[];
    proposerId: number;
    team: PersonRef[];
    hours: Record<number, string>;
}

export interface OutcomeLine {
    key: 'request' | 'approved' | 'approval' | 'project' | 'team' | 'later';
    title: string;
    /** Text before the status chip (or the whole detail). */
    detail: string;
    /** Status chip after `detail`. */
    status?: RequestStatusKey;
    /** Text after the status chip. */
    detailEnd?: string;
}

const URGENCY_WORDS: Record<Urgency, string> = { low: 'baja', medium: 'media', high: 'alta', urgent: 'urgente' };

const hoursText = (value: number) => value.toLocaleString('es-CO', { maximumFractionDigits: 1 });

function joinWith(names: string[], word: 'y' | 'o'): string {
    if (names.length <= 1) return names[0] ?? '';
    return `${names.slice(0, -1).join(', ')} ${word} ${names[names.length - 1]}`;
}

function evaluatedDetail(input: OutcomeInput): string {
    const parts = [input.size ? `talla ${input.size}` : null, input.urgency ? `urgencia ${URGENCY_WORDS[input.urgency]}` : null].filter(
        (part): part is string => part !== null,
    );
    if (input.priority !== null) return `Ya evaluada: ${[...parts, `prioridad ${input.priority}`].join(', ')}.`;
    if (parts.length > 0) return `Ya evaluada: ${parts.join(', ')} y la prioridad que resulte.`;
    return 'Ya evaluada con la talla, la urgencia y la prioridad que elijas.';
}

/** "con 40 h y entrega el 27 nov", or the part that is known; null with neither. */
function estimateClause(totalHours: number, dueDate: string): string | null {
    const hours = totalHours > 0 ? `${hoursText(totalHours)} h` : null;
    const due = dueDate ? `entrega el ${formatShortDate(dueDate)}` : null;
    if (hours && due) return `con ${hours} y ${due}`;
    if (hours || due) return `con ${hours ?? due}`;
    return null;
}

/** The lines of the aside (desktop) and the collapsible summary (phone). */
export function proposalOutcome(input: OutcomeInput): OutcomeLine[] {
    const request: OutcomeLine = {
        key: 'request',
        title: input.requestKey ? `Se crea la solicitud ${input.requestKey} a tu nombre` : 'Se crea la solicitud a tu nombre',
        detail: evaluatedDetail(input),
    };
    const clause = estimateClause(input.totalHours, input.dueDate);

    if (!input.canSelfApprove) {
        const names = joinWith(
            input.approvers.map((approver) => approver.name),
            'o',
        );
        return [
            request,
            {
                key: 'approval',
                title: names ? `Va a aprobación de ${names}` : 'Va a aprobación',
                detail: 'Queda',
                status: 'pending_approval',
                detailEnd: clause ? `${clause}.` : 'con el estimado que escribas.',
            },
            { key: 'later', title: 'Todavía no hay proyecto', detail: 'Cuando la aprueben, la conviertes en proyecto desde la solicitud.' },
        ];
    }

    const others = input.team.filter((person) => person.id !== input.proposerId && positive(parseDecimal(input.hours[person.id] ?? '')) > 0);
    const developers = others.length > 0 ? `; ${joinWith(others.map((person) => person.name), 'y')} en desarrollo` : '';
    const projectName = input.projectName.trim();

    return [
        request,
        {
            key: 'approved',
            title: 'Queda aprobada por ti',
            detail: `En el historial: “Aprobada por quien la propuso”${clause ? `, ${clause}` : ''}.`,
        },
        {
            key: 'project',
            title: input.projectKey ? `Se crea el proyecto ${input.projectKey}` : 'Se crea el proyecto',
            detail: projectName ? `${projectName},` : '',
            status: 'planned',
            detailEnd: input.startDate ? `desde el ${formatShortDate(input.startDate)}.` : 'cuando elijas la fecha de inicio.',
        },
        { key: 'team', title: 'Equipo del proyecto', detail: `Tú como líder del proyecto${developers}.` },
    ];
}

/** "Edward Ramanan recibe un aviso con lo que propusiste." (self-approve only); null when nobody. */
export function noticeLine(people: PersonRef[]): string | null {
    if (people.length === 0) return null;
    const names = joinWith(
        people.map((person) => person.name),
        'y',
    );
    return `${names} ${people.length === 1 ? 'recibe' : 'reciben'} un aviso con lo que propusiste.`;
}

/** WIP limit reached: the new project waits in Planificada. */
export function wipWarning(wip: { in_development: number; limit: number }, projectKey: string | null): string | null {
    if (wip.in_development < wip.limit) return null;
    return `Hay ${wip.in_development} de ${wip.limit} proyectos en desarrollo. ${projectKey ?? 'El proyecto'} espera en Planificada hasta que uno pase a pruebas.`;
}

/** One sentence above the phone submit button. */
export function submitSummary(input: OutcomeInput): { text: string; status?: RequestStatusKey; end?: string } {
    const request = input.requestKey ?? 'la solicitud';
    if (!input.canSelfApprove) {
        const names = joinWith(
            input.approvers.map((approver) => approver.name),
            'o',
        );
        return { text: names ? `Se crea ${request} y va a aprobación de ${names}.` : `Se crea ${request} y va a aprobación.` };
    }
    return {
        text: `Se crea ${request} aprobada por ti y el proyecto${input.projectKey ? ` ${input.projectKey}` : ''}`,
        status: 'planned',
        end: input.startDate ? `desde el ${formatShortDate(input.startDate)}.` : undefined,
    };
}
