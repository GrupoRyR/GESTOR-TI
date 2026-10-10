import type { McpScope, McpTool, McpToken } from '../types/mcp';
import { bogotaDateKey } from './format';

/** The six tools the connector offers, read tools first. */
export const MCP_TOOLS: McpTool[] = [
    { key: 'buscar_solicitudes', label: 'Buscar solicitudes', access: 'read' },
    { key: 'ver_solicitud', label: 'Ver solicitud y su documentación', access: 'read' },
    { key: 'crear_tarea', label: 'Crear tarea', access: 'write' },
    { key: 'actualizar_tarea', label: 'Actualizar tarea', access: 'write' },
    { key: 'agregar_nota', label: 'Agregar nota', access: 'write' },
    { key: 'adjuntar_documento', label: 'Adjuntar documento', access: 'write' },
];

export const MCP_SCOPE_LABELS: Record<McpScope, string> = { read: 'Solo lectura', read_write: 'Lectura y escritura' };

/** Tokens this close to their date (in days) show "Vence pronto". */
export const EXPIRING_SOON_DAYS = 7;

const DAY_MS = 86_400_000;

/** Days since epoch of a `YYYY-MM-DD` calendar date. */
function dayNumber(key: string): number {
    const [year = 1970, month = 1, day = 1] = key.split('-').map(Number);
    return Math.round(Date.UTC(year, month - 1, day) / DAY_MS);
}

function keyOf(value: string | Date): string {
    if (typeof value === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(value)) return value;
    return bogotaDateKey(typeof value === 'string' ? value : value.toISOString());
}

/** Calendar date (`YYYY-MM-DD`, Bogotá) a token created at `created` stops working, `days` later. */
export function expiresAt(created: string | Date, days: number): string {
    const date = new Date((dayNumber(keyOf(created)) + days) * DAY_MS);
    return date.toISOString().slice(0, 10);
}

/** Whole calendar days from today (Bogotá) to `date`; negative once it passed. */
export function daysLeft(date: string, now: Date): number {
    return dayNumber(keyOf(date)) - dayNumber(keyOf(now));
}

/** "…7f3a": only the last four characters are ever shown after creation. */
export function maskToken(last4: string): string {
    return `…${last4.slice(-4)}`;
}

/** Tools a token can call: read-only gets the two read tools, read and write gets all six. */
export function toolsFor(scope: McpScope): McpTool[] {
    return scope === 'read' ? MCP_TOOLS.filter((tool) => tool.access === 'read') : MCP_TOOLS;
}

/** Claude Code command to add the connector; the user swaps `<token>` for theirs. */
export function claudeCodeCommand(url: string): string {
    return `claude mcp add --transport http gestor-ia ${url.trim()} --header "Authorization: Bearer <token>"`;
}

export type TokenStatus =
    | { kind: 'active'; days: number }
    | { kind: 'expiring'; days: number }
    | { kind: 'expired'; days: number }
    | { kind: 'revoked' };

export function tokenStatus(token: Pick<McpToken, 'expires_at' | 'revoked_at'>, now: Date): TokenStatus {
    if (token.revoked_at) return { kind: 'revoked' };
    const days = daysLeft(token.expires_at, now);
    if (days < 0) return { kind: 'expired', days };
    if (days <= EXPIRING_SOON_DAYS) return { kind: 'expiring', days };
    return { kind: 'active', days };
}

/** "en 88 días", "mañana", "hoy". */
export function daysLeftText(days: number): string {
    if (days === 0) return 'hoy';
    if (days === 1) return 'mañana';
    return `en ${days} días`;
}

/** "0,4 s": round trip of "Probar conexión", one decimal, never "0,0 s". */
export function formatLatency(ms: number): string {
    const seconds = Math.max(0.1, Math.round(ms / 100) / 10);
    return `${seconds.toFixed(1).replace('.', ',')} s`;
}

/** Plain `YYYY-MM-DD` → noon in Bogotá, so `formatDate` shows that same calendar day. */
export function calendarIso(date: string): string {
    return /^\d{4}-\d{2}-\d{2}$/.test(date) ? `${date}T12:00:00-05:00` : date;
}
