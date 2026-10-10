/** Acceptance validation by the requester (Validar GIA-127): progress and gate. */

export interface CriterionAnswer {
    id: number;
    result: 'pass' | 'fail' | null;
    /** Required when `result` is `fail`. */
    comment: string;
}

export interface ValidationState {
    total: number;
    answered: number;
    missing: number;
    /** Criterion ids marked "No cumple" without saying what failed. */
    failWithoutComment: number[];
    canSubmit: boolean;
    /** Footer sentence explaining why the button is (not) enabled. */
    message: string;
}

export function validationState(answers: CriterionAnswer[]): ValidationState {
    const total = answers.length;
    const answered = answers.filter((answer) => answer.result !== null).length;
    const missing = total - answered;
    const failWithoutComment = answers.filter((answer) => answer.result === 'fail' && answer.comment.trim() === '').map((answer) => answer.id);
    const canSubmit = total > 0 && missing === 0 && failWithoutComment.length === 0;

    let message: string;
    if (missing > 0) {
        message = missing === 1 ? 'Falta 1 criterio por responder.' : `Faltan ${missing} criterios por responder.`;
    } else if (failWithoutComment.length > 0) {
        const position = answers.findIndex((answer) => answer.id === failWithoutComment[0]) + 1;
        message = `Cuenta qué falló en el criterio ${position}.`;
    } else {
        const passed = answers.filter((answer) => answer.result === 'pass').length;
        const failed = total - passed;
        message = `${passed} cumple${passed === 1 ? '' : 'n'} y ${failed} no cumple${failed === 1 ? '' : 'n'}. Ya puedes enviarla.`;
    }

    return { total, answered, missing, failWithoutComment, canSubmit, message };
}
