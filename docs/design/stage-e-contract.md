# Stage E · Frontend contract for the app agent (Conector MCP)

Builds on the [Stage A](stage-a-contract.md) to [Stage D](stage-d-contract.md) contracts. The approved mockups are on the design canvas, page "Etapa E · Conector MCP".

**The source of truth is `types/pages/profile-mcp.ts`.** Its header lists every route, body, permission and server rule.

| Page | What it covers |
|---|---|
| `Profile/Mcp` (new, linked from `Profile/Edit`) | intro with the 6 tools; server URL and "Probar conexión"; personal tokens (create, show once, revoke); "Cómo agregarlo en Claude"; activity per token; states |

- **Domain types:** `types/mcp.ts`.
- **Helpers:** `lib/mcp.ts` (expiry, masking, tools per scope, `claude mcp add` command).
- **Mocks:** `mocks/profile-mcp.ts`.

## Routes

| Route | Method | Params / body | Notes |
|---|---|---|---|
| `profile.mcp` | GET | query `?token={id}\|all` | Selecting a token's activity is a partial reload: `only: ['activity','selected_token']`. |
| `profile.mcp.tokens.store` | POST | `{ name (1..60), scope: 'read' \| 'read_write', expires_in_days: 30 \| 90 \| 180 }` | Error key: `name`. |
| `profile.mcp.tokens.destroy` | DELETE | `{token}` | Revokes the token. Its row moves to "Revocados" and its log is kept. |
| `profile.mcp.test` | POST | — | Returns `connection_test`; `only: ['connection_test','server']`. |

## Rules the backend must enforce

**Who can use it**
- Only `ai.mcp.use` (today Jhon and Leonardo). Each person sees and manages only their own tokens.
- Without the permission, the page shows the no-access state (`mcp_holders`), and tokens and activity come empty.

**Token value (shown once)**
- The value exists only in `created_token`, in the single response after `tokens.store`.
- That response uses `Inertia::encryptHistory()` and `Cache-Control: no-store`.
- Afterwards the app only shows the last 4 characters.
- Store the token hashed. Never put it in URLs, logs or audit entries.

**Scopes**
- `read`: `buscar_solicitudes` and `ver_solicitud`.
- `read_write`: adds `crear_tarea`, `actualizar_tarea`, `agregar_nota` and `adjuntar_documento`.

**MCP server**
- Over HTTP at `https://ti.segurex.co/mcp`, with `Authorization: Bearer <token>`.
- Expired or revoked token: `401`.
- Read-only token calling a write tool: `403`, logged as "Rechazada · token de solo lectura".
- Every call is audited: token, tool, target, when and result.
- The server never returns vault content, credentials, rates, costs or salaries.
- It must work on the shared hosting. `server.active = false` shows "El conector aún no está activo en este servidor".

## Open points
- **Placeholders:** `[PASOS EN CLAUDE]` (exact Claude menu steps, never invented), `[TIEMPO DE RETENCIÓN]` (activity log retention) and `[PASO DE ACTIVACIÓN EN EL HOSTING]`.
- **Token format:** the real format is not defined; the mock uses `gti_mcp_…`.
- **Optional field `last_client`:** the client name, taken from MCP `clientInfo` and used as the token subtitle.
- **"Expiring soon":** a token is flagged at 7 days or less before expiry.
