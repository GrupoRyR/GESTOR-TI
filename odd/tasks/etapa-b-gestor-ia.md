# Stage B · Gestor IA core (backend)

Source of truth: `PROMPT_GESTOR_IA_APP.md` §4 (6.4.1–6.4.11), §6, §9 row B. Page contracts: design branch `claude/diseno-gestor-ia-etapa-b`
(`resources/js/types/ai.ts`, `types/pages/ai-requester.ts`, `types/pages/ai-triage.ts`; projects/products/docs/value/vault types still in progress on the design side — T4/T5).

## Objective
Requests with evaluation, duplicates and +1; products (the 5 current apps); project approval by Jhon or Edward (web + Telegram);
projects, Kanban, tasks, requirements; time and costs estimated vs real; view by area; documentation with templates;
rating and value dashboard; vault. Every page the design delivers returns exactly its typed props with `assertInertia` tests.

## Constraints
- Shared cPanel: database queue + cron; no long-running processes. Duplicate search: MySQL FULLTEXT on the server, LIKE fallback on SQLite (tests).
- Requesters never see costs, rates or internal notes; costs only with `ai.costs.view`; vault only with `ai.vault.access` (+ Microsoft reauth, 30 s reveal, auto-lock 10 min, own `VAULT_KEY`).
- `ai.projects.approve` only via role "Aprobador de proyectos" (Jhon, Edward).
- Data and pages for Stage D (GitHub, AI suggestions) and E (MCP) are out of scope; keep columns ready where the prompt names them (issue/PR on tasks).
- Design-owned folders are not edited. Pages not yet designed: backend + tests first, wired when the design delivers.

## Delivery
- Branch `claude/gestor-ia-app-prompt-d14b73`; slices stacked on `stage-a/08-connect-frontend` (`stage-b/NN-*`). Strategy stacked-to-main (cached).
- RDD off. Checks: `php artisan test`, `vendor/bin/pint --test`, `npm run typecheck`, `npm run build`.

## Tasks
- [x] B1 · Requests domain: `ai_requests` (key GIA-n, type, product/version, requester, company, area + benefited areas, status machine, guided fields, impact/effort/urgency, suggested priority), votes (+1), comments (public/internal), attachments (private disk), acceptance criteria, approvals (area + project), first-response deadline alert, duplicate search service. Seeders for statuses/config.
- [x] B2 · Products: `ai_products` (slug, owner, areas, repo, stack, AI model/provider, monthly cost, status, current version), `ai_product_versions` ↔ requests, link with `monitored_apps`; seed the 5 current apps.
- [x] B3 · Wire requester pages (ai-requester.ts): wizard + duplicates, submitted, mine, show (requester view), validate acceptance, ratings + hours saved, roadmap. Replace Stage B placeholder routes.
- [ ] B4 · Wire IT triage pages (ai-triage.ts): inbox, show (IT view) + actions (evaluate, need info, send to approval, backlog, reject, comments/internal notes, create on behalf), approvals (web + Telegram ✅/❌ via CallbackRegistry), backlog, by area, my week; Home `it`/`requester` real data.
- [ ] B5 · Projects: projects from approved requests, boards/columns (configurable), tasks/subtasks/labels/assignees/due dates/attachments, sprints, requirements to users + reminders, WIP limit, blocks, acceptance checklist, "Mi semana" data, project reports (email, PDF).
- [ ] B6 · Time and costs: estimates, time entries (timer, manual, Telegram `/horas`), cost rates (effective dating, `ai.costs.view`), project costs, AI spend from `ai_interactions`, estimate vs real alerts 80/100 %, re-approval above 20 %, Friday reminder, area hour values and ROI.
- [ ] B7 · Documentation (markdown + revisions + templates + search), ratings lifecycle (3/7 days, 30-day hours saved), value dashboard.
- [ ] B8 · Vault: items encrypted with `VAULT_KEY`, reauth + 30 s reveal + 10 min auto-lock, access log + Telegram notice, denied-attempt alert, rotation/expiry reminders 30/15/7 days.
- [ ] B9 · Wire design T4/T5 pages (projects, products, docs, value, vault, admin costs) when delivered.

## Progress / evidence

### B1 · Requests domain — done (commit `558939d` `feat(ai): add requests domain`)
- Route: delegated (writer trigger: 60+ new files across migrations, models, actions, policy, command, tests).
- Test-first: 96 new tests written first and observed RED (0/96 passing, classes missing), then GREEN.
- Checks: `php artisan test` 509/509 passed; `vendor/bin/pint` fixed 3 files, `vendor/bin/pint --test` passed;
  `migrate:fresh` + `db:seed` + `db:seed --class=AiDemoSeeder` + rollback of the 3 new migrations on a temp SQLite file OK (file deleted);
  `schedule:list` shows `*/15 * * * * ai:first-response-alerts`. MySQL-only FULLTEXT path not run here (verify on the server).
- Size: 3247 authored code lines + 1553 test lines, above the ~400 heuristic: one coherent domain (schema, enums, actions, policy) that B3/B4 consume together.

Decisions:
- Enums `App\Enums\Ai\*` mirror the design keys exactly: type `new|improvement|bug|idea` (not `new_development`), statuses of `lib/status.ts`,
  urgency, size, risk `none|low|medium|high`, reject reasons, acceptance result `pass|fail` (not `ok|fail`), approval decision `pending|approved|rejected`.
- Status machine is `App\Domain\Ai\RequestStatusMachine` (the enum is `App\Enums\Ai\RequestStatus`); `approved` is reached only by `RecordApprovalDecision`.
- Area approval is a flag `area_approval_status` (null|pending|approved|rejected) beside `status` (the design has no status for it); pending blocks Recibida → En evaluación.
- Keys `GIA-n` from the `sequences` table with `lockForUpdate`; explicit/imported keys push the sequence forward; deleted requests keep their number.
- `priority_score`/`final_priority` are 0–100 integers (the formula returns integers) plus `priority_overridden`; votes recompute the suggestion and the final follows it until overridden.
- Duplicates: MySQL `MATCH … AGAINST` natural language on (title, problem); other drivers score keywords (no Spanish stopwords/accents) in PHP over the newest 500; rejected/closed excluded.
- First response: due = creation + `ai.first_response_business_days` business days, Bogotá, weekends and Settings `ai.holidays` skipped (no holiday calendar bundled).
  TI's first status move, evaluation or public reply stamps it and resolves the `ai.first_response:{key}` alert.
- Approval rows are one per asked approver (pending → decided, estimate snapshot JSON, `require_all`, `is_reapproval`); Settings `ai.approval.require_other_if_evaluator` (off).
- The wizard has no title: the server derives it from the first sentence of the problem (max 120).
- The requester's public reply on "Necesita información" moves it back to "En evaluación".

Open for B3/B4: whether any requester may open the detail of another person's roadmap item (today: requester, voters, area viewers, approvers, `ai.manage`);
`SendToApproval` (estimate + approver rows + notification) belongs to B4; `product_id` foreign key in B2.

### B2 · Products — done (commit `3dacf33` `feat(ai): add products and versions`)
- Route: delegated (writer trigger: 15 new files across migrations, models, policy, resolver, seeder, factories, tests).
- Test-first: 31 new tests written first and observed RED (0/31 passing, classes missing), then GREEN.
- Checks: `php artisan test` 540/540 passed; `vendor/bin/pint` fixed 1 file (unused import), `vendor/bin/pint --test` passed;
  `migrate:fresh --seed` on a temp SQLite file OK (5 products, 5 linked monitored apps, 6 area links), seeder re-run idempotent,
  rollback + re-migrate of the 2 new migrations OK (file deleted).
- Size: ~690 authored code lines + ~520 test lines, above the ~400 heuristic: schema, models, policy and seeder are one unit B3/B9 consume together.

Decisions:
- `ai_products` (slug unique even when soft-deleted, owner_user_id + owner_name fallback, url, repository "owner/repo", stack, hosting,
  ai_provider, ai_model, monthly_cost decimal + `monthly_cost_currency` default COP, status, current_version, notion_url, soft deletes);
  pivots `ai_product_company` and `ai_product_area` (both with `is_primary`); `ai_product_versions` (version, released_at, summary, notes markdown,
  source manual|github_release, release_url; unique per product) + `ai_product_version_request`.
- Enum `App\Enums\Ai\ProductStatus` mirrors the design `ProductState` values; the column is `status` (design field `state`), hosting column is `hosting` (design `host`).
  The design `Product` has one `company_id`: serialize `primaryCompany()` (pivot `is_primary`).
- Not company-scoped: `AiProduct::visibleTo($user)` filters by the CurrentCompany context through the pivot ("Todas" = all, no company = none);
  `requests()` and version requests skip `CompanyScope` (multi-company product).
- FKs `ai_requests.product_id` and `monitored_apps.ai_product_id` → `ai_products` null on delete, in a separate migration (works on SQLite).
- `health()` is the placeholder "Salud: monitoreo aún no activo" unless the product has a monitored app and `modules.monitoring.active` is on.
- `openRequestsCount()` excludes in_production, rejected and closed; `averageRating()`/`hoursSaved()` return null until B7;
  `monthlyAiSpend()` sums `ai_interactions.cost` with `source` = the product in the current calendar month (interactions currency, USD), not COP.
- `AiProductPolicy`: viewAny module users (`ai.requests.create`) or `ai.manage`; view `ai.manage`, owner, or module users of a product company;
  create/update/delete/manageVersions `ai.manage`; viewCosts = `costVisibleTo()` = `ai.costs.view`.
- `ProductResolver` (`fromSlug`, `forUser`, `byIdForUser`, `version`): `CreateRequest` takes `product` (slug) or `product_id`; it must serve the
  requester's company (else validation error on `product`); the version defaults to `current_version`.
- `AiProductSeeder` (in DatabaseSeeder) is create-only by slug (soft-deleted included): the 5 apps of §1, all `production`, slugs/descriptions/areas
  from the design fixtures, hosting "Equipo local" for robotcamara; it creates or links a `monitored_apps` row per product (web, local for robotcamara).
  Fixture statuses (pilot/development), versions, costs and ratings were not copied: they are mock data.

Pending from Jhon: product owners, URLs, repositories, current versions, monthly costs, AI provider/model per product, areas of Cámaras.

### B3 · Requester pages — done (commit `b10e492` `feat(ai): wire requester pages`)
- Route: delegated (writer trigger: 25 new files across migration, models, domain, presenters, controllers, form requests, routes, tests).
- Test-first: 7 new/updated test files (41 new tests + policy/shell-route updates) written first and observed RED (37 of 52 failing: routes, `Roadmap` and policy abilities missing), then GREEN.
- Checks: `php artisan test` 576/576 passed; `vendor/bin/pint` fixed 4 files, `vendor/bin/pint --test` passed; `npm run typecheck` passed;
  `php artisan route:list --path=ai` shows 35 routes (requester routes real, IT ones TEMPORARY); new migration up/rollback/up on a temp SQLite file OK (file deleted).
- Size: ~2160 authored code lines + ~1150 test lines, above the ~400 heuristic: eight pages share presenters, copy and the roadmap rule, so they ship as one unit.

Decisions:
- Routes exactly as `ai-requester.ts`: `ai.requests.create` (+ `?producto=` and partial reload `similar` with `q`, 20+ chars, own company only, 5 max), `ai.requests.draft.update` PUT,
  `ai.requests.store`, `ai.requests.submitted`, `ai.requests.mine` (`filter` active|done|all; done = rejected/closed), `ai.requests.show`, `ai.requests.vote` POST/DELETE,
  `ai.requests.follow` POST/DELETE, `ai.requests.comments.store`, `ai.requests.validation.update` PUT, `ai.ratings.create|store`, `ai.ratings.hours.store`, `ai.roadmap.index`.
  Added (not in the contract): `ai.requests.validation.edit` GET and `ai.ratings.hours.create` GET (pages need a URL; notifications/B7 link to them),
  `ai.requests.attachments.show` (signed 30-min links for `FileRef.url`, re-authorized; internal-note files need `viewInternal`), DELETE twins named `*.destroy`.
- Controllers live in the existing `App\Http\Controllers\AI` namespace (folder `AI`, case-insensitive on Windows; a second `Ai` folder would break PSR-4 on Linux).
  Presenters: `App\Support\Ai\Presenters\{RequestPresenter,RequesterDetailPresenter}`; copy: `App\Support\Ai\RequesterCopy`; rank rule: `App\Domain\Ai\Roadmap`.
- Visibility: `AiRequestPolicy::view` = takes part (requester, voters, followers, approvers, area viewers, `ai.manage`) OR `ai.requests.create` with access to the company.
  Commenting still needs taking part (a colleague can follow, which makes them take part). `viewOwn` guards Submitted. `ai.manage` holders opening `ai.requests.show` are redirected with the Stage B notice until B4 ships the IT view.
- Requester props never read costs: estimate = `{due_date, hours_total}` from the latest project-approval snapshot (`estimate` JSON), approval without costs, public comments only, wizard files only.
- Roadmap: now = in_development/in_testing, next = approved/planned, later = pending_approval/backlog. Rank only for `later`, per area: final priority desc (null last), votes desc, oldest, id.
  The design fixtures rank pending approval (GIA-146) and not approved, so "approved/backlog" from the brief became "pending_approval/backlog". `later` shows 20 cards, `later_total` counts all;
  `wip_limit` = Settings `ai.wip_limit` (default 3, not in config defaults yet); `planned_start` null until B5; areas get " · {short_name}" when several companies appear.
- Followers (`ai_request_followers`): requester (CreateRequest) and voters (VoteRequest) follow automatically; unvote keeps following.
- Drafts (`ai_request_drafts`, one per user, wizard fields only); deleted on store; `initial_step` = first step with missing required answers.
- Store: each non-empty line of "Cómo sabría que quedó bien" becomes an acceptance criterion; `product_slug` is validated with `ProductResolver::forUser`.
- Validation: any criterion ending "fail" moves En pruebas → En desarrollo (TransitionRequest, public note); all pass keeps En pruebas for TI.
- Ratings: `ai_ratings` (solved_need, ease_of_use 1–5, comment, rated_at) and `ai_hours_saved_reports` (hours_per_week 0–60 step 0.5), unique per request+user.
  `rate` = requester, in_production, not rated; `reportHoursSaved` = requester, in_production or closed, not reported. Body keys are the design ones (`need_score`, `ease_score`).
  `AiProduct::averageRating()` (avg solved_need, 1 decimal), `ratingsCount()`, `hoursSaved()` (sum hours/week × 52/12, 1 decimal).
- Home requester: up to 10 open requests (not rejected/closed), latest activity first, with next step, `can_rate` and "Puesto N en la hoja de ruta de {Área}".
- Placeholders kept (TEMPORARY): `ai.requests.index`, `ai.tasks.show`, `ai.requirements.show`, `ai.approvals.show`, `ai.vault.index`, plus every GestorLayout tab (`ai.inbox|backlog|areas|projects|products|docs|week|value.index`).

Notes for design:
- No link to the Validate page or the HoursSaved page in the requester pages; backend names: `ai.requests.validation.edit`, `ai.ratings.hours.create`.
- `RatedRequest.product_name` is required but requests of type "new" have no product: the server sends the request title.
- `RoadmapItem.planned_start`, `RequestSummary.project_key` and `similar_to` are always null until B4/B5.

## Next step
B4 · Wire IT triage pages (ai-triage.ts): replace the IT placeholders and the `ai.manage` redirect on `ai.requests.show` with the IT view.
