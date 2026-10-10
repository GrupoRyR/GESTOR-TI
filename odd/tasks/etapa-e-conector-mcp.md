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
- [x] E1 · MCP + OAuth foundation:
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

### E1 · MCP + OAuth foundation — done
- Route: delegated direct (writer trigger: 2+ non-trivial files).
- Packages: `laravel/mcp` v1.0.1 (`^1.0`), `laravel/passport` v13.9.0 (`^13.9`).
- Published only: `routes/ai.php`, `config/mcp.php`, `config/passport.php`, Passport migrations for auth codes, access tokens, refresh tokens and clients (renamed `2026_10_30_00000N_*` to keep the order; device codes dropped). No vendor views published: the consent views are our own.
- Config:
  - `auth.guards.api` uses the `passport` driver.
  - `User` implements `OAuthenticatable` with `HasApiTokens`. `$guard_name = 'web'` keeps spatie roles and permissions on `web` while `auth:api` switches the default guard. Without it, `can()` returned false on MCP requests.
  - `gestor.mcp`: access token 60 min (`MCP_ACCESS_TOKEN_MINUTES`), refresh token 30 days (`MCP_REFRESH_TOKEN_DAYS`), 120 MCP requests per user per minute, 30 OAuth requests per IP per minute.
  - `mcp.redirect_domains` comes from `MCP_REDIRECT_DOMAINS`. The default allows `https://claude.ai`, `https://claude.com`, `http://localhost` and `http://127.0.0.1` (the package also accepts `[::1]` once a loopback is listed).
- Routes (`routes/ai.php`):
  - `Passport::ignoreRoutes()`, so only what Claude needs is registered.
  - Behind `throttle:mcp-oauth`: `Mcp::oauthRoutes()` (4 `/.well-known/*` routes and `POST /oauth/register`) and `POST /oauth/token` (`passport.token`).
  - With `web` and `EnsureMcpConsent`: `GET /oauth/authorize` and `POST /oauth/authorize` (the POST also needs `auth`).
  - `DELETE /oauth/authorize` uses `web` and `auth`.
  - `POST /mcp/gestor-ia` (`mcp.gestor-ia`) runs `throttle:mcp`, `auth:api` and `EnsureMcpAccess`. GET and DELETE answer 405.
  - `route:cache` works.
- Protected-resource metadata: `resource` = `url('/mcp/gestor-ia')`, i.e. APP_URL + `/mcp/gestor-ia`. A 401 carries `WWW-Authenticate: Bearer realm="mcp", resource_metadata="<APP_URL>/.well-known/oauth-protected-resource/mcp/gestor-ia"`.
- Redirect URIs:
  - DCR rejects plain http outside loopback, other https hosts, look-alike hosts, custom schemes and userinfo.
  - league/oauth2-server ignores the port only for `127.0.0.1` and `[::1]`, not for `localhost`, which Claude Code uses. `App\Support\Mcp\LoopbackAwareAuthCodeGrant` replaces Passport's `authorization_code` grant (`AuthorizationServer` extended in `App\Providers\McpServiceProvider`). It matches http loopback URIs on any port, but only against a registered loopback URI with the same host and path. Everything else keeps the exact match.
  - Tests cover localhost, 127.0.0.1 and [::1] end to end, through the token exchange.
- Access:
  - `EnsureMcpAccess` checks on every request that the token has the `mcp:use` scope, that the user is active and that they hold `ai.mcp.use`. Otherwise it answers 403 with a JSON-RPC error (`-32001`, Spanish message). It runs the request under `AuditOrigin::runAs('api')`, so E2 writes are audited as `api`.
  - `EnsureMcpConsent` shows `mcp.forbidden` (403, "No tienes permiso para usar el conector del Gestor IA") instead of the consent screen or an approval, including Passport's auto-approval.
  - Inactive users are logged out by `EnsureUserIsActive` (web group).
  - Guests go to the existing login, and `url.intended` brings them back to the authorize URL. Microsoft login already uses `redirect()->intended()`.
- Server: `App\Mcp\Servers\GestorIaServer` ("Gestor IA", Spanish instructions), tool `quien_soy` (read-only, idempotent): name, email, roles, home company and `ve_todas_las_empresas`. Plain JSON responses, no streaming.
- Audit (`auth` log, sensitive, origin `api`):
  - `mcp_client_authorized` on approval: causer, `client_id`, `client_name`.
  - `mcp_token_issued` on `AccessTokenCreated`, covering both code and refresh grants. Token values are never logged.
- Test-first: RED 44 tests, 0 passed (25 failures and 19 errors: missing routes). GREEN 44/44 in `tests/Feature/Mcp` (discovery, DCR, full code + PKCE flow through form-urlencoded `/oauth/token`, refresh grant, consent and denial, loopback ports, redirect mismatches, `initialize`, `tools/list`, `tools/call quien_soy`, no permission, permission removed, inactive user, revoked token, scope missing, web session alone, rate limit).
- Checks:
  - `php artisan test --filter=Mcp`: 44 passed.
  - `php artisan test`: 1112 passed (baseline 1068, plus 44).
  - `vendor/bin/pint --test`: passed.
  - `npm run typecheck`: passed.
  - `route:list` for `mcp` (3), `oauth` (9) and `.well-known` (4): as listed above.
- Env keys (server):
  - `PASSPORT_PRIVATE_KEY` and `PASSPORT_PUBLIC_KEY`: PEM content, newlines as `\n`. The fallback is `storage/oauth-*.key` (`php artisan passport:keys`).
  - Optional: `MCP_ACCESS_TOKEN_MINUTES`, `MCP_REFRESH_TOKEN_DAYS`, `MCP_REDIRECT_DOMAINS`.
  - `APP_URL` must be the exact public https URL, because the RFC 9728 `resource` is derived from it.
- Server steps (for E3 deploy doc):
  1. `migrate --force` (4 `oauth_*` tables).
  2. Generate the key pair once and store it in `.env`.
  3. `optimize`.
  4. Check that `/.well-known/*` and `/oauth/*` pass WAF/ModSecurity.
- Design notes: `resources/views/mcp/{layout,authorize,forbidden}.blade.php` are minimal, accessible, Spanish, inline CSS with light and dark themes, no Vite. They sit outside the design-owned folders and are flagged for design (RYR branding).
- Size: about 1,000 authored lines (about 600 of them tests) plus published vendor config and migrations, over the ~400 heuristic. OAuth flow coverage needed the full flow helper and the data-provider cases.

## Open questions (for Jhon)
- "Gerente de TI" is not a role in `config/permissions.php` (only Súper Admin, Líder IA, approvers, Usuario final): today `ai.mcp.use` = Líder IA + Súper Admin. Create a "Gerente de TI" role or give it to a specific user?
