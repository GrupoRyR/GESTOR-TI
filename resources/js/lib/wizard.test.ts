import { describe, expect, it } from 'vitest';
import {
    canSubmit,
    emptyDraft,
    firstInvalidStep,
    MAX_ATTACHMENT_BYTES,
    teamHours,
    validateStep,
    WIZARD_STEPS,
    type RequestDraft,
} from './wizard';

const TODAY = '2026-10-07';

/** GIA-150 as Andrés sends it on step 7. */
const COMPLETE: RequestDraft = {
    ...emptyDraft(),
    type: 'improvement',
    product_slug: 'portafolio',
    problem: 'Los precios de los proveedores llegan en Excel separados. Para comparar una referencia hay que abrir 3 archivos y copiar a mano.',
    hours_per_week: 6,
    people: 8,
    expected_result: 'Ver los precios de 3 proveedores en una sola tabla del Portafolio',
    success_criteria: 'Comparo una referencia en menos de 1 minuto, sin abrir Excel',
    systems: ['sap_b1', 'excel', 'email', 'portafolio'],
    urgency: 'high',
    deadline: '2026-10-30',
    deadline_reason: 'El 1 de noviembre cambian las listas de precios.',
    area_id: 4,
    attachments: [
        { name: 'captura.png', size: 312_000, mime: 'image/png' },
        { name: 'ejemplo-ficha.xlsx', size: 48_000, mime: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' },
    ],
};

describe('WIZARD_STEPS', () => {
    it('has the 7 approved steps in order', () => {
        expect(WIZARD_STEPS.map((step) => step.title)).toEqual([
            'Qué necesitas',
            'Qué problema hay',
            'Cuánto tiempo',
            'Qué esperas',
            'Sistemas y datos',
            'Urgencia',
            'Revisar y enviar',
        ]);
        expect(WIZARD_STEPS.map((step) => step.number)).toEqual([1, 2, 3, 4, 5, 6, 7]);
    });
});

describe('validateStep', () => {
    it('accepts the complete GIA-150 draft on every step', () => {
        for (const step of WIZARD_STEPS) {
            expect(validateStep(step.number, COMPLETE, TODAY), step.title).toEqual({});
        }
    });

    it('step 1 needs a type, and an app when improving or fixing one', () => {
        expect(validateStep(1, emptyDraft(), TODAY)).toHaveProperty('type');
        expect(validateStep(1, { ...COMPLETE, product_slug: null }, TODAY)).toHaveProperty('product_slug');
        expect(validateStep(1, { ...COMPLETE, type: 'bug', product_slug: null }, TODAY)).toHaveProperty('product_slug');
        expect(validateStep(1, { ...COMPLETE, type: 'new', product_slug: null }, TODAY)).toEqual({});
    });

    it('step 2 needs a problem of at least 20 characters', () => {
        expect(validateStep(2, { ...COMPLETE, problem: '  corto  ' }, TODAY)).toHaveProperty('problem');
        expect(validateStep(2, { ...COMPLETE, problem: 'a'.repeat(2001) }, TODAY)).toHaveProperty('problem');
    });

    it('step 3 needs hours between 0.5 and 60 and at least one person', () => {
        expect(validateStep(3, { ...COMPLETE, hours_per_week: null }, TODAY)).toHaveProperty('hours_per_week');
        expect(validateStep(3, { ...COMPLETE, hours_per_week: 0 }, TODAY)).toHaveProperty('hours_per_week');
        expect(validateStep(3, { ...COMPLETE, hours_per_week: 61 }, TODAY)).toHaveProperty('hours_per_week');
        expect(validateStep(3, { ...COMPLETE, people: 0 }, TODAY)).toHaveProperty('people');
        expect(validateStep(3, { ...COMPLETE, people: 2.5 }, TODAY)).toHaveProperty('people');
    });

    it('step 3 lets an idea skip the time estimate', () => {
        expect(validateStep(3, { ...COMPLETE, type: 'idea', hours_per_week: null, people: null }, TODAY)).toEqual({});
    });

    it('step 4 needs the expected result', () => {
        expect(validateStep(4, { ...COMPLETE, expected_result: ' ' }, TODAY)).toHaveProperty('expected_result');
        expect(validateStep(4, { ...COMPLETE, success_criteria: '' }, TODAY)).toEqual({});
    });

    it('step 5 is optional: TI asks if nothing is marked', () => {
        expect(validateStep(5, { ...COMPLETE, systems: [] }, TODAY)).toEqual({});
    });

    it('step 6 needs an urgency, a future deadline and a reason when a deadline is set', () => {
        expect(validateStep(6, { ...COMPLETE, urgency: null }, TODAY)).toHaveProperty('urgency');
        expect(validateStep(6, { ...COMPLETE, deadline_reason: '' }, TODAY)).toHaveProperty('deadline_reason');
        expect(validateStep(6, { ...COMPLETE, deadline: '2026-10-07' }, TODAY)).toHaveProperty('deadline');
        expect(validateStep(6, { ...COMPLETE, deadline: null, deadline_reason: '' }, TODAY)).toEqual({});
    });

    it('step 7 needs an area and attachments within limits', () => {
        expect(validateStep(7, { ...COMPLETE, area_id: null }, TODAY)).toHaveProperty('area_id');
        expect(
            validateStep(7, { ...COMPLETE, attachments: [{ name: 'video.mp4', size: 1000, mime: 'video/mp4' }] }, TODAY),
        ).toHaveProperty('attachments');
        expect(
            validateStep(7, { ...COMPLETE, attachments: [{ name: 'big.pdf', size: MAX_ATTACHMENT_BYTES + 1, mime: 'application/pdf' }] }, TODAY),
        ).toHaveProperty('attachments');
    });

    it('returns Spanish messages', () => {
        expect(validateStep(1, emptyDraft(), TODAY).type).toBe('Escoge qué necesitas.');
    });
});

describe('firstInvalidStep and canSubmit', () => {
    it('points to the first step with errors', () => {
        expect(firstInvalidStep(emptyDraft(), TODAY)).toBe(1);
        expect(firstInvalidStep({ ...COMPLETE, urgency: null }, TODAY)).toBe(6);
        expect(firstInvalidStep(COMPLETE, TODAY)).toBeNull();
    });

    it('submits only a complete draft', () => {
        expect(canSubmit(COMPLETE, TODAY)).toBe(true);
        expect(canSubmit({ ...COMPLETE, area_id: null }, TODAY)).toBe(false);
    });

    it('lets a +1 on a similar request finish right after step 2', () => {
        const vote: RequestDraft = { ...emptyDraft(), type: 'improvement', product_slug: 'toma-pedidos', plus_one_key: 'GIA-121' };
        expect(firstInvalidStep(vote, TODAY)).toBeNull();
        expect(canSubmit(vote, TODAY)).toBe(true);
    });
});

describe('teamHours', () => {
    it('multiplies hours by people (6 × 8 = 48)', () => {
        expect(teamHours(COMPLETE)).toBe(48);
        expect(teamHours({ ...COMPLETE, people: null })).toBeNull();
    });
});
