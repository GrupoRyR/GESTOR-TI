/**
 * MCP connector ("Conector para Claude"): personal tokens that let Claude
 * query and update the Gestor IA over the app's MCP server (HTTP).
 *
 * Rules the server owns:
 * - Only holders of `ai.mcp.use` (today Jhon Castro and Leonardo Herrera)
 *   create, list and revoke tokens, and each person only sees their own.
 * - The token value exists only in `created_token`, right after the store
 *   request. It is never in a URL, a log or any later prop: afterwards only
 *   `last4` is sent.
 * - Every MCP call is audited (who, tool, when, result). The MCP server never
 *   returns vault content, credentials, rates, costs or salaries.
 */

export type McpScope = 'read' | 'read_write';

export type McpToolKey = 'buscar_solicitudes' | 'ver_solicitud' | 'crear_tarea' | 'actualizar_tarea' | 'agregar_nota' | 'adjuntar_documento';

export interface McpTool {
    key: McpToolKey;
    /** "Buscar solicitudes". */
    label: string;
    access: 'read' | 'write';
}

/** Expiry choices offered by "Crear token" (90 is the default). */
export type McpExpiryDays = 30 | 90 | 180;

export interface McpToken {
    id: number;
    /** Chosen by the owner: "Claude · portátil". */
    name: string;
    scope: McpScope;
    /** Last four characters of the value; the UI shows "…7f3a". */
    last4: string;
    created_at: string;
    /** `null` = never used. */
    last_used_at: string | null;
    /**
     * Client name reported on the token's last call (MCP `clientInfo`),
     * shown under the name ("Navegador y escritorio"); `null` if never used
     * or unknown.
     */
    last_client: string | null;
    /** Calendar date `YYYY-MM-DD` (Bogotá) after which the token stops working. */
    expires_at: string;
    /** `null` = still valid (it may be expired by date). */
    revoked_at: string | null;
}

/** Sent ONCE, in the response to `profile.mcp.tokens.store`. */
export interface McpCreatedToken {
    id: number;
    name: string;
    /** The full secret. Never log it, never put it in a URL. */
    value: string;
    scope: McpScope;
    expires_at: string;
}

export interface McpServer {
    /** `https://[DOMINIO DEL GESTOR]/mcp`. */
    url: string;
    /** `false` = the endpoint is not enabled on this hosting yet. */
    active: boolean;
}

/** Result of the last "Probar conexión" (POST `profile.mcp.test`). */
export type McpConnectionTest =
    | { status: 'ok'; /** Round trip in milliseconds. */ ms: number; at: string }
    | { status: 'failed'; /** Machine code, e.g. `mcp_timeout`, `mcp_tls`, `mcp_http_500`. */ code: string; at: string };

export type McpCallResult = 'ok' | 'rejected' | 'error';

/** One audited call. The log never stores the token or what the Gestor answered. */
export interface McpCall {
    id: number;
    tool: McpToolKey;
    /** What it was about, already worded by the server: "GIA-139 · con documentación", "“cartera” · 4 resultados". */
    summary: string;
    at: string;
    token: { id: number; name: string };
    result: McpCallResult;
    /** Why it was rejected or failed: "token de solo lectura". */
    reason: string | null;
}

export interface McpActivity {
    /** Token whose calls are listed; `null` = all of the user's tokens. */
    token_id: number | null;
    /** Newest first. */
    calls: McpCall[];
    /** Only with a token selected: rejected calls of the user's OTHER tokens in the last 7 days. */
    rejected_elsewhere: McpCall[];
    /** How long the log is kept, e.g. "90 días"; `null` hides the note. */
    retention: string | null;
}
