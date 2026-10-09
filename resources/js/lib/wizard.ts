/**
 * "Pedir mejora" request wizard: the 7 steps and their client-side checks.
 * Laravel validates again on submit; these rules only guide the requester
 * and decide which step to reopen. Messages are user-facing Spanish.
 */
import type { Urgency } from './priority';

export type RequestType = 'new' | 'improvement' | 'bug' | 'idea';

export type WizardStepNumber = 1 | 2 | 3 | 4 | 5 | 6 | 7;

export interface DraftAttachment {
    name: string;
    /** Bytes. */
    size: number;
    mime: string;
}

/** What the requester has typed so far (autosaved as a draft). */
export interface RequestDraft {
    type: RequestType | null;
    /** App the request is about; preset when arriving from "Pedir mejora". */
    product_slug: string | null;
    problem: string;
    /** Key of a similar request the requester +1'd instead of filing a new one. */
    plus_one_key: string | null;
    hours_per_week: number | null;
    people: number | null;
    expected_result: string;
    success_criteria: string;
    /** Keys from the systems catalog (sap_b1, excel, email, …). */
    systems: string[];
    systems_notes: string;
    urgency: Urgency | null;
    /** YYYY-MM-DD, optional. */
    deadline: string | null;
    deadline_reason: string;
    area_id: number | null;
    attachments: DraftAttachment[];
}

export const WIZARD_STEPS: { number: WizardStepNumber; title: string }[] = [
    { number: 1, title: 'Qué necesitas' },
    { number: 2, title: 'Qué problema hay' },
    { number: 3, title: 'Cuánto tiempo' },
    { number: 4, title: 'Qué esperas' },
    { number: 5, title: 'Sistemas y datos' },
    { number: 6, title: 'Urgencia' },
    { number: 7, title: 'Revisar y enviar' },
];

export const PROBLEM_MIN = 20;
export const TEXT_MAX = 2000;
export const HOURS_MIN = 0.5;
export const HOURS_MAX = 60;
export const PEOPLE_MAX = 500;
export const MAX_ATTACHMENT_BYTES = 10 * 1024 * 1024;
export const MAX_ATTACHMENTS = 10;

const ALLOWED_MIME = [
    /^image\//,
    /^application\/pdf$/,
    /^application\/vnd\.ms-excel$/,
    /^application\/vnd\.openxmlformats-officedocument\.spreadsheetml\.sheet$/,
    /^text\/csv$/,
];

export type StepErrors = Partial<Record<keyof RequestDraft, string>>;

export function emptyDraft(): RequestDraft {
    return {
        type: null,
        product_slug: null,
        problem: '',
        plus_one_key: null,
        hours_per_week: null,
        people: null,
        expected_result: '',
        success_criteria: '',
        systems: [],
        systems_notes: '',
        urgency: null,
        deadline: null,
        deadline_reason: '',
        area_id: null,
        attachments: [],
    };
}

/** Team hours per week (6 h × 8 people = 48), or null while incomplete. */
export function teamHours(draft: Pick<RequestDraft, 'hours_per_week' | 'people'>): number | null {
    if (draft.hours_per_week === null || draft.people === null) return null;
    return draft.hours_per_week * draft.people;
}

const blank = (text: string) => text.trim() === '';

function checkType(draft: RequestDraft): StepErrors {
    if (draft.type === null) return { type: 'Escoge qué necesitas.' };
    if ((draft.type === 'improvement' || draft.type === 'bug') && draft.product_slug === null) {
        return { product_slug: 'Escoge la app.' };
    }
    return {};
}

function checkProblem(draft: RequestDraft): StepErrors {
    const length = draft.problem.trim().length;
    if (length < PROBLEM_MIN) return { problem: 'Cuéntalo en una o dos frases (mínimo 20 caracteres).' };
    if (length > TEXT_MAX) return { problem: 'Es muy largo: resúmelo en menos de 2.000 caracteres.' };
    return {};
}

function checkTime(draft: RequestDraft): StepErrors {
    if (draft.type === 'idea' && draft.hours_per_week === null && draft.people === null) return {};
    const errors: StepErrors = {};
    const hours = draft.hours_per_week;
    if (hours === null || !Number.isFinite(hours) || hours < HOURS_MIN || hours > HOURS_MAX) {
        errors.hours_per_week = 'Escribe entre media hora y 60 horas por semana.';
    }
    const people = draft.people;
    if (people === null || !Number.isInteger(people) || people < 1 || people > PEOPLE_MAX) {
        errors.people = 'Escribe cuántas personas, de 1 a 500.';
    }
    return errors;
}

function checkResult(draft: RequestDraft): StepErrors {
    if (blank(draft.expected_result)) return { expected_result: 'Cuéntanos qué esperas que pase.' };
    if (draft.expected_result.length > TEXT_MAX) return { expected_result: 'Es muy largo: resúmelo en menos de 2.000 caracteres.' };
    return {};
}

function checkUrgency(draft: RequestDraft, today: string): StepErrors {
    const errors: StepErrors = {};
    if (draft.urgency === null) errors.urgency = 'Escoge para cuándo lo necesitas.';
    if (draft.deadline !== null) {
        if (draft.deadline <= today) errors.deadline = 'La fecha límite tiene que ser después de hoy.';
        if (blank(draft.deadline_reason)) errors.deadline_reason = 'Cuéntanos por qué esa fecha.';
    }
    return errors;
}

function checkReview(draft: RequestDraft): StepErrors {
    const errors: StepErrors = {};
    if (draft.area_id === null) errors.area_id = 'Escoge tu área.';
    if (draft.attachments.length > MAX_ATTACHMENTS) {
        errors.attachments = 'Puedes adjuntar hasta 10 archivos.';
    } else if (draft.attachments.some((file) => file.size > MAX_ATTACHMENT_BYTES)) {
        errors.attachments = 'Cada archivo puede pesar hasta 10 MB.';
    } else if (draft.attachments.some((file) => !ALLOWED_MIME.some((pattern) => pattern.test(file.mime)))) {
        errors.attachments = 'Solo imágenes, PDF o Excel.';
    }
    return errors;
}

/** Errors of one step, keyed by draft field. Empty object = the step is fine. */
export function validateStep(step: WizardStepNumber, draft: RequestDraft, today: string): StepErrors {
    switch (step) {
        case 1:
            return checkType(draft);
        case 2:
            return checkProblem(draft);
        case 3:
            return checkTime(draft);
        case 4:
            return checkResult(draft);
        case 5:
            return {};
        case 6:
            return checkUrgency(draft, today);
        case 7:
            return checkReview(draft);
    }
}

/**
 * First step with errors, or null when the draft can be sent. A +1 on a
 * similar request only needs step 1 (the requester does not fill the rest).
 */
export function firstInvalidStep(draft: RequestDraft, today: string): WizardStepNumber | null {
    const steps = draft.plus_one_key !== null ? WIZARD_STEPS.slice(0, 1) : WIZARD_STEPS;
    for (const { number } of steps) {
        if (Object.keys(validateStep(number, draft, today)).length > 0) return number;
    }
    return null;
}

export function canSubmit(draft: RequestDraft, today: string): boolean {
    return firstInvalidStep(draft, today) === null;
}

const FIELD_STEPS: Record<string, WizardStepNumber> = {
    type: 1,
    product_slug: 1,
    problem: 2,
    plus_one_key: 2,
    hours_per_week: 3,
    people: 3,
    expected_result: 4,
    success_criteria: 4,
    systems: 5,
    systems_notes: 5,
    urgency: 6,
    deadline: 6,
    deadline_reason: 6,
    area_id: 7,
    attachments: 7,
};

/**
 * Step that shows a field, for Laravel error keys too (`attachments.0`,
 * `systems.1`). Unknown keys land on the review step, which lists everything.
 */
export function stepOfField(field: string): WizardStepNumber {
    return FIELD_STEPS[field.split('.')[0] ?? field] ?? 7;
}

/** Earliest step with at least one error, or null without errors. */
export function firstStepWithErrors(errors: Record<string, string | undefined>): WizardStepNumber | null {
    const steps = Object.keys(errors)
        .filter((key) => errors[key])
        .map(stepOfField);
    return steps.length > 0 ? (Math.min(...steps) as WizardStepNumber) : null;
}
