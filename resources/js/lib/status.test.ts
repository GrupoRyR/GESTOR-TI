import { describe, expect, it } from 'vitest';
import { PHASE_LABELS, REQUEST_STATUSES, requestStatus, type RequestStatusKey } from './status';

const KEYS: RequestStatusKey[] = [
    'received',
    'evaluating',
    'needs_info',
    'pending_approval',
    'approved',
    'backlog',
    'rejected',
    'planned',
    'in_development',
    'in_testing',
    'in_production',
    'closed',
];

describe('request status catalog', () => {
    it('has exactly the 12 statuses', () => {
        expect(Object.keys(REQUEST_STATUSES).sort()).toEqual([...KEYS].sort());
    });

    it('gives every status a label, a family and a phase from 1 to 5', () => {
        for (const key of KEYS) {
            const status = requestStatus(key);
            expect(status.label.length).toBeGreaterThan(0);
            expect(['gray', 'amber', 'blue', 'green', 'slate', 'red']).toContain(status.family);
            expect(status.phase).toBeGreaterThanOrEqual(1);
            expect(status.phase).toBeLessThanOrEqual(5);
        }
    });

    it('maps families and phases as approved', () => {
        expect(requestStatus('needs_info')).toMatchObject({ label: 'Necesita información', family: 'amber', phase: 1 });
        expect(requestStatus('rejected')).toMatchObject({ label: 'Rechazada', family: 'red', phase: 2 });
        expect(requestStatus('planned')).toMatchObject({ family: 'blue', phase: 3 });
        expect(requestStatus('in_testing')).toMatchObject({ label: 'En pruebas', family: 'amber', phase: 4 });
        expect(requestStatus('closed')).toMatchObject({ family: 'slate', phase: 5 });
    });

    it('labels the five phases', () => {
        expect(PHASE_LABELS).toEqual({
            1: 'Evaluación',
            2: 'Aprobación',
            3: 'Plan',
            4: 'Construcción',
            5: 'Entrega',
        });
    });
});
