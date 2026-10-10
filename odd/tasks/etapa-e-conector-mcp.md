# Stage E · Conector MCP (backend)

Source of truth: `PROMPT_GESTOR_IA_APP.md` §6.4.7 step 3 ("Conector MCP del Gestor IA"), §8. No design PR for this stage (user: "Arranca con el backend de la etapa E", 2026-10-10).

## Objective
Expose the Gestor IA as an HTTP MCP server that Jhon (Líder IA) and the Gerente de TI add as a custom connector in Claude (claude.ai, desktop, Claude Code), each with their own identity, to search requests, read a request with its documentation, create and update tasks, add progress notes and attach documents. Must work on GoDaddy shared hosting.

## Decisions
- 2026-10-10 (orchestrator, from primary-source research):
  - claude.ai custom connectors identify each user only through OAuth. Static header auth is a limited beta and an organization-wide credential (https://claude.com/docs/connectors/building/authentication). So the spec's "token por usuario" is implemented as per-user OAuth tokens.
  - Stack: `laravel/mcp` (v1.x, Laravel 13) plus `laravel/passport` with `Mcp::oauthRoutes()`: RFC 9728/8414 metadata, DCR at `/oauth/register`, PKCE S256.
  - The HTTP transport is plain JSON per POST, which suits cPanel. No tool streams.
- Access: `ai.mcp.use` (Líder IA; Súper Admin `*`) is checked on every request, not only at OAuth consent. Tools respect company scope and existing policies. Writes are audited with origin `api`.
- Never exposed through MCP: vault items, credentials, rates, salaries. Costs and hours only with `ai.costs.view`. Internal notes only for users who already see them in the app.
- Consent screen: a minimal Blade view (Spanish). It sits outside the design-owned folders, so it is flagged for design.
- Claude Code: the same OAuth flow (`/mcp`). If Passport rejects its changing loopback port, a fallback gets decided then (Sanctum header).

## Delivery
- Strategy: stacked-to-main. Slices `stage-e/NN-*` stacked on `stage-d/07-suggestions`. RDD off.
- Checks: `php artisan test`, `vendor/bin/pint --test`, `npm run typecheck`, `npm test`, `npm run build`.

## Tasks
- [ ] E1 · MCP + OAuth foundation:
  - `composer require laravel/mcp laravel/passport`.
  - Passport: keys from env, `api` guard, `OAuthenticatable` User, consent view.
  - `Mcp::oauthRoutes()`; server `GestorIaServer` at `/mcp/gestor-ia` behind `auth:api` and `ai.mcp.use`.
  - Loopback redirect for any port.
  - Tests: metadata documents, 401 `WWW-Authenticate`, DCR, token, `initialize` and `tools/list`, users without permission.
  - Route: delegated (writer trigger).
- [ ] E2 · Tools:
  - `buscar_solicitudes`, `ver_solicitud` (with docs and criteria), `crear_tarea`, `actualizar_tarea`, `agregar_nota_avance`, `adjuntar_documento`.
  - Company scope, policies, audit and whitelist leak tests.
  - Route: delegated.
- [ ] E3 · Close: deploy doc (Passport keys, WAF/ModSecurity for `/.well-known` and `/oauth/*`, adding the connector in claude.ai and Claude Code, hosting test checklist), full checks, PRs.

## Progress / evidence

## Open questions (for Jhon)
- "Gerente de TI" is not a role in `config/permissions.php` (only Súper Admin, Líder IA, approvers, Usuario final): today `ai.mcp.use` = Líder IA + Súper Admin. Create a "Gerente de TI" role or give it to a specific user?
