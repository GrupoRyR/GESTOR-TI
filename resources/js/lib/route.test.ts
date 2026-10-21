import { afterEach, describe, expect, it } from 'vitest';
import { hasRoute } from './route';

describe('hasRoute', () => {
    afterEach(() => {
        globalThis.route = undefined;
    });

    it('treats every route as present in the backend-free preview', () => {
        globalThis.route = undefined;
        expect(hasRoute('ai.proposals.create')).toBe(true);
    });

    it('asks Ziggy whether the named route exists', () => {
        const known = new Set(['ai.backlog.index']);
        globalThis.route = ((name?: string) => {
            if (name === undefined) {
                return { has: (candidate: string) => known.has(candidate) };
            }
            return `/${name}`;
        }) as unknown as typeof globalThis.route;

        expect(hasRoute('ai.backlog.index')).toBe(true);
        expect(hasRoute('ai.proposals.create')).toBe(false);
    });
});
