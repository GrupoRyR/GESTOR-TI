/**
 * Conector para Claude (`Pages/Profile/Mcp.tsx` → `Profile/Mcp`).
 * Mockups: canvas page "Etapa E · Conector MCP" (E-McpTokens, E-McpCreate,
 * E-McpCreated, E-McpSetup, E-McpActivity, E-McpPhone, E-McpStates).
 *
 * Permission: `ai.mcp.use` (roles Líder IA and Gerente de TI; today Jhon
 * Castro and Leonardo Herrera). Without it the page only shows who has it:
 * the server then sends `tokens: []`, `activity: null`, `selected_token: null`,
 * `connection_test: null`, `created_token: null`, and every route below
 * answers 403. Each person only lists, creates and revokes THEIR OWN tokens.
 *
 * Ziggy routes used:
 * - `profile.mcp`               GET     query `?token={id}|all` (optional)   this page; `token` opens the activity
 *                                        panel. "Actividad" links do a partial reload
 *                                        `only: ['activity', 'selected_token']` with preserveScroll/State.
 * - `profile.mcp.tokens.store`  POST    body `{ name: string (1..60), scope: 'read' | 'read_write',
 *                                              expires_in_days: 30 | 90 | 180 }`
 *                                        Redirects back to `profile.mcp` with `created_token` set for that ONE
 *                                        response, `Inertia::encryptHistory()` and `Cache-Control: no-store`.
 *                                        The value is hashed at rest; it never goes to logs, URLs or later props.
 *                                        Errors: `name` (required, unique among the user's active tokens).
 * - `profile.mcp.tokens.destroy` DELETE  params `{ token: id }`                 "Revocar" / "Quitar de la lista"
 *                                        (revokes; the row moves to "Revocados", its audit log is kept).
 * - `profile.mcp.test`          POST    —                                     "Probar conexión": the server calls its
 *                                        own MCP endpoint (10 s timeout) and returns `connection_test`
 *                                        (partial reload `only: ['connection_test', 'server']`).
 * - `profile.edit`              GET     —                                     breadcrumb / back link
 * - `assistant.index`           GET     —                                     no-permission state
 *
 * MCP server contract (not a page route): `POST /mcp` with
 * `Authorization: Bearer <token>`. 401 for an expired or revoked token, 403
 * when a read-only token calls a write tool (logged as `rejected`). Every
 * call is audited (user, token, tool, when, result). It NEVER returns vault
 * content, credentials, rates, costs or salaries.
 */
import type { McpActivity, McpConnectionTest, McpCreatedToken, McpServer, McpToken } from '@/types/mcp';

export interface ProfileMcpProps {
    server: McpServer;
    /** Active, expired and revoked tokens of the signed-in user (revoked ones are listed collapsed). */
    tokens: McpToken[];
    /** Last "Probar conexión" of this session; `null` = not tested yet. */
    connection_test: McpConnectionTest | null;
    /** Only in the response to `profile.mcp.tokens.store`; the UI wipes it on "Ya lo guardé". */
    created_token: McpCreatedToken | null;
    /** Token selected with `?token=`; `null` with `activity` set = all tokens. */
    selected_token: McpToken | null;
    /** `null` = the activity panel is closed (no `?token=`). */
    activity: McpActivity | null;
    /** Names of the people who have `ai.mcp.use`, for the no-permission state. */
    mcp_holders: string[];
    /** Server time (ISO), for "hoy"/"ayer" and days left. */
    now: string;
    /** Gallery only: open a dialog or scroll to a section on load. The server never sends it. */
    preview?: {
        dialog?: 'create' | 'revoke';
        /** Token for `dialog: 'revoke'`. */
        revoke_token_id?: number;
        anchor?: 'setup' | 'activity';
    };
}
