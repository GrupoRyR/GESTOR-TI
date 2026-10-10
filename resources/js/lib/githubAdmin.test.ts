import { describe, expect, it } from 'vitest';
import { permissionsSummary, webhookEventLabel } from './githubAdmin';

describe('permissionsSummary', () => {
    it('groups write and read scopes in words', () => {
        expect(
            permissionsSummary([
                { scope: 'issues', access: 'write' },
                { scope: 'pull_requests', access: 'read' },
                { scope: 'contents', access: 'read' },
                { scope: 'releases', access: 'read' },
                { scope: 'actions', access: 'read' },
            ]),
        ).toBe('Issues: leer y escribir · Pull requests, contenido, releases y acciones: solo leer');
    });

    it('keeps unknown scopes as they come and skips empty groups', () => {
        expect(permissionsSummary([{ scope: 'metadata', access: 'read' }])).toBe('Metadata: solo leer');
        expect(permissionsSummary([])).toBe('Sin permisos');
    });
});

describe('webhookEventLabel', () => {
    it('says push only counts on the main branch', () => {
        expect(webhookEventLabel('push')).toBe('push a la rama principal');
        expect(webhookEventLabel('workflow_run')).toBe('workflow_run');
    });
});
