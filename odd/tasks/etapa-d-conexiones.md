# Stage D · Conexiones (backend)

Source of truth: `PROMPT_GESTOR_IA_APP.md` §6.4.6 (GitHub), §6.4.7 (Claude: brief + AI suggestions), §7.2 (webhooks), §8. Page contracts (design PR GrupoRyR/GESTOR-TI#20, merged into this chain as `1e39cc7`):
`docs/design/stage-d-contract.md`, `resources/js/types/github.ts`, `types/suggestions.ts`, `types/pages/{admin,ai-knowledge,ai-projects,ai-github,ai-triage}.ts`, `lib/{brief,githubKeys,issue}.ts`.

## Objective
Connect the Gestor IA with GitHub (GitHub App, repo links, one-click issues, signed webhooks processed on the database queue, releases that create product versions and notify requesters, repo docs), expose whitelisted "Brief para Claude" inputs, and add AI suggestions (summary, duplicates, criteria, size, release notes) that IT always confirms.

## Decisions
- 2026-10-10 (orchestrator, per user request "confirma o renombra" applied since Stage C): the route names added by design are confirmed as-is.
- GitHub auth: GitHub App (design assumes `install_url` + private key). JWT RS256 signed with `openssl_sign` (no new dependency); installation token cached < 1 h. All GitHub calls through Laravel `Http` (fakeable). Secrets (`GITHUB_PRIVATE_KEY_PATH`, `GITHUB_WEBHOOK_SECRET`) only in `.env`; props carry booleans.
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
- [x] D2 · Webhook: `POST /api/github/webhook` (HMAC `X-Hub-Signature-256`, rate limit), store event, queued processor; `issues`/`pull_request` (task → En revisión / Hecho, activity), `workflow_run` (CI), `push` to main (deploy), `release` (product version + included `GIA-…` since previous release); failed-event reprocess. Route: delegated.
- [ ] D3 · Repos, issues and docs: `ai.products.repository.store`, `ai.projects.repository.store`, `ai.projects.repository.create` (private repo with README, CLAUDE.md, docs/), `ai.tasks.issue.store`; props `Ai/Products/Show.github|repo_link`, `Ai/Projects/Show.repo_link|repo_create`, Board `selected_task_github|issue_created`, `Ai/Tasks/Show.github`; `Ai/Docs/Repo` connected. Route: delegated.
- [ ] D4 · Releases: page `Ai/Releases/Show`, `ai.releases.notes.update`, `ai.releases.notify` (bell + Telegram to each requester, confirmed notes only). Route: delegated.
- [ ] D5 · Brief para Claude: whitelisted `brief` inputs + `brief_state` on request/task/project/board, `brief_omitted`; tests that vault, credentials, costs, rates, hours and internal notes never appear. Route: delegated.
- [ ] D6 · AI suggestions: `ai_suggestions`, agents, `ai.requests.suggestions.store`, `ai.suggestions.{accept,discard,regenerate,cancel}`, `ai.requests.compare`, `ai.requests.criteria.update`, `size_suggestion_id` on evaluation, `ai_availability`/`ai_usage`/`merge_into`; release-notes suggestion. Route: delegated.
- [ ] D7 · Close: full checks, deploy doc (GitHub App setup, env keys), PRs.

## Progress / evidence

### D1 · GitHub foundation — done (`8337b13`)
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

### D2 · Webhook endpoint and processing — done
- Route: delegated (writer trigger: controller, job, processor, 6 handlers, linker, migration, models, command; 2 test files + 6 fixtures).
- Test-first: 29 new tests (`tests/Feature/GitHub/{WebhookEndpointTest,ProcessGitHubEventTest}`, fixtures `tests/Fixtures/github/*.json`) written first and observed RED
  (28 of 29 failing: route 404, events stayed `pending`, `github:sync` missing), then GREEN.
- Checks: `php artisan test --filter=GitHub` 60/60; full suite 1002/1002 passed (10353 assertions); `vendor/bin/pint --test` passed.
- Size: ~1,050 authored code lines + ~650 test/fixture lines, above the ~400 heuristic: endpoint, processor and the five event handlers share one storage contract the
  later slices (D3 product/task props, D4 releases) read together.

Schema (`2026_10_27_000001_add_github_event_columns`):
- `github_repositories.ci` json = latest `CiRun` per branch (keyed by branch; the product page reads `trackedBranch()` = link branch or default), `last_deploy` json
  (`Deploy` without `provider`: branch, commit_sha, deployed_at, url; provider comes from the product hosting), `recent_commits` json (`CommitSummary` with
  `author_name`/`author_login`, newest first, max 20).
- `ai_product_versions`: `tag`, `github_release_id` (unique, redelivery updates the same version), `github_repository_id`, `published_at` (exact instant; `released_at`
  stays the Bogotá date), `author_login`, `technical_notes` (release body), `user_notes` and `notified_at` (null; D4 fills them). `ai_product_version_request.found_in_pr`.

Classes and routes:
- `POST /api/github/webhook` → `App\Http\Controllers\GitHub\WebhookController` (`github.webhook`, `throttle:github-webhook` 300/min per IP; API group, no CSRF).
  403 without `GITHUB_WEBHOOK_SECRET`; 401 unless `X-Hub-Signature-256` = `sha256=` HMAC of the raw body (`hash_equals`); 400 without event/delivery headers or JSON.
  `ping` → 200, not stored; events outside `GitHubEventProcessor::HANDLED_EVENTS` (5 subscribed + `installation`, `installation_repositories`) → 200 `ignored`, not stored.
  Stored once per `X-GitHub-Delivery` (redelivery → 200 `duplicate`, no dispatch; unique-violation race handled) → 202 and `ProcessGitHubEvent` dispatched.
- `App\Jobs\ProcessGitHubEvent` (tries 3, backoff 60/300 s): skips processed/ignored events; unexpected errors set `failed` + `error_code` (`github_<status>` from
  `GitHubException`, else `processing_error`) and rethrow so the queue retries. Admin "Reprocesar" (D1) re-dispatches it (covered end to end).
- `App\Domain\GitHub\Events\GitHubEventProcessor`: attempts+1, resolves the repo by GitHub id then full name; unknown/unlinked → `failed` `repo_not_linked`
  ("El repositorio no está vinculado a un producto ni a un proyecto."); linked with `receive_events` off → `ignored`. Runs repo handlers in one DB transaction;
  `EventFailure` (data problems) fails without retry. Stores `request_keys`, `processed_at`.
- Handlers (`app/Domain/GitHub/Events`): `IssuesHandler` (keys; `github_issue_state` of tasks with the same issue URL or number in a project of the repo),
  `PullRequestHandler` (links `github_pr_number|url|state` open/merged/closed; opened/reopened/ready_for_review non-draft → "En revisión", merged → "Hecho", never
  back out of "Hecho"; moves through `MoveTask`; one `github` activity per project "PR #41 abierto|fusionado|cerrado sin fusionar|listo para revisión|reabierto: título"),
  `WorkflowRunHandler` (`CiStatus`: not completed = running, success = passing, failure/timed_out/startup_failure/action_required = failing, else none; older run never
  overwrites a newer one per branch; tasks by PR number in the run or GIA key + task key in the branch get `github_ci`), `PushHandler` (only `refs/heads/{trackedBranch}`,
  deletions ignored; keys from commit messages), `ReleaseHandler`, `InstallationHandler` (account must equal `github.organization`, saved from the payload when empty;
  `deleted` forgets the installation and its token; others run `RepositorySync`).
- `WorkLinker`: projects in scope = projects of the requests with the keys + projects of the repo (linked project, or projects of the linked product). A task matches by
  its stored PR URL, PR number (repo projects), task key `T3` in title/branch (request projects, `(?<![A-Za-z0-9])t(\d+)(?![A-Za-z0-9])`), or the issue the PR closes
  ("Closes|Fixes|Resolves #12"). Actor = user with `github_login` of the PR author (merger on merge), else null.
- `MoveTask::handle` accepts a null actor (system moves); behaviour unchanged for people.
- `php artisan github:sync` (`GitHubSyncCommand`) runs `RepositorySync`; Spanish errors for not configured / not connected.

Decisions:
- Release requests come from the stored merged `pull_request` events of the repo between the previous release of the same repo (`published_at`) and this one, plus keys
  in the release name/body (found_in_pr null). No compare API call: works with `Http::fake`, while GitHub is down and on reprocess; limit: commits pushed straight to
  main without a PR only count when the key is written in the release notes.
- A release on a project link uses the project's product; none → `failed` `no_product`. Drafts, prereleases and actions other than `published` → ignored.
  The version becomes the product `current_version` when no newer GitHub release exists. Requests are NOT moved to "En producción" nor notified in D2
  (TransitionRequest notifies the requester): D4 does both after IT confirms `user_notes`.
- Version name = tag without a leading `v` before a digit (`v1.9.0` → `1.9.0`, `tag` keeps `v1.9.0`); an existing manual version with that name is enriched, not duplicated.
- Linked repo with `receive_events` off → `ignored` (not `failed`), so turning events off does not fill the failed list.
- Timestamps from GitHub are converted to the app zone before saving (Eloquent stores wall time as given).

Notes for design:
- A failed `installation`/`installation_repositories` event (e.g. GitHub 500) appears in the failed list with `type` outside `WebhookEventType`; consider adding both
  to the union or label them "instalación".
- `Release.author` can be built from `author_login` (+ `users.github_login`); `CiRun.summary` is the workflow name (no test counts in the webhook).

Server steps (D7 deploy doc):
- GitHub App → Webhook URL `https://{dominio}/api/github/webhook`, content type `application/json`, secret = `GITHUB_WEBHOOK_SECRET` in `.env`.
- Subscribe to events: Issues, Pull request, Release, Workflow runs, Push (installation events are always sent to the app). Permissions: Actions read (workflow_run).
- After installing: `php artisan github:sync` once; the cron `schedule:run` + database queue processes the events.

## Open questions (for Jhon)
- GitHub organization name, App registration (app id, private key, webhook secret go to `.env`).
- `github_login` for each IT member (issue assignees).
