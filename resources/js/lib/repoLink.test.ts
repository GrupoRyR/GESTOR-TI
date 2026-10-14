import { describe, expect, it } from 'vitest';
import type { GitHubRepo } from '../types/github';
import { repoLinkOptions } from './repoLink';

const repo = (name: string, linked_to: GitHubRepo['linked_to'] = null, language: string | null = 'PHP'): GitHubRepo => ({
    full_name: `GrupoRyR/${name}`,
    name,
    url: `https://github.com/GrupoRyR/${name}`,
    private: true,
    default_branch: 'main',
    branches: ['main'],
    language,
    pushed_at: null,
    linked_to,
});

const REPOS = [
    repo('robotcamara', null, 'Python'),
    repo('visualizador-cartera', { kind: 'product', key: 'visualizador-cartera', name: 'Visualizador de cartera' }, 'TypeScript'),
    repo('toma-pedidos', { kind: 'product', key: 'toma-pedidos', name: 'Toma Pedidos SAP' }),
    repo('gestor-ti', { kind: 'project', key: 'P-09', name: 'Gestor TI' }),
];

describe('repoLinkOptions', () => {
    it('keeps the server order and leaves unlinked repos available', () => {
        const options = repoLinkOptions(REPOS, { kind: 'product', key: 'robotcamara' });
        expect(options.map((option) => option.repo.name)).toEqual(['robotcamara', 'visualizador-cartera', 'toma-pedidos', 'gestor-ti']);
        expect(options[0]).toMatchObject({ disabled: false, note: null });
    });

    it('disables repos linked to something else and says to what', () => {
        const [, visualizador, , gestor] = repoLinkOptions(REPOS, { kind: 'product', key: 'robotcamara' });
        expect(visualizador).toMatchObject({ disabled: true, note: 'Vinculado a Visualizador de cartera' });
        expect(gestor).toMatchObject({ disabled: true, note: 'Vinculado a Gestor TI' });
    });

    it('keeps the repo already linked to the same target available', () => {
        const options = repoLinkOptions(REPOS, { kind: 'product', key: 'toma-pedidos' });
        expect(options.find((option) => option.repo.name === 'toma-pedidos')).toMatchObject({ disabled: false, note: 'Vinculado aquí' });
    });

    it("lets a project use its product's repository", () => {
        const options = repoLinkOptions(REPOS, { kind: 'project', key: 'P-06', product_key: 'toma-pedidos' });
        expect(options.find((option) => option.repo.name === 'toma-pedidos')).toMatchObject({ disabled: false, note: 'Del producto Toma Pedidos SAP' });
        expect(options.find((option) => option.repo.name === 'visualizador-cartera')?.disabled).toBe(true);
    });

    it('filters by name or language, ignoring case and accents', () => {
        expect(repoLinkOptions(REPOS, { kind: 'product', key: 'x' }, '  CÁMARA ').map((option) => option.repo.name)).toEqual(['robotcamara']);
        expect(repoLinkOptions(REPOS, { kind: 'product', key: 'x' }, 'nómina').map((option) => option.repo.name)).toEqual([]);
        expect(repoLinkOptions(REPOS, { kind: 'product', key: 'x' }, 'robotcámara').map((option) => option.repo.name)).toEqual(['robotcamara']);
        expect(repoLinkOptions(REPOS, { kind: 'product', key: 'x' }, 'python').map((option) => option.repo.name)).toEqual(['robotcamara']);
        expect(repoLinkOptions(REPOS, { kind: 'product', key: 'x' }, 'CARTERA').map((option) => option.repo.name)).toEqual(['visualizador-cartera']);
    });

    it('returns everything for an empty query', () => {
        expect(repoLinkOptions(REPOS, { kind: 'product', key: 'x' }, '   ')).toHaveLength(4);
    });
});
