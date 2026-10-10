import { describe, expect, it } from 'vitest';
import { extractRequestKeys, normalizeRequestKey, suggestedBranch } from './githubKeys';

describe('extractRequestKeys', () => {
    it('finds the key in a PR title', () => {
        expect(extractRequestKeys({ title: 'GIA-139 endpoint de zonas por asesor' })).toEqual(['GIA-139']);
    });

    it('finds the key in the body and in the branch name, case-insensitively', () => {
        expect(extractRequestKeys({ body: 'Cierra gia-123 y revisa el filtro' })).toEqual(['GIA-123']);
        expect(extractRequestKeys({ branch: 'gia-123-arreglar-filtro' })).toEqual(['GIA-123']);
        expect(extractRequestKeys({ branch: 'feature/GIA-77-login' })).toEqual(['GIA-77']);
    });

    it('dedupes across sources and keeps first-seen order', () => {
        expect(
            extractRequestKeys({
                title: 'GIA-139 · T3 endpoint',
                body: 'Relacionado con GIA-112. Cierra gia-139.',
                branch: 'gia-139-endpoint-zonas',
            }),
        ).toEqual(['GIA-139', 'GIA-112']);
    });

    it('keeps zero padding as written', () => {
        expect(extractRequestKeys({ title: 'GIA-095 entrada con Microsoft' })).toEqual(['GIA-095']);
    });

    it('ignores look-alikes', () => {
        expect(extractRequestKeys({ title: 'MEGIA-12 no cuenta', body: 'gia123 tampoco, ni GIA- ni xgia-5' })).toEqual([]);
        expect(extractRequestKeys({ title: null, body: undefined, branch: '' })).toEqual([]);
    });

    it('stops at the digits (no letters glued after the number)', () => {
        expect(extractRequestKeys({ branch: 'gia-12a-prueba' })).toEqual([]);
        expect(extractRequestKeys({ title: '(GIA-148), GIA-150.' })).toEqual(['GIA-148', 'GIA-150']);
    });
});

describe('normalizeRequestKey', () => {
    it('upper-cases a valid key and refuses anything else', () => {
        expect(normalizeRequestKey('gia-139')).toBe('GIA-139');
        expect(normalizeRequestKey(' GIA-7 ')).toBe('GIA-7');
        expect(normalizeRequestKey('GIA139')).toBeNull();
    });
});

describe('suggestedBranch', () => {
    it('joins the key and the task key, as on the task brief', () => {
        expect(suggestedBranch('GIA-139', { key: 'T4' })).toBe('gia-139-t4');
    });

    it('uses a short description slug when there is no task', () => {
        expect(suggestedBranch('GIA-123', null, 'Arreglar filtro')).toBe('gia-123-arreglar-filtro');
        expect(suggestedBranch('GIA-123')).toBe('gia-123');
    });

    it('adds the description after the task key and keeps it short and ASCII', () => {
        expect(suggestedBranch('gia-139', { key: 'T3' }, 'Endpoint de zonas por asesor y región comercial')).toBe(
            'gia-139-t3-endpoint-de-zonas-por-asesor',
        );
    });

    it('is always found again by extractRequestKeys', () => {
        const branch = suggestedBranch('GIA-139', { key: 'T4' }, 'Consulta de cartera');
        expect(extractRequestKeys({ branch })).toEqual(['GIA-139']);
    });
});
