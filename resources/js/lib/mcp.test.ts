import { describe, expect, it } from 'vitest';
import type { McpToken } from '../types/mcp';
import { calendarIso, claudeCodeCommand, daysLeft, expiresAt, formatLatency, maskToken, MCP_TOOLS, tokenStatus, toolsFor } from './mcp';

const NOW = new Date('2026-10-10T09:24:00-05:00');

const token = (overrides: Partial<McpToken> = {}): McpToken => ({
    id: 1,
    name: 'Claude · portátil',
    scope: 'read_write',
    last4: '7f3a',
    created_at: '2026-10-08T08:00:00-05:00',
    last_used_at: null,
    last_client: null,
    expires_at: '2027-01-06',
    revoked_at: null,
    ...overrides,
});

describe('expiresAt', () => {
    it('adds calendar days in Bogotá and returns a plain date', () => {
        expect(expiresAt('2026-10-08T08:00:00-05:00', 90)).toBe('2027-01-06');
        expect(expiresAt('2026-10-10T09:24:00-05:00', 90)).toBe('2027-01-08');
        expect(expiresAt('2026-10-10T09:24:00-05:00', 30)).toBe('2026-11-09');
        expect(expiresAt('2026-10-10T09:24:00-05:00', 180)).toBe('2027-04-08');
    });

    it('uses the Bogotá day even late at night (UTC already tomorrow)', () => {
        expect(expiresAt('2026-10-10T23:30:00-05:00', 30)).toBe('2026-11-09');
    });

    it('accepts a Date', () => {
        expect(expiresAt(NOW, 90)).toBe('2027-01-08');
    });
});

describe('daysLeft', () => {
    it('counts calendar days from today in Bogotá', () => {
        expect(daysLeft('2027-01-06', NOW)).toBe(88);
        expect(daysLeft('2026-11-30', NOW)).toBe(51);
        expect(daysLeft('2026-10-10', NOW)).toBe(0);
    });

    it('is negative once the date has passed', () => {
        expect(daysLeft('2026-10-04', NOW)).toBe(-6);
    });

    it('accepts a full ISO instant', () => {
        expect(daysLeft('2026-11-30T23:59:59-05:00', NOW)).toBe(51);
    });
});

describe('maskToken', () => {
    it('shows only the last four characters after an ellipsis', () => {
        expect(maskToken('7f3a')).toBe('…7f3a');
    });

    it('keeps only four characters even if given more', () => {
        expect(maskToken('gti_mcp_9d2e41c07ab35f68e2d1b0c4')).toBe('…b0c4');
    });
});

describe('toolsFor', () => {
    it('gives the two read tools to a read-only token', () => {
        expect(toolsFor('read').map((tool) => tool.key)).toEqual(['buscar_solicitudes', 'ver_solicitud']);
    });

    it('gives all six tools to a read and write token', () => {
        expect(toolsFor('read_write').map((tool) => tool.key)).toEqual([
            'buscar_solicitudes',
            'ver_solicitud',
            'crear_tarea',
            'actualizar_tarea',
            'agregar_nota',
            'adjuntar_documento',
        ]);
        expect(toolsFor('read_write')).toHaveLength(MCP_TOOLS.length);
    });

    it('marks read and write tools', () => {
        expect(MCP_TOOLS.filter((tool) => tool.access === 'read')).toHaveLength(2);
        expect(MCP_TOOLS.filter((tool) => tool.access === 'write')).toHaveLength(4);
    });
});

describe('claudeCodeCommand', () => {
    it('builds the claude mcp add command with a token placeholder', () => {
        expect(claudeCodeCommand('https://[DOMINIO DEL GESTOR]/mcp')).toBe(
            'claude mcp add --transport http gestor-ia https://[DOMINIO DEL GESTOR]/mcp --header "Authorization: Bearer <token>"',
        );
    });

    it('trims the URL', () => {
        expect(claudeCodeCommand('  https://gestor.example/mcp ')).toBe(
            'claude mcp add --transport http gestor-ia https://gestor.example/mcp --header "Authorization: Bearer <token>"',
        );
    });
});

describe('tokenStatus', () => {
    it('is active with time to spare', () => {
        expect(tokenStatus(token(), NOW)).toEqual({ kind: 'active', days: 88 });
    });

    it('is expiring soon within seven days', () => {
        expect(tokenStatus(token({ expires_at: '2026-10-17' }), NOW)).toEqual({ kind: 'expiring', days: 7 });
        expect(tokenStatus(token({ expires_at: '2026-10-10' }), NOW)).toEqual({ kind: 'expiring', days: 0 });
    });

    it('is expired after its date', () => {
        expect(tokenStatus(token({ expires_at: '2026-10-04' }), NOW)).toEqual({ kind: 'expired', days: -6 });
    });

    it('is revoked whatever its date says', () => {
        expect(tokenStatus(token({ revoked_at: '2026-10-02T10:00:00-05:00', expires_at: '2026-10-04' }), NOW).kind).toBe('revoked');
    });
});

describe('formatLatency', () => {
    it('shows seconds with one decimal and a comma', () => {
        expect(formatLatency(400)).toBe('0,4 s');
        expect(formatLatency(1250)).toBe('1,3 s');
    });

    it('never shows zero for a fast answer', () => {
        expect(formatLatency(20)).toBe('0,1 s');
    });
});

describe('calendarIso', () => {
    it('turns a plain date into noon in Bogotá so it formats as that same day', () => {
        expect(calendarIso('2026-10-04')).toBe('2026-10-04T12:00:00-05:00');
        expect(calendarIso('2026-10-04T08:00:00-05:00')).toBe('2026-10-04T08:00:00-05:00');
    });
});
