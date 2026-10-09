import { describe, expect, it } from 'vitest';
import { roadmapLine, voteLabel } from './roadmap';

describe('roadmapLine', () => {
    it('prefers the server note', () => {
        expect(roadmapLine({ area: { id: 1, name: 'Cartera' }, note: 'Entrega estimada 14 nov', planned_start: null })).toBe('Cartera · Entrega estimada 14 nov');
    });

    it('falls back to the planned start', () => {
        expect(roadmapLine({ area: { id: 1, name: 'Contabilidad' }, note: null, planned_start: '2026-11-03' })).toBe('Contabilidad · Empieza el 3 nov');
    });

    it('shows only the area otherwise', () => {
        expect(roadmapLine({ area: { id: 1, name: 'Ventas' }, note: null, planned_start: null })).toBe('Ventas');
    });
});

describe('voteLabel', () => {
    it('describes the vote button for screen readers', () => {
        expect(voteLabel(0, false)).toBe('Sumar mi voto');
        expect(voteLabel(1, false)).toBe('1 voto, sumar el mío');
        expect(voteLabel(4, false)).toBe('4 votos, sumar el mío');
        expect(voteLabel(3, true)).toBe('3 votos, incluido el tuyo. Quitar mi voto');
    });
});
