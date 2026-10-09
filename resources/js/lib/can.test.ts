import { describe, expect, it } from 'vitest';
import type { AuthUser } from '@/types/shared';
import { can } from './can';

const user = (permissions: string[]): AuthUser => ({
    id: 1,
    name: 'Laura Gómez',
    email: 'laura.gomez@segurex.com.co',
    avatar_url: null,
    company_id: 1,
    roles: ['Solicitante'],
    permissions,
});

describe('can', () => {
    it('returns true when the user holds the permission', () => {
        expect(can(user(['ai.requests.create', 'assistant.use']), 'assistant.use')).toBe(true);
    });

    it('returns false when the user lacks the permission', () => {
        expect(can(user(['ai.requests.create']), 'ai.projects.approve')).toBe(false);
    });

    it('does not treat a prefix as a match', () => {
        expect(can(user(['ai.costs.view']), 'ai.costs.manage')).toBe(false);
    });

    it('returns false for a user without permissions', () => {
        expect(can(user([]), 'ai.manage')).toBe(false);
    });
});
