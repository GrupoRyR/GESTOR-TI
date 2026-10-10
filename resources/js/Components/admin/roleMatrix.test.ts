import { describe, expect, it } from 'vitest';
import { changedCells, hasPermission, rolesPayload, togglePermission, type RoleGrant } from './roleMatrix';

const INITIAL: RoleGrant[] = [
    { name: 'Súper Admin', permissions: ['ai.manage', 'ai.requests.create'] },
    { name: 'Usuario final', permissions: ['ai.requests.create'] },
];

describe('togglePermission', () => {
    it('adds a permission to one role without touching the others', () => {
        const next = togglePermission(INITIAL, 'Usuario final', 'ai.costs.view', true);
        expect(next[1]?.permissions).toEqual(['ai.requests.create', 'ai.costs.view']);
        expect(next[0]).toBe(INITIAL[0]);
    });

    it('removes a permission and never mutates the input', () => {
        const next = togglePermission(INITIAL, 'Súper Admin', 'ai.manage', false);
        expect(next[0]?.permissions).toEqual(['ai.requests.create']);
        expect(INITIAL[0]?.permissions).toEqual(['ai.manage', 'ai.requests.create']);
    });

    it('is a no-op when the cell already has that value', () => {
        expect(togglePermission(INITIAL, 'Súper Admin', 'ai.manage', true)).toBe(INITIAL);
        expect(togglePermission(INITIAL, 'Usuario final', 'ai.manage', false)).toBe(INITIAL);
    });
});

describe('hasPermission', () => {
    it('reads one cell', () => {
        expect(hasPermission(INITIAL, 'Súper Admin', 'ai.manage')).toBe(true);
        expect(hasPermission(INITIAL, 'Usuario final', 'ai.manage')).toBe(false);
        expect(hasPermission(INITIAL, 'Desconocido', 'ai.manage')).toBe(false);
    });
});

describe('changedCells', () => {
    it('is 0 for an untouched matrix', () => {
        expect(changedCells(INITIAL, INITIAL)).toBe(0);
    });

    it('counts every cell that differs from the saved matrix', () => {
        let next = togglePermission(INITIAL, 'Usuario final', 'ai.costs.view', true);
        next = togglePermission(next, 'Súper Admin', 'ai.manage', false);
        expect(changedCells(INITIAL, next)).toBe(2);
    });

    it('goes back to 0 when a change is undone', () => {
        const on = togglePermission(INITIAL, 'Usuario final', 'ai.costs.view', true);
        const off = togglePermission(on, 'Usuario final', 'ai.costs.view', false);
        expect(changedCells(INITIAL, off)).toBe(0);
    });

    it('ignores permission order', () => {
        const reordered: RoleGrant[] = [{ name: 'Súper Admin', permissions: ['ai.requests.create', 'ai.manage'] }, INITIAL[1]!];
        expect(changedCells(INITIAL, reordered)).toBe(0);
    });
});

describe('rolesPayload', () => {
    it('sends every role with its permissions sorted', () => {
        const next = togglePermission(INITIAL, 'Usuario final', 'ai.area.view', true);
        expect(rolesPayload(next)).toEqual([
            { name: 'Súper Admin', permissions: ['ai.manage', 'ai.requests.create'] },
            { name: 'Usuario final', permissions: ['ai.area.view', 'ai.requests.create'] },
        ]);
    });
});
