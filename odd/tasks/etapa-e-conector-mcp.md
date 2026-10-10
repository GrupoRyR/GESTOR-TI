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

- 2026-10-10 (user, after design PR GrupoRyR/GESTOR-TI#34): "Ambos". The MCP server accepts the design's personal tokens (Claude Code, scripts) AND the E1 OAuth (claude.ai web/desktop) on one endpoint, `POST /mcp`. User rules:
  - each person manages only their own tokens (`ai.mcp.use`);
  - the value travels only in `created_token`, once, with `encryptHistory` + `no-store`, hashed at rest, never in URLs or logs;
  - 401 for an expired or revoked token; 403 when a read-only token calls a write tool (logged as rejected);
  - every call is audited;
  - NEVER vault, credentials, rates, costs or salaries (costs removed from `ver_solicitud` even with `ai.costs.view`).
- Scopes: `read` = `buscar_solicitudes`, `ver_solicitud`; `read_write` adds `crear_tarea`, `actualizar_tarea`, `agregar_nota` (renamed from `agregar_nota_avance`), `adjuntar_documento`. OAuth tokens (scope `mcp:use`) act as `read_write`.

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
- [x] E2c · Merge design PR #34 (`787f5f6`): no conflicts; typecheck, 496 vitest and build green.
- [x] E3 · Personal tokens + `Profile/Mcp`:
  - `mcp_tokens` (hashed, scope, expiry, last used/client, revoked) and `mcp_calls` audit;
  - page + `profile.mcp.tokens.{store,destroy}`, `profile.mcp.test`;
  - `/mcp` accepting personal tokens or OAuth, 401/403 rules;
  - `agregar_nota` rename, costs removed, per-call audit.
  - Route: delegated.
- [ ] E4 · Close: deploy doc (Passport keys, WAF/ModSecurity for `/.well-known` and `/oauth/*`, adding the connector in claude.ai and Claude Code, hosting test checklist), full checks, PRs.

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

### E2b · Security review fixes — done (`c273ceb`)
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

### E3 · Personal tokens + Profile/Mcp — done (`874b7bc`)
- Route: delegated direct (writer trigger: 2+ non-trivial files).
- Schema (`2026_10_31_00000{1,2}`):
  - `mcp_tokens`: user, `name` (60), `scope` (`read` | `read_write`), `token_hash` (SHA-256, unique), `last_four`, `expires_at` (date: last calendar day in Bogotá the token works), `last_used_at`, `last_client`, `revoked_at`, `revoked_by`. Rows are never deleted. Name unique among the user's active tokens (not revoked, not past the date), case-insensitive, checked in `StoreMcpTokenRequest`.
  - `mcp_calls`: user, `mcp_token_id` (nullable), `oauth_client_id` (nullable), `tool`, `target` ("GIA-139", "P-3 · T5", "“cartera”", "documento 12"), `result` (`ok` | `error` | `rejected` | `unauthorized`), `message` (160), `created_at`. Never the token value nor what the Gestor answered.
  - Retention: `gestor.mcp.calls_retention_days` (180, `MCP_CALLS_RETENTION_DAYS`), `mcp:prune-calls` daily at 03:20 Bogotá. The page receives it as `activity.retention` ("180 días"), so the `[TIEMPO DE RETENCIÓN]` placeholder is filled by the prop.
- Token format: `gti_mcp_` + 40 url-safe characters from `random_bytes(32)` (base64url). Lookup by the SHA-256 (unique index) plus `hash_equals`. `McpToken::issue()` is the only place the value exists.
- Routes (`web` + `auth`, controller `App\Http\Controllers\Profile\McpConnectorController`):
  - `profile.mcp` GET `/profile/mcp` (`?token={id}|all`): `selected_token` and `activity` are closures (partial reload `only: ['activity','selected_token']`). Another person's or a malformed id: 404.
  - `profile.mcp.tokens.store` POST: flashes `{id, value}` encrypted (`Crypt`) and redirects to `profile.mcp`; the next GET decrypts it, checks the hash and sends `created_token` once.
  - `profile.mcp.tokens.destroy` DELETE `{token}`: revokes (`revoked_at`, `revoked_by`), idempotent, keeps the calls. Another person's token: 404.
  - `profile.mcp.test` POST (`throttle:6,1`): stores `connection_test` in the session and redirects back (keeps `?token=` from the referer).
  - Every response: `Inertia::encryptHistory()` on the GET and `Cache-Control: no-store, no-cache, private, max-age=0` on all four.
  - Without `ai.mcp.use`: GET renders `tokens: []`, `activity`, `selected_token`, `connection_test`, `created_token` null, `mcp_holders` (active users holding the permission, by name). The other routes answer 403.
  - `server`: `{ url: APP_URL + '/mcp', active: gestor.mcp.enabled }`.
  - Audit (`auth` log, sensitive): `mcp_personal_token_created` (name, scope, expires_at) and `mcp_personal_token_revoked`. No value, no last four.
- "Probar conexión" (`App\Support\Mcp\ConnectionTester`): POSTs an unauthenticated `initialize` to the server's own `/mcp` (10 s timeout, `gestor.mcp.connection_test_timeout`). No token is created or sent. `ok` = HTTP 401 with a `Bearer` challenge (reachable, Laravel answering, asking for auth), with `ms`. Failures: `mcp_not_found` (404, also when `MCP_ENABLED=false`), `mcp_http_{status}`, `mcp_timeout` (cURL 28), `mcp_tls` (cURL 35/51/58/60, SSL), `mcp_unreachable`.
- Unified endpoint `POST /mcp` (moved from `/mcp/gestor-ia`, route name `mcp.gestor-ia` kept). Middleware order:
  1. `AuthenticateMcp`: `MCP_ENABLED=false` → 404. A `gti_mcp_` bearer → personal token: unknown → 401; expired or revoked → 401 and an `unauthorized` call row ("token vencido" / "token revocado"); valid → `last_used_at`, and on `initialize` `last_client` from `clientInfo.name`. Any other bearer → Passport `api` guard. The caller is kept as `App\Support\Mcp\McpContext` (user, token or OAuth client). laravel/mcp adds the `WWW-Authenticate` challenge to every 401.
  2. `throttle:mcp`: now per personal token, else per OAuth user (`McpContext::rateKey`). Before E3 it ran before auth and fell back to the IP.
  3. `AuditMcpCalls`: one `mcp_calls` row per `tools/call`, also for refusals below. Writes keep their `ai` activity entries with origin `api`.
  4. `EnforceMcpScope`: a `read` token calling `crear_tarea`, `actualizar_tarea`, `agregar_nota` or `adjuntar_documento` → HTTP 403 JSON-RPC `-32001` "Este token es de solo lectura: no puede usar …", logged `rejected` with reason "token de solo lectura" (the page shows "Rechazada · token de solo lectura"). `tools/list` also hides them (`WritesGestor::shouldRegister`), but the 403 does not depend on that.
  5. `EnsureMcpAccess`: OAuth tokens still need `mcp:use`; every caller must be active and hold `ai.mcp.use`, else 403 (logged `rejected`).
  - OAuth = `read_write`. Protected-resource metadata: `resource` = APP_URL + `/mcp` (`/.well-known/oauth-protected-resource/mcp`).
- Tools: `agregar_nota_avance` renamed `agregar_nota` (class `AgregarNota`, server instructions). `ver_solicitud` no longer returns `costos` for anyone, and `SafeText` now omits any free text with a money amount for every viewer (user rule: never costs). Tool descriptions and instructions say so.
- Page activity: calls of the user's personal tokens with the six `McpToolKey` tools (newest first, 100). `quien_soy` and OAuth calls (no token to show) are audited but not listed. `unauthorized` reads as `rejected` with its reason. `rejected_elsewhere`: rejected calls of the user's other tokens in the last 7 days. `summary` = target, plus "· N resultados" for searches.
- Test-first: RED 112 tests, 42 passed, 45 failed, 25 errors (routes, models and middleware missing; `/mcp` 404). One exception: the `ver_solicitud` costs change and the SafeText money rule were written before that RED run, so their RED was not observed. GREEN 117/117 in `--filter='Mcp|Profile'`. New: `tests/Feature/Mcp/PersonalTokenServerTest.php` (16), `tests/Feature/Profile/McpConnectorPageTest.php` (16 including 5 data-provider cases). Updated: `McpTestCase` (`SERVER_PATH = '/mcp'`, `personalToken()`), `OAuthDiscoveryTest`, `RequestToolsTest`, `NoteAndDocumentToolsTest`.
- Checks:
  - `php artisan test --filter='Mcp|Profile'`: 117 passed.
  - `php artisan test`: 1181 passed (baseline 1149, plus 32).
  - `vendor/bin/pint --test`: passed.
  - `npm run typecheck`: passed.
  - `php artisan route:list --path=mcp`: 7 routes (`GET|DELETE /mcp` 405, `POST /mcp`, `profile.mcp`, `profile.mcp.test`, `profile.mcp.tokens.store`, `profile.mcp.tokens.destroy`).
  - `route:cache` works. `schedule:list` shows `20 3 * * * mcp:prune-calls`.
- Size: about 1,300 authored app lines (the `AgregarNota` rename counts twice) plus 630 test lines, over the ~400 heuristic. Tokens, the page, the endpoint auth and the audit all need each other to be testable end to end.
- Env keys (server, optional): `MCP_ENABLED` (default true), `MCP_CALLS_RETENTION_DAYS` (default 180). Migrate: `mcp_tokens`, `mcp_calls`.
- Design notes:
  - `activity.retention` is sent ("180 días"), so the placeholder goes away.
  - `last_client` is the raw MCP `clientInfo.name` (e.g. `claude-code`, `claude-ai`), not a friendly label like "Navegador y escritorio". Design may map known names.
  - Calls through claude.ai OAuth are audited but not shown on the page, since `McpCall.token` is required. If design wants them, the type needs an OAuth variant.
  - Revoking from the activity panel keeps `?token=` (from the referer), so the panel stays open.
- Follow-ups outside the E3 surface: the `App\Actions\Ai\AddProgressNote` docblock still names `agregar_nota_avance`. There is no per-IP throttle before authentication on `/mcp`: unauthenticated requests cost one indexed lookup or one JWT check.

## Open questions (for Jhon)
- "Gerente de TI" is not a role in `config/permissions.php` (only Súper Admin, Líder IA, approvers, Usuario final): today `ai.mcp.use` = Líder IA + Súper Admin. Create a "Gerente de TI" role or give it to a specific user?
