import { describe, expect, it } from 'vitest';
import { validationState, type CriterionAnswer } from './validation';

const answer = (id: number, result: CriterionAnswer['result'], comment = ''): CriterionAnswer => ({ id, result, comment });

describe('validationState', () => {
    it('counts answered criteria and blocks until all are answered', () => {
        const state = validationState([answer(1, 'pass'), answer(2, 'fail', 'Sale sin descuento'), answer(3, null), answer(4, null)]);
        expect(state).toMatchObject({ total: 4, answered: 2, missing: 2, canSubmit: false });
        expect(state.message).toBe('Faltan 2 criterios por responder.');
    });

    it('requires a comment on every "No cumple"', () => {
        const state = validationState([answer(1, 'pass'), answer(2, 'fail', '   ')]);
        expect(state.canSubmit).toBe(false);
        expect(state.failWithoutComment).toEqual([2]);
        expect(state.message).toBe('Cuenta qué falló en el criterio 2.');
    });

    it('allows sending when everything is answered', () => {
        const state = validationState([answer(1, 'pass'), answer(2, 'fail', 'Sale sin descuento')]);
        expect(state.canSubmit).toBe(true);
        expect(state.message).toBe('1 cumple y 1 no cumple. Ya puedes enviarla.');
    });

    it('uses the singular for one missing criterion', () => {
        expect(validationState([answer(1, null)]).message).toBe('Falta 1 criterio por responder.');
    });
});
