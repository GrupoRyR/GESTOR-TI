import { describe, expect, it } from 'vitest';
import {
    buildProposalPayload,
    emptyProposal,
    errorSummary,
    estimateTotal,
    livePriority,
    noticeLine,
    parseDecimal,
    proposalOutcome,
    submitSummary,
    validateProposal,
    wipWarning,
    type OutcomeInput,
    type ProposalForm,
} from './proposal';

/** Intl uses no-break spaces in some dates; compare with plain ones. */
const norm = (value: string) => value.replace(/ /g, ' ');

const JHON = { id: 1, name: 'Jhon Castro', job_title: 'Líder IA' };
const LEONARDO = { id: 2, name: 'Leonardo Herrera', job_title: 'Gerente de TI' };
const EDWARD = { id: 3, name: 'Edward Ramanan', job_title: 'CEO' };
const TEAM = [JHON, LEONARDO];

function filled(overrides: Partial<ProposalForm> = {}): ProposalForm {
    return {
        ...emptyProposal(TEAM, [JHON, EDWARD]),
        type: 'improvement',
        product_slug: 'cuentas-por-pagar',
        title: 'Cuentas por pagar: cruzar las facturas de proveedores con las entradas de SAP B1',
        problem: 'Antes de pagar, Contabilidad cruza a mano cada factura radicada contra la entrada de mercancía en SAP.',
        current_process: 'Exportan las entradas de SAP a Excel.',
        expected_result: 'Que la app marque sola las facturas que coinciden.',
        area_id: 13,
        benefited_area_ids: [15, 16],
        hours_per_week: '5',
        people: '3',
        risk: 'medium',
        urgency: 'medium',
        size: 'M',
        hours: { 1: '32', 2: '8' },
        due_date: '2026-11-27',
        project_name: 'Cuentas por pagar · cruce con SAP',
        start_date: '2026-10-13',
        ...overrides,
    };
}

describe('emptyProposal', () => {
    it('starts with one empty hours box per team member and every approver selected', () => {
        const form = emptyProposal(TEAM, [JHON, EDWARD]);
        expect(form.hours).toEqual({ 1: '', 2: '' });
        expect(form.approver_ids).toEqual([1, 3]);
        expect(form.type).toBeNull();
        expect(form.risk).toBe('none');
        expect(form.priority_final).toBeNull();
    });
});

describe('parseDecimal', () => {
    it('reads numbers as typed in Colombia', () => {
        expect(parseDecimal('5')).toBe(5);
        expect(parseDecimal('1,5')).toBe(1.5);
        expect(parseDecimal(' 2.5 ')).toBe(2.5);
    });

    it('returns null for empty or non-numeric text', () => {
        expect(parseDecimal('')).toBeNull();
        expect(parseDecimal('   ')).toBeNull();
        expect(parseDecimal('abc')).toBeNull();
    });
});

describe('estimateTotal', () => {
    it('adds the typed hours and ignores empty or negative boxes', () => {
        expect(estimateTotal({ 1: '32', 2: '8' })).toBe(40);
        expect(estimateTotal({ 1: '1,5', 2: '' })).toBe(1.5);
        expect(estimateTotal({ 1: '-4', 2: 'x' })).toBe(0);
    });
});

describe('livePriority', () => {
    it('uses lib/priority with no votes (15 h/sem, talla M, urgencia media = 43)', () => {
        expect(livePriority(filled())).toBe(43);
    });

    it('is null without a size or with invalid hours', () => {
        expect(livePriority(filled({ size: null }))).toBeNull();
        expect(livePriority(filled({ hours_per_week: '90' }))).toBeNull();
    });
});

describe('validateProposal', () => {
    it('accepts a complete self-approve proposal', () => {
        expect(validateProposal(filled(), { canSelfApprove: true })).toEqual({});
    });

    it('reuses the wizard messages for type, app, problem, time, result and urgency', () => {
        const errors = validateProposal(
            filled({ type: 'bug', product_slug: null, problem: 'corto', hours_per_week: '90', expected_result: ' ', urgency: null }),
            { canSelfApprove: true },
        );
        expect(errors.product_slug).toBe('Escoge la app.');
        expect(errors.problem).toBe('Cuéntalo en una o dos frases (mínimo 20 caracteres).');
        expect(errors.hours_per_week).toBe('Escribe entre media hora y 60 horas por semana.');
        expect(errors.expected_result).toBe('Cuéntanos qué esperas que pase.');
        expect(errors.urgency).toBe('Escoge para cuándo lo necesitas.');
        expect(validateProposal(filled({ type: null }), { canSelfApprove: true }).type).toBe('Escoge qué necesitas.');
    });

    it('lets an idea leave hours and people empty, like the wizard', () => {
        expect(validateProposal(filled({ type: 'idea', product_slug: null, hours_per_week: '', people: '' }), { canSelfApprove: true })).toEqual({});
    });

    it('checks the title, the area, the size and the estimate', () => {
        const errors = validateProposal(filled({ title: ' ', area_id: null, size: null, hours: { 1: '', 2: '0' }, due_date: '' }), { canSelfApprove: true });
        expect(errors).toMatchObject({
            title: 'Escribe un título.',
            area_id: 'Escoge el área principal.',
            size: 'Elige la talla.',
            hours: 'Escribe las horas de al menos una persona.',
            due_date: 'Elige la fecha estimada de entrega.',
        });
        expect(validateProposal(filled({ title: 'x'.repeat(121) }), { canSelfApprove: true }).title).toBe('Usa hasta 120 caracteres.');
    });

    it('asks for the project name and start only when self-approving', () => {
        const form = filled({ project_name: ' ', start_date: '' });
        expect(validateProposal(form, { canSelfApprove: true })).toEqual({
            project_name: 'Escribe el nombre del proyecto.',
            start_date: 'Elige cuándo empieza.',
        });
        expect(validateProposal(form, { canSelfApprove: false })).toEqual({});
    });

    it('asks for at least one approver only when going to approval', () => {
        const form = filled({ approver_ids: [] });
        expect(validateProposal(form, { canSelfApprove: false })).toEqual({ approver_ids: 'Elige al menos un aprobador.' });
        expect(validateProposal(form, { canSelfApprove: true })).toEqual({});
    });
});

describe('errorSummary', () => {
    it('counts the fields with errors and points to the first one in form order', () => {
        expect(errorSummary({ due_date: 'x', hours_per_week: 'y' })).toEqual({ count: 2, first: { field: 'hours_per_week', label: 'Horas por semana' } });
    });

    it('folds server keys of the estimate rows into one field', () => {
        expect(errorSummary({ 'hours.0.hours': 'a', 'hours.1.hours': 'b' })).toEqual({ count: 1, first: { field: 'hours', label: 'Horas por persona' } });
        expect(errorSummary({ 'benefited_area_ids.0': 'a' }).first).toEqual({ field: 'benefited_area_ids', label: 'También se benefician' });
    });

    it('ignores empty messages and reports nothing without errors', () => {
        expect(errorSummary({ title: '' })).toEqual({ count: 0, first: null });
        expect(errorSummary({})).toEqual({ count: 0, first: null });
    });
});

describe('buildProposalPayload', () => {
    it('sends the project fields and no approvers when self-approving', () => {
        const payload = buildProposalPayload(filled(), { canSelfApprove: true });
        expect(payload).toMatchObject({
            type: 'improvement',
            product_slug: 'cuentas-por-pagar',
            area_id: 13,
            benefited_area_ids: [15, 16],
            hours_per_week: 5,
            people: 3,
            size: 'M',
            urgency: 'medium',
            risk: 'medium',
            priority_suggested: 43,
            priority_final: 43,
            hours: [
                { person_id: 1, hours: 32 },
                { person_id: 2, hours: 8 },
            ],
            due_date: '2026-11-27',
            project_name: 'Cuentas por pagar · cruce con SAP',
            start_date: '2026-10-13',
        });
        expect(payload).not.toHaveProperty('approver_ids');
    });

    it('sends the approvers and no project fields when going to approval', () => {
        const payload = buildProposalPayload(filled({ approver_ids: [1, 3] }), { canSelfApprove: false });
        expect(payload.approver_ids).toEqual([1, 3]);
        expect(payload).not.toHaveProperty('project_name');
        expect(payload).not.toHaveProperty('start_date');
    });

    it('drops empty estimate rows, trims text and keeps an overridden final priority', () => {
        const payload = buildProposalPayload(filled({ hours: { 1: '', 2: '1,5' }, title: '  Robot  ', priority_final: 70 }), { canSelfApprove: false });
        expect(payload.hours).toEqual([{ person_id: 2, hours: 1.5 }]);
        expect(payload.title).toBe('Robot');
        expect(payload.priority_suggested).toBe(43);
        expect(payload.priority_final).toBe(70);
    });

    it('clears the app for new things and ideas, and sends empty time as null', () => {
        const payload = buildProposalPayload(filled({ type: 'idea', product_slug: 'cuentas-por-pagar', hours_per_week: '', people: '' }), { canSelfApprove: true });
        expect(payload.product_slug).toBeNull();
        expect(payload.hours_per_week).toBeNull();
        expect(payload.people).toBeNull();
    });
});

const selfInput: OutcomeInput = {
    canSelfApprove: true,
    requestKey: 'GIA-154',
    projectKey: 'P-08',
    size: 'M',
    urgency: 'medium',
    priority: 43,
    totalHours: 40,
    dueDate: '2026-11-27',
    projectName: 'Cuentas por pagar · cruce con SAP',
    startDate: '2026-10-13',
    approvers: [JHON, EDWARD],
    proposerId: 1,
    team: TEAM,
    hours: { 1: '32', 2: '8' },
};

describe('proposalOutcome', () => {
    it('lists request, own approval, project and team when self-approving', () => {
        const lines = proposalOutcome(selfInput).map((line) => ({ ...line, detail: norm(line.detail), detailEnd: line.detailEnd && norm(line.detailEnd) }));
        expect(lines).toEqual([
            { key: 'request', title: 'Se crea la solicitud GIA-154 a tu nombre', detail: 'Ya evaluada: talla M, urgencia media, prioridad 43.' },
            {
                key: 'approved',
                title: 'Queda aprobada por ti',
                detail: 'En el historial: “Aprobada por quien la propuso”, con 40 h y entrega el 27 nov.',
            },
            { key: 'project', title: 'Se crea el proyecto P-08', detail: 'Cuentas por pagar · cruce con SAP,', status: 'planned', detailEnd: 'desde el 13 oct.' },
            { key: 'team', title: 'Equipo del proyecto', detail: 'Tú como líder del proyecto; Leonardo Herrera en desarrollo.' },
        ]);
    });

    it('lists request, approval round and no project yet when going to approval', () => {
        const lines = proposalOutcome({
            ...selfInput,
            canSelfApprove: false,
            size: 'L',
            priority: null,
            totalHours: 48,
            dueDate: '2026-12-18',
            proposerId: 2,
        });
        expect(lines.map((line) => line.key)).toEqual(['request', 'approval', 'later']);
        expect(lines[0]?.detail).toBe('Ya evaluada: talla L, urgencia media y la prioridad que resulte.');
        expect(lines[1]).toMatchObject({
            title: 'Va a aprobación de Jhon Castro o Edward Ramanan',
            detail: 'Queda',
            status: 'pending_approval',
        });
        expect(norm(lines[1]?.detailEnd ?? '')).toBe('con 48 h y entrega el 18 dic.');
        expect(lines[2]).toEqual({ key: 'later', title: 'Todavía no hay proyecto', detail: 'Cuando la aprueben, la conviertes en proyecto desde la solicitud.' });
    });

    it('falls back to generic wording without keys or values', () => {
        const lines = proposalOutcome({
            ...selfInput,
            requestKey: null,
            projectKey: null,
            size: null,
            urgency: null,
            priority: null,
            totalHours: 0,
            dueDate: '',
            projectName: '',
            startDate: '',
            hours: { 1: '', 2: '' },
        });
        expect(lines[0]).toMatchObject({ title: 'Se crea la solicitud a tu nombre', detail: 'Ya evaluada con la talla, la urgencia y la prioridad que elijas.' });
        expect(lines[1]?.detail).toBe('En el historial: “Aprobada por quien la propuso”.');
        expect(lines[2]).toMatchObject({ title: 'Se crea el proyecto', detail: '', status: 'planned', detailEnd: 'cuando elijas la fecha de inicio.' });
        expect(lines[3]?.detail).toBe('Tú como líder del proyecto.');
    });

    it('names only the selected approvers and joins three with commas', () => {
        const three = [JHON, EDWARD, { id: 9, name: 'Camila Torres' }];
        expect(proposalOutcome({ ...selfInput, canSelfApprove: false, approvers: three })[1]?.title).toBe(
            'Va a aprobación de Jhon Castro, Edward Ramanan o Camila Torres',
        );
        expect(proposalOutcome({ ...selfInput, canSelfApprove: false, approvers: [] })[1]?.title).toBe('Va a aprobación');
    });
});

describe('noticeLine', () => {
    it('names who gets the informative notice', () => {
        expect(noticeLine([EDWARD])).toBe('Edward Ramanan recibe un aviso con lo que propusiste.');
        expect(noticeLine([EDWARD, LEONARDO])).toBe('Edward Ramanan y Leonardo Herrera reciben un aviso con lo que propusiste.');
        expect(noticeLine([])).toBeNull();
    });
});

describe('wipWarning', () => {
    it('warns only when the WIP limit is reached', () => {
        expect(wipWarning({ in_development: 3, limit: 3 }, 'P-08')).toBe('Hay 3 de 3 proyectos en desarrollo. P-08 espera en Planificada hasta que uno pase a pruebas.');
        expect(wipWarning({ in_development: 3, limit: 3 }, null)).toBe(
            'Hay 3 de 3 proyectos en desarrollo. El proyecto espera en Planificada hasta que uno pase a pruebas.',
        );
        expect(wipWarning({ in_development: 2, limit: 3 }, 'P-08')).toBeNull();
    });
});

describe('submitSummary', () => {
    it('sums up the self-approve result for the phone bar', () => {
        const summary = submitSummary(selfInput);
        expect(summary.text).toBe('Se crea GIA-154 aprobada por ti y el proyecto P-08');
        expect(summary.status).toBe('planned');
        expect(norm(summary.end ?? '')).toBe('desde el 13 oct.');
    });

    it('sums up the approval path and falls back without keys', () => {
        expect(submitSummary({ ...selfInput, canSelfApprove: false })).toEqual({ text: 'Se crea GIA-154 y va a aprobación de Jhon Castro o Edward Ramanan.' });
        expect(submitSummary({ ...selfInput, canSelfApprove: false, requestKey: null, approvers: [] })).toEqual({ text: 'Se crea la solicitud y va a aprobación.' });
        expect(submitSummary({ ...selfInput, requestKey: null, projectKey: null, startDate: '' })).toEqual({
            text: 'Se crea la solicitud aprobada por ti y el proyecto',
            status: 'planned',
            end: undefined,
        });
    });
});
