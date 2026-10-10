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
- [x] E2 · Tools:
  - `buscar_solicitudes`, `ver_solicitud` (with docs and criteria), `crear_tarea`, `actualizar_tarea`, `agregar_nota_avance`, `adjuntar_documento`.
  - Company scope, policies, audit and whitelist leak tests.
  - Route: delegated.
- [x] E2b · Security review fixes (independent read-only review of `fc9fc3b`: no blocker/high):
  - medium: require PKCE `S256` (league accepts `plain` and defaults to it when the method is missing);
  - low: show the redirect host on the consent screen;
  - low: prune DCR clients that never received a token;
  - low: `mcp:revoke` command and consent wording matching it;
  - follow-up from E2: the task controller uses `CreateTask`/`UpdateTask`/`AddProgressNote` (one copy of the rules).
  - Route: delegated.
- [ ] E3 · Close: deploy doc (Passport keys, WAF/ModSecurity for `/.well-known` and `/oauth/*`, adding the connector in claude.ai and Claude Code, hosting test checklist), full checks, PRs.

## Progress / evidence

### E1 · MCP + OAuth foundation — done (`fc9fc3b`)
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

### E2 · Tools — done (`8ab68f8`)
- Route: delegated direct (writer trigger: 2+ non-trivial files). Only `GestorIaServer` registration touched E1 code; OAuth files untouched.
- Tools (Spanish names, inputs and answers; JSON text responses; errors are tool errors `isError: true` in Spanish, never JSON-RPC errors or 500s):
  - `quien_soy`: kept.
  - `buscar_solicitudes` (read-only, idempotent): `texto` (every word in key, title or problem, plain LIKE), `estado` (RequestStatus value), `producto`, `area`, `empresa` (name/slug, partial), `limite` (1–25, default 10). Returns `total` and `solicitudes[]` with `clave`, `titulo`, `estado`, `estado_clave`, `producto`, `empresa`, `area`, `prioridad` (final, else suggested), `actualizada`, `url`. Only requests the user may open (AiRequestPolicy::view), newest change first.
  - `ver_solicitud` (read-only, idempotent): `clave` GIA-n. Returns problem, current process, expected result, criteria (text, result), evaluation (size, urgency, risk, suggested and final priority), requester, product, linked projects the user may view (AiProjectPolicy::view) with repository and tasks (column, assignees, GitHub issue/PR URLs, task URL), public comments, documents of those projects and the request's product the user may view (AiDocumentPolicy::view; id, title, template, version, URL, Markdown cut at 20 000 chars). `notas_internas` only with AiRequestPolicy::viewInternal; `costos` (monthly impact, latest estimate without hourly rates via ItDetailPresenter/TriagePresenter) only with `ai.costs.view`. Unknown, out-of-scope and forbidden keys all answer "No encontré la solicitud GIA-n.".
  - `crear_tarea`: `proyecto`, `titulo`, optional `descripcion`, `columna` (Board key, default `todo`), `responsable` (email, BoardPeople), `horas_estimadas` (0.5–999), `solicitud` (must be one of the project's requests). Returns `proyecto`, `tarea`, `titulo`, `columna`, `responsables`, `url`.
  - `actualizar_tarea`: `proyecto`, `tarea`, any of `titulo`, `descripcion`, `columna` (MoveTask), `responsable` (empty clears). Same answer shape.
  - `agregar_nota_avance`: `proyecto`, optional `tarea`, `nota`. With a task: task comment (≤ 5000) plus "comentó en T…" in the feed, as the board. Without: project activity feed `comment` "nota de avance: …" (≤ 200 so it fits the 255-char feed).
  - `adjuntar_documento`: `documento_id` + `contenido` (+ `resumen`) saves and publishes the next version; otherwise `proyecto` or `producto` (slug) + `titulo` (+ `plantilla`, default `blank`) creates version 1. Markdown/text only, no binary uploads; limit `gestor.mcp.document_max_chars` (default 100 000, read with a code default; no config file change).
- Annotations: reads `readOnlyHint` + `idempotentHint`; writes `destructiveHint: false`. Server instructions list every tool and the "no credentials" rule.
- Reused: AiRequestPolicy, AiProjectPolicy (`manage` for writes), AiDocumentPolicy, CompanyScope, BoardPeople, Board, MoveTask (lifecycle and request transitions/notifications), App\Actions\Ai\Documents (SecretGuard, revisions), ItDetailPresenter::estimate, Money, RepoActivity::repositoryOf, BriefText + SecretGuard (SafeText).
- New shared actions (app/Actions/Ai): `CreateTask` and `UpdateTask` mirror `TaskController::store|update` exactly; `AddProgressNote` (task comment or feed entry + SecretGuard). Follow-up for the app owner: `App\Http\Controllers\AI\TaskController` store/update/comment should call these actions (outside this task's edit surface, so the board rules exist in both places until then).
- Company context: the MCP route has no session, so `CurrentCompany` resolves a `companies.view_all` user to "Todas" and anyone else to their own company. That is the "all companies the user may access" default. Tested with a connector role without `companies.view_all`.
- Visibility and leaks (SafeText): every free text is returned whole or replaced by "[Texto omitido…]". Credentials are always omitted (SecretGuard). Viewers without `ai.costs.view` or without internal notes also get BriefText rules (money amounts, quotes of internal notes). Never read: vault items, rates, time entries, salaries, task comments.
- Writes: AiProjectPolicy::manage / AiDocumentPolicy create|update through `Gate::forUser`; SecretGuard refuses credentials in titles, descriptions, notes and documents; each write logs an `ai` activity (`mcp_task_created`, `mcp_task_updated`, `mcp_progress_note`, `mcp_document_created`, `mcp_document_updated`, property `tool`) with origin `api` stamped by EnsureMcpAccess. Notifications are the app's (MoveTask → TransitionRequest); the board sends none for create, edit or comments.
- Test-first: RED 69 tests, 44 passed, 25 failed (new tools missing). GREEN 70/70 in `tests/Feature/Mcp`, with 1 more real-OAuth-token test added after GREEN. Files: `McpToolsTestCase`, `RequestToolsTest`, `TaskToolsTest`, `NoteAndDocumentToolsTest`. `GestorIaServerTest` no longer expects `quien_soy` to be the only tool.
- Checks:
  - `php artisan test --filter=Mcp`: 70 passed.
  - `php artisan test`: 1138 passed (baseline 1112, plus 26).
  - `vendor/bin/pint --test`: passed.
- Size: about 1,250 authored app lines plus 700 test lines, over the ~400 heuristic: six tools with their visibility rules and leak tests belong together.

### E2b · Security review fixes — done
- Route: delegated direct (writer trigger: 2+ non-trivial files).
- PKCE (medium): `LoopbackAwareAuthCodeGrant::validateAuthorizationRequest` runs the parent first (unknown client or redirect keep their errors), then refuses with `invalid_request` when there is no `code_challenge` (public and confidential clients) or when `code_challenge_method` is not exactly `S256` (the parent stores `plain` when the method is missing). The hint is Spanish/English. No auth code can be bound to a `plain` challenge, so league's registered PlainVerifier is unreachable at the token endpoint. Confirmed that Claude always sends `code_challenge_method=S256` on every authorization request (https://claude.com/docs/connectors/building/authentication, "PKCE and requested scopes"); `laravel/mcp` metadata already advertises only `S256`.
- Consent screen (low): `App\Support\Mcp\ConsentRedirect` derives the redirect host from `redirect_uri` (or the client's only registered URI). The screen shows "Volverá a: claude.ai", or for loopback "Volverá a: localhost (una aplicación en tu equipo, como Claude Code)" plus "Autoriza solo si acabas de conectar Claude Code en este equipo." (the same docs page says to show the host and warn on loopback). Wording changed to "Para retirar el acceso, pide a TI que lo revoque; también se corta si pierdes el permiso."
- DCR growth (low): `php artisan mcp:prune-clients` deletes clients without owner (DCR) created more than 24 h ago with no tokens and no auth codes. Scheduled daily at 03:15 Bogotá (`routes/console.php`, `withoutOverlapping`).
- Revoke (low): `php artisan mcp:revoke {email} {--client=}` revokes the user's access tokens, their refresh tokens and pending auth codes (all clients, or one `client_id`). Passport serves only the connector, so every OAuth token of the user is an MCP token. Revoked tokens also stop Passport's auto-approval, so Claude asks for consent again. Audited `auth` log (sensitive), event `mcp_access_revoked`, subject the user, properties `source: console`, `client_id`, `tokens`, `auth_codes` (origin stamped `system` by AuditOrigin). Unknown email: error, no audit.
- Follow-up from E2: `TaskController::store|update|comment` now call `CreateTask`, `UpdateTask` and `AddProgressNote::commentOnTask` (new public method holding the comment + "comentó en …" write; `handle` keeps its MCP guards: empty, length, SecretGuard). The web comment keeps its own validation and does not gain SecretGuard, so board behavior is unchanged. `due_at` is mapped to the action's `due_date`.
- Test-first: `tests/Feature/Mcp/OAuthHardeningTest.php`, RED 11 tests, 1 passed (S256 flow), 6 failed, 4 errors (commands missing). GREEN 11/11. Existing task tests unchanged and green.
- Checks:
  - `php artisan test --filter='Mcp|Task'`: 107 passed.
  - `php artisan test`: 1149 passed (baseline 1138, plus 11).
  - `vendor/bin/pint --test`: passed.
  - `php artisan schedule:list`: `15 3 * * * php artisan mcp:prune-clients`.
- For E3 deploy doc: mention `mcp:revoke` for IT, and that the consent view changed (still flagged for design).

## Open questions (for Jhon)
- "Gerente de TI" is not a role in `config/permissions.php` (only Súper Admin, Líder IA, approvers, Usuario final): today `ai.mcp.use` = Líder IA + Súper Admin. Create a "Gerente de TI" role or give it to a specific user?
