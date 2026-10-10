# Stage D · Conexiones (backend)

Source of truth: `PROMPT_GESTOR_IA_APP.md` §6.4.6 (GitHub), §6.4.7 (Claude: brief + AI suggestions), §7.2 (webhooks), §8. Page contracts (design PR GrupoRyR/GESTOR-TI#20, merged into this chain as `1e39cc7`):
`docs/design/stage-d-contract.md`, `resources/js/types/github.ts`, `types/suggestions.ts`, `types/pages/{admin,ai-knowledge,ai-projects,ai-github,ai-triage}.ts`, `lib/{brief,githubKeys,issue}.ts`.

## Objective
Connect the Gestor IA with GitHub (GitHub App, repo links, one-click issues, signed webhooks processed on the database queue, releases that create product versions and notify requesters, repo docs), expose whitelisted "Brief para Claude" inputs, and add AI suggestions (summary, duplicates, criteria, size, release notes) that IT always confirms.

## Decisions
- 2026-10-10 (orchestrator, per user request "confirma o renombra" applied since Stage C): the route names added by design are confirmed as-is.
- GitHub auth: GitHub App (design assumes `install_url` + private key). JWT RS256 signed with `openssl_sign` (no new dependency); installation token cached < 1 h. All GitHub calls through Laravel `Http` (fakeable). Secrets (`GITHUB_APP_PRIVATE_KEY_PATH`, `GITHUB_WEBHOOK_SECRET`) only in `.env`; props carry booleans.
- GitHub admin routes register outside the `can:settings.manage` Integraciones group, under `can:ai.github.manage` (Líder IA has it, not `settings.manage`). `admin.integrations.github.update` (organization) stays `settings.manage` per type header.
- Key matching mirrors `lib/githubKeys.ts`: `(?<![A-Za-z0-9])gia-(\d+)(?![A-Za-z0-9])`, case-insensitive, over title, body and branch.
- AI suggestions reuse `AiRuntime::run()` (logs `ai_interactions`, enforces the cap). AI input whitelist: title, problem, current process, expected result, app, open-request titles. Never auto-applied.

## Delivery
- Strategy cached: stacked-to-main. Slices `stage-d/NN-*` stacked on `stage-c/06-pending-forms`. RDD off.
- Checks: `php artisan test`, `vendor/bin/pint --test`, `npm run typecheck`, `npm test`, `npm run build`.
- Forecast: ~3,000+ authored lines (6 slices).

## Tasks
- [x] D0 · Merge design PR #20 (`1e39cc7`): no conflicts; typecheck, 476 vitest and build green.
- [x] D1 · GitHub foundation: `github_repositories` (installed repos + polymorphic product/project link: branch, read_docs, receive_events, linked_by/at), `github_webhook_events`, `users.github_login`; `GitHubApp` client (JWT, installation token, repos, contents, issues, create repo); `GiaKeys` matcher; page `Admin/Integrations/GitHub` + `admin.integrations.github{,.update,.events.retry,.disconnect}`; Integraciones card. Route: delegated (writer trigger).
- [ ] D2 · Webhook: `POST /api/github/webhook` (HMAC `X-Hub-Signature-256`, rate limit), store event, queued processor; `issues`/`pull_request` (task → En revisión / Hecho, activity), `workflow_run` (CI), `push` to main (deploy), `release` (product version + included `GIA-…` since previous release); failed-event reprocess. Route: delegated.
- [ ] D3 · Repos, issues and docs: `ai.products.repository.store`, `ai.projects.repository.store`, `ai.projects.repository.create` (private repo with README, CLAUDE.md, docs/), `ai.tasks.issue.store`; props `Ai/Products/Show.github|repo_link`, `Ai/Projects/Show.repo_link|repo_create`, Board `selected_task_github|issue_created`, `Ai/Tasks/Show.github`; `Ai/Docs/Repo` connected. Route: delegated.
- [ ] D4 · Releases: page `Ai/Releases/Show`, `ai.releases.notes.update`, `ai.releases.notify` (bell + Telegram to each requester, confirmed notes only). Route: delegated.
- [ ] D5 · Brief para Claude: whitelisted `brief` inputs + `brief_state` on request/task/project/board, `brief_omitted`; tests that vault, credentials, costs, rates, hours and internal notes never appear. Route: delegated.
- [ ] D6 · AI suggestions: `ai_suggestions`, agents, `ai.requests.suggestions.store`, `ai.suggestions.{accept,discard,regenerate,cancel}`, `ai.requests.compare`, `ai.requests.criteria.update`, `size_suggestion_id` on evaluation, `ai_availability`/`ai_usage`/`merge_into`; release-notes suggestion. Route: delegated.
- [ ] D7 · Close: full checks, deploy doc (GitHub App setup, env keys), PRs.

## Progress / evidence

### D1 · GitHub foundation — done (commit recorded in the Stage D PR list)
- Route: delegated (writer trigger: migration, 2 models, 2 factories, client, sync, key matcher, job, presenter, controller, routes, config; 4 test files).
- Test-first: 31 new tests (`tests/Unit/GitHub/GiaKeysTest`, `tests/Feature/GitHub/{GitHubAppTest,RepositorySyncTest}`, `tests/Feature/Admin/GitHubIntegrationAdminTest`) written first and observed RED
  (30 of 31 failing: classes, tables and routes missing), then GREEN. On Windows PHP `openssl_pkey_new` needs a config file: the test helper `GeneratesAppKey` passes a minimal one.
- Checks: `php artisan test --filter='GitHub|GiaKeys|RepositorySync|IntegrationsAdmin'` 50/50; full suite 973/973 passed (10205 assertions); `vendor/bin/pint --test` passed; `npm run typecheck` passed;
  `route:list --name=integrations.github` 4 routes.
- Size: ~1060 authored code lines + ~775 test lines, above the ~400 heuristic: schema, client, sync and the admin page are one foundation the later slices consume together.

Decisions:
- Schema (`2026_10_26_000001_create_github_tables`): `github_repositories` (github_id and full_name unique, name, default_branch, private, html_url, language, pushed_at, branches json,
  installation_id, last_synced_at; link `linkable` morph to `AiProduct`/`AiProject` stored as class names (no morph map, to keep existing morphs intact), branch, read_docs, receive_events,
  linked_by_id, linked_at; unique `(linkable_type, linkable_id)` = one repo per product/project). `github_webhook_events` (delivery_id unique, event, action, repository, payload, status
  `pending|processed|failed|ignored`, error (Spanish), error_code, request_keys json, received_at, processed_at, attempts). `users.github_login` (fillable).
- `App\Services\GitHub\GitHubApp`: JWT RS256 via `openssl_sign` (iat −60 s, 9 min), installation token cached encrypted (`Crypt`) until 5 min before `expires_at`, max 55 min; methods
  `organizationInstallationId`, `installationRepositories` (all pages), `branches`, `contents`, `fileContents`, `createIssue`, `createRepository` (private, then one Contents PUT per file).
  Errors are `GitHubException` with `reason` (`not_configured`, `not_connected`, `github_<status>`, `github_connection`) and messages without key/JWT/token. Existing env key kept:
  `GITHUB_PRIVATE_KEY_PATH` (not `GITHUB_APP_PRIVATE_KEY_PATH`); added `services.github.app_name` (`GITHUB_APP_NAME`) and `api_url`.
- Connected = app configured (app id + readable key) and setting `github.installation_id` set. `App\Services\GitHub\RepositorySync::sync()` resolves the installation from
  `github.organization`, stores it and upserts repos by GitHub id (renames keep links; lost unlinked repos are deleted, linked ones kept). Nothing calls it yet: D2 wires it to the
  `installation`/`installation_repositories` webhooks (or a command).
- `App\Domain\GitHub\GiaKeys::extract|normalize` mirror `lib/githubKeys.ts` (same cases in `GiaKeysTest`).
- Page (`App\Support\Integrations\GitHubIntegration::toPageProps`, `Admin\GitHubIntegrationController`): repos only while connected, by full name; stats = current month (Bogotá)
  received (all statuses) / processed / failed; failed events newest first; `fix_product_slug` = product whose `repository` equals the event repo (case-insensitive) for `repo_not_linked`;
  webhook URL = `APP_URL` + `/api/github/webhook`; `kind` `token` only with `GITHUB_TOKEN` and no app id. The Index card uses the same class (`status` connected/disconnected, repos count).
- Routes: `admin.integrations.github` GET, `.events.retry` POST `{event}`, `.disconnect` DELETE under `can:ai.github.manage`, outside the `settings.manage` group; `.update` stays there.
  Retry: only `failed` events → `pending`, error cleared, `ProcessGitHubEvent` (D1 stub) dispatched, audited `github_event_retried`. Disconnect: forgets the installation and its cached
  token, keeps events and links, audited `github_disconnected` (sensitive event).

Notes for design:
- `connection.permissions` adds `{ scope: 'administration', access: 'write' }` and `contents` is `write` (needed to create a repo with README/CLAUDE.md/docs). `lib/githubAdmin.ts`
  has no label for `administration` (shows the raw scope): add one ("administración").
- Retry of a non-failed event flashes error "Este evento ya no está fallido."; success flashes "Evento enviado a reprocesar." / "GitHub quedó desconectado. Los eventos y los vínculos se conservan."

## Open questions (for Jhon)
- GitHub organization name, App registration (app id, private key, webhook secret go to `.env`).
- `github_login` for each IT member (issue assignees).
