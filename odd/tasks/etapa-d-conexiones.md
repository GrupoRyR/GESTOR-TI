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
- [x] D3 · Repos, issues and docs: `ai.products.repository.store`, `ai.projects.repository.store`, `ai.projects.repository.create` (private repo with README, CLAUDE.md, docs/), `ai.tasks.issue.store`; props `Ai/Products/Show.github|repo_link`, `Ai/Projects/Show.repo_link|repo_create`, Board `selected_task_github|issue_created`, `Ai/Tasks/Show.github`; `Ai/Docs/Repo` connected. Route: delegated.
- [x] D4 · Releases: page `Ai/Releases/Show`, `ai.releases.notes.update`, `ai.releases.notify` (bell + Telegram to each requester, confirmed notes only). Route: delegated.
- [x] D5 · Brief para Claude: whitelisted `brief` inputs + `brief_state` on request/task/project/board, `brief_omitted`; tests that vault, credentials, costs, rates, hours and internal notes never appear. Route: delegated.
- [x] D6 · AI suggestions: `ai_suggestions`, agents, `ai.requests.suggestions.store`, `ai.suggestions.{accept,discard,regenerate,cancel}`, `ai.requests.compare`, `ai.requests.criteria.update`, `size_suggestion_id` on evaluation, `ai_availability`/`ai_usage`/`merge_into`; release-notes suggestion. Route: delegated.
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

### D2 · Webhook endpoint and processing — done (`5bcf2c1`)
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

### D3 · Repos, issues, repo creation, GitHub props, repo docs — done (`c1a01d5`)
- Route: delegated (writer trigger: 3 actions, 6 domain classes, presenter, controller, 3 form requests, 5 controllers wired, migration; 5 test files + 5 updated).
- Test-first: 20 new tests (`tests/Feature/GitHub/{RepositoryLinkTest,RepositoryCreateTest,TaskGitHubTest,ProductGitHubTest}`, 2 new in
  `tests/Feature/Ai/Knowledge/DocumentsTest`) plus prop-key updates in `ProductPagesTest`, `ProductIncidentsTest`, `ProjectPagesTest`. RED observed by
  setting the implementation aside: 24 of 55 tests in the touched files failing (routes missing, props absent); then GREEN.
- Checks: `php artisan test --filter='GitHub|Repository|Issue|Docs|Board|Product|Project'` 227/227; full suite 1022/1022 passed (10900 assertions);
  `vendor/bin/pint --test` passed; `npm run typecheck` passed.
- Size: ~2,050 authored code lines (with docblocks) + ~1,120 test lines, above the ~400 heuristic: four routes and five page props read one shared
  stored-event model (RepoActivity) and were kept in one slice so the props stay consistent.

Routes (`can:ai.github.manage`, App\Http\Controllers\AI\GitHubController):
- `ai.products.repository.store` POST `/ai/products/{product}/repository` (also `can:ai.manage` + AiProductPolicy::view) and
  `ai.projects.repository.store` POST `/ai/projects/{project}/repository` (AiProjectPolicy::manage), body `RepoLinkPayload`, errors `repo`, `branch` →
  `App\Actions\Ai\LinkRepository`. Back to the show page with flash "Repositorio vinculado." (+ "N evento(s) pendiente(s) se está(n) reprocesando.").
- `ai.projects.repository.create` POST `/ai/projects/{project}/repository/create`, body `{name, claude_md}`, errors `name`, `claude_md` →
  `App\Actions\Ai\CreateProjectRepository`; flash "Se creó el repositorio privado {org}/{name} y quedó vinculado al proyecto.".
- `ai.tasks.issue.store` POST `/ai/projects/{project}/tasks/{task}/issue` (scoped bindings), body `IssuePayload`, errors `title`, `body`,
  `assignee_id` → `App\Actions\Ai\CreateTaskIssue`. From the board (previous URL ends in `/board`) it flashes `issue_created`; otherwise flash
  success "Se creó el issue #N en GitHub.".

Props:
- `Ai/Products/Show`: `github` (`App\Domain\GitHub\ProductActivity`, null without a linked repo; GitHub data to every `ai.manage` viewer) and
  `repo_link` (`App\Domain\GitHub\RepoLinkForm`; null without `ai.github.manage` or once linked; `?vincular=1` or a failed store → `open`).
- `Ai/Projects/Show`: `repo_link`, `repo_create` (`{organization, default_name}` = project name slug), both null without `ai.github.manage` or once
  `repository_url` is set / a repo is linked.
- `Ai/Projects/Board`: `selected_task_github` (lazy closure on `?tarea=`, like `selected_task`) and `issue_created` (`Inertia::always`, read from the
  session flash `github_issue_created`, so it survives a partial reload and shows once).
- `Ai/Tasks/Show`: `github` (`App\Domain\GitHub\TaskActivity`, null without `ai.manage`, e.g. a team member requester).
- `Ai/Docs/Repo`: `repo` from `App\Domain\GitHub\RepoDocs`.

Classes:
- `App\Domain\GitHub\RepoActivity`: latest payload per PR/issue from processed events of the repo (newest 300 per type), `PullRequest`/`GitHubIssue`
  shapes, actors via `users.github_login` (`@login`), `LinkedWork` (first `GIA-…` key, task holding the PR/issue in the repo projects), closing PR
  from merged PR bodies; `repositoryOf(project)` = project link, else its product's link.
- `App\Support\Ai\Presenters\GitHubPresenter`: `GitHubRepo` (free repos first, then by name), `GitHubRepoLink`, `CiRun` (`none` placeholder).
- `App\Domain\GitHub\IssueText`: mirrors lib/issue.ts (`gestor-ia` first, privacy footer appended when missing) and re-validates: SecretGuard,
  money amounts (`$ 1.200`, `COP`, `USD`) and internal notes (≥ 15 chars) of the project's requests.
- `GitHubApp::tree()` (git trees, recursive) and `lastCommitAt()` (commits API, per path) for the docs page.
- Migration `2026_10_28_000001_add_github_issue_to_ai_tasks`: `ai_tasks.github_issue` json snapshot (title, labels, assignee login, created_by_id,
  created_at) shown until the `issues` webhook arrives. `AiProduct|AiProject::githubRepository()` morphOne.
- `TaskPresenter::github` (`GithubLink`): CI `none` now maps to null (type has no `none`).

Decisions:
- A project may reuse its product's repository: the product keeps the morph link (events, CI, releases); the project only stores
  `repository_url`/`repository_branch` (one link per repo in `github_repositories`). Another product/project cannot take a linked repo.
- Linking re-queues failed `repo_not_linked` events of that repo (pending, error cleared, `ProcessGitHubEvent` after commit) and audits
  `github_repository_linked`; repo creation audits `github_repository_created`; project links/creations/issues add a "github" activity.
- GitHub failures are validation errors (`title` for issues, `name` for repo creation) with Spanish messages by reason; tokens never reach the
  session or response (asserted). A repo created on GitHub whose file commits then fail is not stored: retrying gives "Ya existe…" (rare; fix by
  `php artisan github:sync` + linking).
- `TaskGitHub.criteria` = acceptance criteria of the project's origin request (tasks have no criteria relation yet). `request_key` = origin request
  key ('' without one). `events`: PR opened (PR `created_at`) / merged (`merged_at`), completed CI runs of the PR (passing/failing), the issue the
  Gestor created, and board moves parsed from the activity feed ("movió T3 … a En revisión|Hecho|En progreso"); ids = source id × 10 + 1/2/3.
- `ProductGitHub`: commits = last 7 Bogotá days of `recent_commits` (max 20 stored), period "jue 1 a mié 7 oct"; `last_closed_pull_request` = latest
  closed without merging (as the mock); deploy provider = product hosting (else "GitHub"), url = product url; `planned_release` always null (no planned
  version stored); `fetched_at` = repo row `updated_at`.
- Docs: root `*.md` + everything Markdown under `docs/` (folders as `dir` rows with depth), README.md then CLAUDE.md first; dates for the first 40
  files (one commits call each); listing and each file cached 10 min; `release` = first GitHub release published on/after the file's last change.
- All GitHub instants are converted to America/Bogota offsets.

Notes for design:
- Issue dialog: GitHub/no-repo/duplicate errors arrive on `title`; credential/money/internal-note refusals on `body`; no GitHub login on `assignee_id`.
- `IssueAssignee.github_login` is sent as `@login` (null without a login), like `GitHubActor.login`.
- If a future board call uses `only` after "Crear issue", `issue_created` is still included (`Inertia::always`).

### D4 · Releases — done (`1e84a97`)
- Route: delegated (writer trigger: action, domain builder, availability class, notification, controller, form request, routes, config; 1 test file).
- Test-first: 9 new tests (`tests/Feature/GitHub/ReleasePageTest`) written first and observed RED (9 of 9 failing: routes `ai.releases.*`
  and the `ai.release.published` event missing), then GREEN.
- Checks: `php artisan test --filter='Release'` 12/12 (201 assertions); full suite 1031/1031 passed (11079 assertions);
  `vendor/bin/pint --test` passed; `npm run typecheck` passed.
- Size: ~560 authored code lines (with docblocks) + ~330 test lines, above the ~400 heuristic: page, notes and notify share one release
  builder and one action, kept together so the notice and the page preview stay consistent.

Routes (`can:ai.manage` + AiProductPolicy::view; writes also `manageVersions`), `App\Http\Controllers\AI\ReleaseController`:
- `ai.releases.show` GET `/ai/products/{product}/releases/{release}` (`{release}` = tag of a version of that product, else 404).
- `ai.releases.notes.update` PUT `.../notes`, body `{user_notes, suggestion_id}` → flash "Notas guardadas.".
- `ai.releases.notify` POST `.../notify`, same body → flash "Se avisó a N solicitante(s).". Errors keyed `user_notes`.

Props `Ai/Releases/Show`: `product {slug, name, company_id (primary company)}`, `release` (`App\Domain\GitHub\ReleaseDetail`),
`suggestion: null` (D6), `ai` / `ai_usage` (`App\Support\Ai\AiSuggestionAvailability`, reusable by D6 on the request page), `now`.

Classes:
- `ReleaseDetail`: `Release` from the version row; `included` oldest first with real status, requester name, company short name, request
  area and `found_in_pr`; `author` = Gestor person by `github_login`, else the login; `compare` = previous release of the same repo
  ("v1.8.1...v1.8.2", `{repo}/compare/...`), null for the first; `repo` = linked repo full name; `technical_notes` '' when the body is empty.
  `notifiedCount()` = distinct requesters once notified (0 before); `ProductActivity` releases use it too (was the request count).
- `AiSuggestionAvailability::for(user)` = `hidden` without `ai.manage`, else `AiRuntime::status()`; `usage(user)` only for `off_cap`:
  `spent_label`/`cap_label` "US$ 10,50", `resumes_on` = first day of next month in Bogotá ("1 de noviembre").
- `App\Actions\Ai\ReleaseNotes`: `save()` (notes kept as typed; blank → null) and `notify()`; both lock the version row and are audited
  in the `ai` log (`ai_release_notes_updated`, `ai_release_notified`: product, tag, notes length, suggestion_id, request keys, requester
  count; never the notes text).
- `App\Notifications\AiReleasePublished` (event `ai.release.published`, default channels Telegram + bell, user preferences apply).
- `TransitionRequest::apply(..., bool $notifyRequester = true)`: the release passes false.

Decisions:
- One notice per requester per release: `notify()` moves requests with `TransitionRequest::apply(..., notifyRequester: false)` (history,
  first response, `rating_requested_at` still stamped because the release notice asks for the rating), then sends one
  `AiReleasePublished` per distinct requester listing all of their requests. So no `AiRatingRequested`/`AiRequestStatusChanged` is sent
  for the same event (asserted). A request moved to production manually before the release already got its rating notice; the release
  notice is a separate event.
- Only requests "En pruebas" move to "En producción" (the machine's only path; ValidateAcceptance keeps production a TI decision after the
  requester validates). Requests in other statuses keep theirs, and their requesters are still notified because the release includes them
  (the page counts recipients from `included`). Requests stay linked through the existing `ai_product_version_request` pivot;
  `ai_requests.product_version` is not overwritten (it is the version the requester reported).
- Idempotent: a second notify, and any notes change after notifying, are refused with `user_notes` "Ya se avisó a los solicitantes de esta
  versión." Notify needs non-blank notes ("Escribe las notas para los usuarios antes de avisar.") and at least one included request
  ("Esta versión no incluye solicitudes: no hay a quién avisar."). Max 5.000 characters.
- `suggestion_id` is validated (nullable integer) and recorded only in the audit until `ai_suggestions` exists (D6 links it and marks the
  suggestion accepted).
- The notice carries only the confirmed user notes: never technical notes, costs or internal notes (asserted).

Telegram / bell text (FOR JHON'S APPROVAL), mirrors `lib/releaseNotice.ts`:
- Title: "{Producto} {tag} · Ya está disponible".
- Body: "Hola, {nombre}. Ya está disponible lo que pediste: {título} ({GIA-…}) y {título} ({GIA-…}), en {Producto} {tag}." + the confirmed
  notes + "¿Cómo te fue? Califica la entrega en un minuto." Button "Calificar" → rating page of the first request.
- Preferences label "Entregas publicadas", hint "Cuando sale una versión con lo que pediste, con las notas de lo que cambió".

Notes for design:
- `ReleaseRequest.status` is typed `'in_production'`, but before notifying an included request may still be "En pruebas" (or another status
  if it was not tested): the server sends the real status. Please widen it to `RequestStatusKey`; `GestorDid` says "y la pasó a En
  producción" for every request even before the notice — consider "la pasará a En producción al avisar" while `notified_at` is null.
- `ReleaseSummary.url` / `Release.url` stay the GitHub release URL; the product page already links the tag with `route('ai.releases.show')`.
- Errors of notify/update arrive on `user_notes` (the page already shows them).

### D5 · Brief para Claude — done (`1522330`)
- Commit: the `feat(ai)` commit that adds this block (code, tests and this evidence in one work unit).
- Route: delegated (writer trigger: builder + sanitizer classes, 3 controllers wired; 1 new test file + 2 updated).
- Test-first: 8 new tests (`tests/Feature/Ai/Brief/BriefInputsTest`) written first and observed RED (8 of 8 failing: props `brief`,
  `brief_omitted`, `brief_state`, `selected_task_brief` missing), then GREEN. Prop-key lists updated in `ProjectPagesTest` (show, board,
  task page) and `Triage/ItShowTest`.
- Checks: `php artisan test --filter='Brief'` 8/8 (181 assertions); full suite 1039/1039 passed (11260 assertions);
  `vendor/bin/pint --test` passed; `npm run typecheck` passed.
- Size: ~500 authored code lines (with docblocks) + ~420 test lines, above the ~400 heuristic: the three inputs share one whitelist,
  one sanitizer and the same link/task/decision builders, kept together so the leak test covers all four pages at once.

Props (no new routes; the page builds the Markdown with `lib/brief.ts`):
- `Ai/Requests/Show` IT view: `brief` (`RequestBriefInput`), `brief_state`. The requester view never has either key (asserted `missing`).
- `Ai/Projects/Show`: `brief` (`ProjectBriefInput`), `brief_omitted` (`{time_and_costs, internal_notes}`), `brief_state`.
- `Ai/Projects/Board`: `selected_task_brief` (`TaskBriefInput`) and `selected_task_brief_state`, lazy closures on `?tarea=` like
  `selected_task` (partial reload `only: ['selected_task','selected_task_github','selected_task_brief']` covered).
- `Ai/Tasks/Show`: `brief`, `brief_state`.
- `brief_state` = `{open: true}` with `?brief=1`, else null. Every brief prop is null without `ai.manage` + company access (request
  `viewInternal`, project `manage` policies); `brief_omitted`/`brief_state` are null whenever `brief` is null.

Classes:
- `App\Domain\Ai\Brief\BriefInputs`: `forRequest`, `forProject`, `forTask`, `omitted`, `state`.
- `App\Domain\Ai\Brief\BriefText`: drops a whole free-text field (null / '' / item removed) when SecretGuard finds a credential, it
  carries a money amount (IssueText rule: `$ 1.200`, `COP`, `USD`), or it quotes an internal note (≥ 15 chars) of the related requests;
  links must be http(s) and are dropped when label or URL matches `vault|bóveda` (lib/brief.ts rule) or SecretGuard.

Whitelist (exactly the type fields):
- Request: key, title, company short name, area, `requester` = requester's job title (else the area name; never the person's name),
  size, first project key, problem, current process, expected result, hours per week and people (the requester's impact numbers the
  type asks for, not logged hours), criteria texts, product, links, the project's task rows and decisions.
- Project: key, name, company, area, status label, phase (Plan / Construcción / Entrega from the status, as lib/status.ts), request keys,
  objective, criteria of all its requests with `done` = validated pass, product, links, task rows, decisions.
- Task: key, title, project, origin request, column name, assignee names, description, subtasks, origin request criteria, product,
  links, `issue` from `github_issue_number`; `pull_request` and `suggested_branch` null (the page adds them from `TaskGitHub` with
  `withTaskGitHub`).
- Product: name, `current_version`, stack, hosting; `data_source` = systems the (origin) request named, joined.
- Links: linked GitHub repo (`Repositorio`) + `{repo}/blob/{tracked branch}/CLAUDE.md`, else project `repository_url` / product
  `repository` when it is a URL; then Gestor docs (`ai.docs.edit`) of the project and the product with templates `project_sheet`,
  `requirements`, `architecture`, `technical_manual`, `user_manual` (never `prompts`: it carries model costs), project docs first.
- Task rows: board order, column name, `PR #n` / `Issue #n` / null.
- Decisions: rows of the project's "Registro de decisiones" (`| Fecha | Decisión | Motivo |`) → "Decisión. Motivo: motivo", max 20;
  a decisions document with visibility `it` is never read.
- Never read: vault items, cost rates, time entries, project costs, estimates, task estimate hours, product monthly cost, area hour
  value, request/task comments, internal notes. The leak test seeds all of them and asserts none of their values appear in the
  json-encoded briefs of the four pages.

Decisions:
- `brief_omitted.time_and_costs` = the project has time entries, costs, estimates or task estimate hours; `internal_notes` = internal
  notes of the project's requests.
- Unsafe text is dropped whole (no partial masking), so a brief never carries half a secret.

Notes for design:
- No type changes needed. `RequestBriefInput.requester` carries a job title / area (privacy), not a person's name.
- `TaskBriefInput.pull_request` is always null from the server; the drawer and the task page already merge the task's PR from
  `TaskGitHub` (`withTaskGitHub`), so no "related PR" of another task is sent.

### D6 · AI suggestions — done
- Commit: the `feat(ai)` commit that adds this block (code, tests and this evidence in one work unit).
- Route: delegated (writer trigger: migration, model, factory, 5 domain classes, job, presenter, controller, 4 controllers/actions wired, routes, config; 6 test files + 1 updated).
- Test-first: 29 new tests (`tests/Feature/Ai/Suggestions/{SuggestionRoutesTest,SuggestionPagesTest,SuggestionFormsTest,SuggestionInputTest,ReleaseNotesSuggestionTest}`)
  plus the prop-key list of `Triage/ItShowTest`, written first and observed RED (30 of 36 failing in those files: routes `ai.requests.suggestions.store`,
  `ai.suggestions.*`, `ai.requests.compare`, `ai.requests.criteria.update`, model `AiSuggestion` and props missing), then GREEN.
- Checks: `php artisan test --filter='Suggestion|Compare|Criteria|Evaluation|Release'` 50/50 (742 assertions); full suite 1068/1068 passed (11712 assertions);
  `vendor/bin/pint --test` passed; `npm run typecheck` passed.
- Size: ~1,870 authored code lines (with docblocks) + ~1,000 test lines, above the ~400 heuristic: the request suggestions, the forms that credit them and
  the release draft share one table, one whitelist builder, one generator and one presenter, kept together so the leak test and the cancel race cover all kinds.

Schema (`2026_10_29_000001_create_ai_suggestions_table`):
- `ai_suggestions`: `subject` morph (`AiRequest` / `AiProductVersion`, class names), `kind` (summary|duplicate|criteria|size|release_notes), `state`
  (generating|ready|accepted|discarded|error|cancelled), `batch` uuid (rows of one provider call), `provider`, `model`, `payload` json (the TS payload),
  `accepted_value` json, `accepted_by_id/at`, `discarded_by_id/at`, `discard_reason` (≤ 500), `input_hashes` json (sha1 per whitelisted field),
  `error` json (`SuggestionError`), `ai_interaction_id`, `requested_by_id` (null for the release draft), `superseded_at`.
- `ai_requests.summary_text`: "Resumen para TI" once a summary is used (`RequestSuggestions.summary_text`). No company column: suggestions are child
  data reached only through the request (company scope + AiRequestPolicy::evaluate) or the release's product (view + manageVersions).

Classes:
- `App\Models\AiSuggestion` (+ factory; scopes `current` = not cancelled nor superseded, `of($subject)`).
- `App\Domain\Suggestions\SuggestionInput`: the whitelist builder (below) and the input hashes.
- `App\Domain\Suggestions\RequestSuggestionAgent` / `ReleaseNotesAgent` (laravel/ai `Agent`, `MaxSteps(1)`, `RetryTransientStep`, JSON out),
  called only through `AiRuntime::run()`.
- `App\Domain\Suggestions\SuggestionAnswer`: defensive JSON parsing (text around ignored, types checked, lengths cut; criteria ≤ 6 with ids 1..n,
  size in `EffortSize`, duplicates only keys that were offered, ≤ 3, `level` alto|medio).
- `App\Domain\Suggestions\SuggestionGenerator` (+ `App\Jobs\GenerateSuggestions`, 1 try, timeout 150 s) and `App\Domain\Suggestions\Suggestions` (actions).
- `App\Support\Ai\Presenters\SuggestionPresenter` (`Suggestion`, `RequestSuggestions`, release suggestion, duplicate for Compare).
- `App\Http\Controllers\AI\SuggestionController`.

Routes (`can:ai.manage`; every action re-checks the subject):
- `ai.requests.suggestions.store` POST `/ai/requests/{request}/suggestions` → back. Refused (`suggestion`) when the AI is not ready or every kind exists.
- `ai.suggestions.accept` POST `/ai/suggestions/{suggestion}/accept` `{value?}` (≤ 5.000, blank refused) → `accepted_value`; summary also writes
  `ai_requests.summary_text` (the only write to a request). `ai.suggestions.discard` `{reason?}` (≤ 500). `ai.suggestions.regenerate`, `ai.suggestions.cancel`.
  State errors arrive on `suggestion` ("Esta sugerencia ya no se puede usar.", "Esta sugerencia ya se está generando.", "Esta sugerencia ya no se está generando.").
- `ai.requests.compare` GET `/ai/requests/{request}/compare/{other}` → `Ai/Requests/Compare` `{current, other, suggestion, now}` (`viewInternal` on both;
  404 without an AI duplicate suggestion of `request` pointing to `other`). `CompareSide` = summary, problem, expected result, systems.
- `ai.requests.criteria.update` PUT `/ai/requests/{request}/criteria` `{criteria: {id|null, text}[], suggestion_id}`: 1–20 lines, text ≤ 500; rows synced by
  id (positions 1..n, missing rows deleted, new rows created); ids of another request and changes to a criterion the requester already validated are
  refused on `criteria`; `suggestion_id` must be a criteria suggestion of the request (`suggestion_id` error), marked accepted with the saved texts.
  Flash "Criterios guardados.".
- `ai.requests.evaluation.update` accepts `size_suggestion_id` (nullable integer): marked accepted only when it is a size suggestion of the request and the
  saved size equals its payload; otherwise ignored.
- `ai.releases.notes.update` / `ai.releases.notify`: `suggestion_id` must be a `release_notes` suggestion of that version (`suggestion_id` error); it is marked
  accepted with the confirmed text. Requesters still receive only the confirmed `user_notes` (asserted).

Props:
- `Ai/Requests/Show` IT view: `suggestions` (closure, partial reload `only: ['suggestions']` covered with X-Inertia-Version + flushHeaders), `ai_availability`
  (`AiSuggestionAvailability`), `ai_usage`, `merge_into` (`?unir=KEY`, case-insensitive: another open, unmerged request in the viewer's company scope, else null).
  `suggestions` is null unless availability is `ready`, and null when nothing was suggested; per kind the newest current row; duplicates newest first (discarded kept).
  The requester view never has any of the four keys (asserted `missing`).
- `Ai/Releases/Show`: `suggestion` = newest current `release_notes` suggestion (null when the AI is not ready or none exists).

AI input whitelist (`SuggestionInput`; leak tests `SuggestionInputTest` and `ReleaseNotesSuggestionTest`):
- Request: `{request: {title, problem, current_process, expected_result, app}}` (app = product name, else the systems the requester named), and for
  duplicates `open_requests: [{key, title}]` = up to 60 newest open, unmerged requests of the same company (never finished ones or other companies).
- Release: `{product, tag, requests: [{key, title}], technical_notes}`; technical notes line by line.
- Every free text goes through `BriefText` (SecretGuard, money amounts, quotes of the request's internal notes): an unsafe field or title is dropped whole,
  an unsafe technical-notes line is dropped. Never sent: requester/people names and emails, hours, people, who suffers, success criteria, systems notes,
  costs, rates, estimates, area hour value, comments, internal notes, attachments, vault.
- Prompts (agent instructions) are in Spanish; the input is the JSON above; answers are Spanish JSON.

Call strategy and states:
- "Sugerir con IA" = one batch, ONE provider call (`suggestion.request`) for summary + criteria + size + duplicates; rows are created `generating` for
  summary/criteria/size (the mock shows `duplicates: []` while generating) and duplicate rows are created when found. "Volver a sugerir" = one call for
  that kind (`suggestion.summary|criteria|size|duplicates`; a duplicate regenerate uses a placeholder row, deleted when nothing is found). Release draft =
  `suggestion.release_notes`.
- Execution: database queue (`GenerateSuggestions` dispatched after commit); the cron worker runs it within a minute. Each finished kind supersedes the
  older rows of its kind; duplicates keep discarded rows and a discarded request never comes back for the same request.
- Cancel: only `generating` → `cancelled` (never sent to the page; the previous suggestion of the kind shows again). The generator writes only rows still
  `generating` (conditional update), so a cancel always wins; a batch whose rows were all cancelled is not sent.
- `stale` is computed on presentation: a `ready` row whose `input_hashes` differ from the subject's current ones → `state: 'stale'`,
  `stale: {reason: "Cambió el resultado esperado" | "Cambiaron el problema y el resultado esperado", at: subject updated_at}`. Accept still works.
- Errors (`SuggestionError`, message "No se pudo generar la sugerencia."): `ai_timeout` ("DeepSeek no respondió a tiempo. La solicitud no cambió."),
  `ai_error`, `bad_answer`, `ai_off`, `subject_missing`; `too_short` (title + problem + current process + expected result under
  `gestor.ai.suggestions.min_words` = 15 words after unsafe fields are dropped): no call, only a summary row "No hay suficiente texto para resumir." /
  "GIA-n tiene menos de 15 palabras. Pídele más detalle a quien la envió.".
- Release draft: `AiProductVersion` `saved` hook (new row or newly set `github_release_id`) → `Suggestions::draftReleaseNotes()` when the AI is ready, the
  version was not notified and no current draft exists; queued after the webhook transaction commits, so included requests are attached.
  `source_note` = "Hecha con el título de GIA-118 y las notas técnicas del release. No usa costos ni notas internas." (plural with several keys).
- Audit (`ai` log, ids and kind only, never text): `ai_suggestion_accepted`, `ai_suggestion_discarded`.

Decisions:
- The release page has no "Sugerir" button (ai-github.ts only lists discard/regenerate), so the draft is created when the GitHub release arrives (model
  hook; D2 handlers and their tests unchanged). Releases imported while the AI was off get no draft (regenerate needs an existing row).
- Discard is allowed on accepted/error rows; accept on ready (or stale) and accepted rows (Jhon may re-use an edited summary).
- `ai_availability` off → `suggestions: null` (as the mocks); stored suggestions come back when the AI is ready again.

Notes for design:
- Nothing polls yet: while any suggestion is `generating`, `RequestSuggestions` should `router.reload({ only: ['suggestions'] })` every few seconds (the
  queue worker runs every minute on cPanel), and the release page should reload `only: ['suggestion']` (its `useState(props.suggestion)` also ignores new props).
- The release page cannot ask for a first draft when none exists (`suggestion: null` with the AI ready); consider a "Sugerir notas" button (needs a route).
- Errors of accept/discard/regenerate/cancel and "Sugerir con IA" arrive on `errors.suggestion`; criteria errors on `criteria`, `criteria.N.text`,
  `suggestion_id`; release `suggestion_id` errors on `suggestion_id`.
- `Suggestion.provider` is the label of the provider that answered ("DeepSeek"), the configured one while generating.

## Open questions (for Jhon)
- GitHub organization name, App registration (app id, private key, webhook secret go to `.env`).
- `github_login` for each IT member (issue assignees).
