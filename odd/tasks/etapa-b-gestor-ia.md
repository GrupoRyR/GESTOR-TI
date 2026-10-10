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
- [x] B4 · Wire IT triage pages (ai-triage.ts): inbox, show (IT view) + actions (evaluate, need info, send to approval, backlog, reject, comments/internal notes, create on behalf), approvals (web + Telegram ✅/❌ via CallbackRegistry), backlog, by area, my week; Home `it`/`requester` real data.
- [x] B5 · Projects: projects from approved requests, boards/columns (configurable), tasks/subtasks/labels/assignees/due dates/attachments, sprints, requirements to users + reminders, WIP limit, blocks, acceptance checklist, "Mi semana" data, project reports (email, PDF).
- [x] B6 · Time and costs: estimates, time entries (timer, manual, Telegram `/horas`), cost rates (effective dating, `ai.costs.view`), project costs, AI spend from `ai_interactions`, estimate vs real alerts 80/100 %, re-approval above 20 %, Friday reminder, area hour values and ROI.
- [x] B7 · Products pages, documentation (markdown + revisions + templates + search), ratings lifecycle (3/7 days, 30-day hours saved). The value dashboard (`Ai/Value/Index`, `ai-value-vault.ts`) moved to B8 with the vault.
- [x] B8 · Value dashboard (`Ai/Value/Index`, ROI with area hour values and project costs) and vault: items encrypted with `VAULT_KEY`, reauth + 30 s reveal + 10 min auto-lock, access log + Telegram notice, denied-attempt alert, rotation/expiry reminders 30/15/7 days.
- [x] B9 · Design delivered (PR #10, `docs/design/stage-b-contract.md`); merged into the chain as `2a804cb` (package.json: Inertia v3 kept + `markdown-it`; lock regenerated; typecheck, 300 vitest, build and 637 PHP tests green). B5–B8 now wire the pages together with their domain: B5 `ai-projects.ts` (projects, board, tasks, requirements, report), B6 `Ai/Projects/Costs`, `Ai/TimeEntries/Create`, `admin-costs.ts`, B7 `ai-knowledge.ts` + ratings lifecycle, B8 `ai-value-vault.ts`. Not designed (keep out): product create/edit, incident create, vault item edit.

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

### B4 · IT triage pages and project approvals — done (commit `398736f` `feat(ai): wire IT triage pages and project approvals`)
- Route: delegated (writer trigger: 47 code files across migration, models, domain, actions, presenters, controllers, Telegram, routes; 11 test files).
- Test-first: 10 new test files + 2 updated (77 tests in the run) written first and observed RED (61 of 77 failing: routes, models and classes missing), then GREEN.
- Checks: `php artisan test` 637/637 passed; `vendor/bin/pint` fixed 5 files, `vendor/bin/pint --test` passed; `npm run typecheck` passed;
  `php artisan route:list --path=ai` shows 58 routes; new migration up/rollback/up on a temp SQLite file OK (file deleted).
- Size: ~3800 authored code lines + ~1530 test lines, above the ~400 heuristic: seven pages share the triage domain (inbox, rounds, estimates, timeline) and the approval flow spans web and Telegram.

Decisions:
- Routes exactly as `ai-triage.ts` (`ai.inbox.index`, `ai.requests.evaluation.update|notes.store|reject|ask|merge|approval.store`, `ai.requests.bulk.ask|bulk.assign`,
  `ai.approvals.index|show|approve|reject|remind`, `ai.backlog.index|reorder|manual`, `ai.areas.index`, `ai.week.index`, `ai.requests.remind`).
  IT pages/actions: `can:ai.manage` + policy `evaluate`/`transition` (company access). Approve/reject: `can:ai.projects.approve` + `approveProject`.
  Added (not in the contract): `ai.requests.backlog` POST `{ note? }` ("Mover al backlog"), `requester_id` on `ai.requests.store` (create on behalf, `ai.manage`).
  `ai.requests.index` (Inicio "Ver todas") redirects to the inbox chip (`unanswered`, `pending_approval`) or Mi semana (tasks/requirements).
- Placeholders kept (TEMPORARY; pages → Inicio, actions → back, with the notice): `ai.tasks.show`, `ai.requirements.show|remind`, `ai.requests.convert`, `ai.timer.start|stop`,
  `ai.reviews.store`, `ai.projects.index|show`, `ai.products.index|show`, `ai.docs.index`, `ai.value.index`, `ai.vault.index`.
- Inbox = Recibida, En evaluación, Necesita información, Pendiente de aprobación (company scope); `unanswered` = no first response; order: unanswered by due date, then newest.
  Counts honour the area/company filters; `company_id` only in "Todas"; `merge_candidates` is an optional prop (exact key first, then DuplicateFinder, 8 max) on the inbox and the IT show.
  `RequestSummary.similar_to` is filled on the IT detail (first duplicate); inbox rows keep null (no per-row duplicate search).
- IT show: same component, `view: 'it'`; `RequestDetail` + internal notes, IT timeline (status changes + new `ai_request_events`: evaluation, sent to approval, notices, merges,
  reminders; ids status×2 / event×2+1), full estimate (latest `ai_estimates`); `duplicates` = 3 DuplicateFinder hits (none when finished);
  `evaluation.priority_final` null until evaluated; money impact = hours/week × people × 52/12 × area hour value ("$ 1.170.000 al mes", `ai.costs.view` only).
- Estimates (6.4.11): `ai_estimates` (request, `ai_project_id` reserved for B5, hours_total, external_costs, total_cost, due_date, created_by) + `ai_estimate_lines`
  (user or role, hours, hourly_rate snapshot). Rates: new `ai_cost_rates` (user, rate COP, effective_from) read by `App\Domain\Ai\CostRates`; B6 adds its admin page.
  `total_cost` is null while a person has no rate (mirrors `estimateTotals` of the design). Area hour value: new `areas.hour_value` (COP, null = unset).
- SendToApproval: evaluated requests only (size set); hours only for the IT team (`ai.manage` holders = `TriageActionData.team`); approvers only holders of `ai.projects.approve`;
  one pending row per approver with the same `requested_at` (= the round) and the JSON snapshot (`estimate_id`, hours_by_person with rate, totals, due date);
  earlier pending rows are removed; `is_reapproval` = an earlier round was approved. Setting `ai.approval.require_other_if_evaluator`: the evaluator cannot be the only
  approver nor be asked with `require_all` (validation), and cannot approve (policy).
- Approvals: list = pending requests the viewer may decide and has not decided in the current round, newest round first. The index without the permission renders empty
  (the page shows NoPermission, no data leaves); the show without it answers 403. `decision` = the viewer's decision in the round, else the round result once it left approval.
  Reaching "Aprobada" dispatches `App\Events\RequestApproved` (after commit): the B5 hook to create the project.
- Telegram: `AiApprovalRequested::forApproval()` adds ✅ Aprobar / ❌ Rechazar (`ai-approval:{approval_id}:{a|r}:{hmac}`, ≤ 64 bytes, HMAC with the app key) + "Ver detalle"
  (`?desde=telegram`); the summary line has hours and due date, never costs. Handler `App\Telegram\Handlers\ApprovalCallbackHandler` (config `gestor.telegram.callbacks`):
  only the row's approver; already decided = idempotent (message edited with the result); ✅ records channel `telegram` and edits the message; ❌ asks for the reason and the
  next plain-text message of that chat within 15 min is the reason (UpdateHandler hook, `/cancelar` drops it), so rejections keep their mandatory comment.
- Backlog: status "En backlog"; suggested order = roadmap order; manual order = `ai_requests.backlog_position` (one global order: a reorder clears the others) + Settings
  `ai.backlog.manual_order` (by, at, moved_key/above_key = first key that changed place, note null). WIP: Settings `ai.wip_limit` (default 3, now in config defaults),
  counted globally on requests "En desarrollo" until projects exist (B5).
- Por área: active areas of the company in name order; the switch offers the active companies the viewer reaches. Counters: products in production/pilot of the area,
  projects 0 (B5), backlog, hours saved/month from production/closed requests (hours/week × people × 52/12). Semáforo: late = unanswered past due; attention = unanswered
  not yet due, needs info ("espera la respuesta de {nombre}") or pending approval; ok = first backlog item as reason. Owner = area approver or "[RESPONSABLE]".
- Mi semana: due = first responses due by Sunday (Bogotá, overdue included), person = assignee or "TI"; waiting = questions (title + open question), validations
  ("faltan X de Y criterios") and approvals (pending approvers); `blocked`, `running_timer` and `review` stay empty/null until B5/B6.
- Ask: the question goes to the public conversation and the request moves to Necesita información (first response, requester notified); asking again while waiting adds
  the question and sends `AiRequestReminder`. Merge: the duplicate's requester and voters become +1 voters/followers of the one that stays; the duplicate is rejected
  ("Está duplicada", public note, `merged_into_id`). Bulk assign stores `ai_requests.assignee_id` (IT team only). Reminders reuse the requester's
  `ai.request.status_changed` event (no new catalog entry).
- Home `it`: unanswered requests late or due within 24 h (10 max, "GIA-148 · vencida hace 1 día" / "vence hoy"); `pending_approvals` for approvers
  (`cost_cop` = total cost, 0 when unknown or without `ai.costs.view`); tasks/requirements (B5) and credentials (B8) stay empty.

Notes for design:
- No button yet for "Mover al backlog" (`ai.requests.backlog` POST `{ note? }`) or "Crear a nombre de otra persona" (`requester_id` on `ai.requests.store`).
- `ApprovalItem.cost_cop` is a required number: the server sends 0 when the cost is unknown or the viewer lacks `ai.costs.view`; consider `number | null`.
- `ApprovalDetail.evaluation.size` is required: sending to approval now requires a size, so it is always set.
- The backlog reorder has no note field: `ManualOrder.note` is always null.
- `TeamMember.hourly_rate` and estimate costs stay null until rates are loaded (`ai_cost_rates`, admin page in B6).

### B5 · Projects, board, requirements and reports — done (commit `5ec9e15` `feat(ai): add projects, board, requirements and reports`)
- Route: delegated (writer trigger: 60+ files across migration, models, domain, actions, presenters, controllers, policies, notifications, mail/PDF, commands, routes; 7 test files).
- Test-first: 6 new test files + base case and 3 updated (63 tests in the first run) written first and observed RED (43 of 63 failing: `AiProject` and routes missing), then GREEN; area board and demo seeder tests added with their change.
- Checks: `php artisan test` 677/677 passed; `vendor/bin/pint` fixed 4 files, `vendor/bin/pint --test` passed; `npm run typecheck` passed;
  `php artisan route:list --path=ai/projects` shows 25 routes (18 real, 7 TEMPORARY for B6/B7); `schedule:list` shows `0 8 * * * ai:requirement-reminders` (Bogotá) and `*/15 * * * * ai:project-reports`;
  new migration up/rollback/up + `db:seed` + `AiDemoSeeder` on a temp SQLite file OK (1 demo project, 7 tasks; file deleted). `composer require barryvdh/laravel-dompdf` (^3.1).
- Size: ~4880 authored lines (code, docblocks, migration, Blade) + ~1350 test lines, above the ~400 heuristic: projects, board, requirements and the report share one schema and its presenters.

Decisions:
- Schema (one migration): `ai_projects` (key `P-n` from `sequences`, company data, area, objective, status = request keys `planned|in_development|in_testing|in_production|closed`, lead, product, start/due dates, started/approved at, repository url/branch, `reapproval_requested_at`),
  `ai_project_area` (benefited + note), `ai_project_members` (role), `ai_project_request` (one project per request), `ai_project_columns` (the 5 design keys, editable name, `is_done`, `wip_limit`),
  `ai_tasks` (key `T{n}` per project from `sequences` `ai_tasks:{id}`, never reused; position; labels JSON; due date; estimate hours; GitHub issue/PR/CI columns for Stage D; started/completed at),
  `ai_task_assignees`, `ai_subtasks` (checklist rows, not parent tasks: the design `Subtask` is `{id,title,done}`), `ai_task_comments`, `ai_task_attachments` (private disk),
  `ai_requirements` (company data; recipient user or null + label/area; due date; `pending|answered` stored, `overdue` computed in Bogotá; reminders count/last; `blocks_task_id`; answer + one file),
  `ai_project_activities` (feed), `ai_project_reports` (recipients JSON, sections, version, schedule), `ai_time_entries` (origin `timer|manual|telegram`; B5 only reads them), FK `ai_estimates.ai_project_id`.
  No sprints/milestones table: the design has none.
- A task is blocked while an open requirement points at it (`blocks_task_id`); answering clears the block (no stored flag) and notifies the assignees (`AiTaskUnblocked`) unless another open requirement still blocks it.
- Project from requests: `ai.requests.convert` `{name, start_date}` (the design's ConvertToProjectDialog) for "Aprobada", or "En backlog" when its latest approval round was approved
  (`RequestStatusMachine` now allows Backlog → Planned; only this action uses it). It copies company, area, benefited areas, product and objective (expected result, else problem),
  takes due date/approval date from the approved round, links the estimate, team = assignee (else actor) "Líder del proyecto" + estimate people "Desarrollo", default columns, request → "Planificada".
  No listener on `RequestApproved`: TI names and dates the project, as designed. "Jhon creates a project directly" has no design page (not built).
- Lifecycle (MoveTask): first task to "En progreso" starts a planned project ("En desarrollo", `started_at`) and moves its planned requests to "En desarrollo"; it is refused (`column` error) while the
  global WIP is full. All tasks in "Hecho" → project and requests "En pruebas" (requester validates). Production/closing stay manual (no design action yet).
- WIP: `WipLimit` counts projects "En desarrollo" (all companies) instead of requests. Board limit Settings `ai.board.in_progress_limit` (3) is shown, not enforced.
- Moves reorder inside a transaction (project tasks locked), clamp the position and renumber source and target columns 1..n; activity "movió T3 Endpoint de zonas a En revisión".
- Pages/routes exactly as `ai-projects.ts`: `ai.projects.index` (`ai.manage`), `ai.projects.show|board`, `ai.tasks.show` (AiProjectPolicy::view), task actions, requirements, report (`ai.manage` + policy manage).
  Added (not in the contract): `ai.tasks.attachments.show` (signed 30-min link for `FileRef.url`, re-authorized). `ai.tasks.show` is now `{project, task}` (home.ts mock still shows `{request, task}`).
- AiProjectPolicy: view = `ai.manage` with company access, team/lead, task assignees, `ai.area.view` of the project's area (read only); manage = `ai.manage` with company access.
  AiRequirementPolicy: respond/answer/reassign = recipient (any company; binding skips CompanyScope) or IT; remind/create = IT.
- Money: `ProjectBudget` = latest linked estimate vs Σ time entries (hours) and Σ hours × rate in force (cost); `cost` null without `ai.costs.view`; `needs_reapproval` when real hours or cost pass the
  estimate by more than Settings `ai.reapproval_threshold` (0.2); `projected_due_date` = due date until B6 computes the pace. `TaskDetail.time_by_person` is hours only.
- Requirements: "Qué necesitas" → title (first line, 160 chars) + detail (rest); unknown recipient → "[RESPONSABLE {ÁREA}]" with the project's area. Notices reuse the requester event
  `ai.request.status_changed` (mail + bell) for `AiRequirementRequested`/`AiRequirementReminder`, and `alert.info` (bell) for `AiTaskUnblocked`/`AiRequirementAnswered` (config/notifications.php was outside the surface).
  Reminders: `ai:requirement-reminders` daily 08:00 Bogotá, pending with a recipient, due tomorrow or earlier, at most every Settings `ai.requirements.reminder_every_days` (2) days; "Recordar" counts too.
  Respond page: `suggested_user_id` = approver of the requirement's area (when not the recipient); candidates = people of the requirement's company with an area, suggested first. Reassign sends the recipient home.
- Report: content per recipient (`ProjectReportContent`: done = tasks finished in 7 days, next = En progreso then Por hacer, blockers = open requirements). Time and cost only when internal version +
  section "budget" + recipient has `ai.costs.view` + `include_costs` + the saver had `ai.costs.view`. Default recipients = team then requesters. HTML mail (`ProjectReportMail`, company sender) + PDF (dompdf,
  same Blade body); `pdf {pages,size}` rendered on page load. Weekly schedule: `next_send_at` (weekday 1–7 + time, Bogotá), `ai:project-reports` every 15 min. Monthly is not in the design (manual|weekly only).
- Mi semana: due adds open tasks due by Sunday (person = assignees or "TI"); blocked = tasks waiting on a requirement (target requirement → "Recordar a {área}"); waiting adds requirements that block nothing.
  Home `it`: `overdue_tasks` ("P-07 · T3 · vencida hace 3 días" → `ai.tasks.show`) and `waiting_requirements` ("Título · Área", late when overdue → `ai.requirements.show`, which redirects).
- Por área: `projects_in_progress` (En desarrollo/En pruebas) and `AreaProject` rows (note: overdue requirement late, blocked tasks or missing validations attention). Roadmap `planned_start` = project start date.
  `RequestSummary.project_key` is filled. AiDemoSeeder adds GIA-139 with a demo project (7 tasks, a blocking requirement).
- Placeholders kept (TEMPORARY): `ai.timer.start|stop`, `ai.projects.costs|costs.export`, `ai.costs.store`, `ai.time-entries.create|store`, `ai.projects.reapproval.store` (B6), `ai.projects.docs` (B7),
  `ai.reviews.store` (weekly review: no storage designed), plus vault/products/docs/value tabs.

Notes for design:
- `TaskDetail.criteria` is always `[]`: there is no way to link criteria to tasks (no field in `TaskUpdatePayload`).
- No action exists to move a project to "En producción"/"Cerrada" or to edit columns, members, repository or labels catalog; "create project without a request" has no page.
- `mocks/home.ts` uses `href_params: {request, task}` for `ai.tasks.show`; the server sends `{project, task}` (the contract of ai-projects.ts).
- `ReportSchedule.periodicity` is `manual | weekly`; the brief also mentioned monthly (not built).
- `RequirementForRecipient.answer_attachment` has no URL, so the answer file cannot be downloaded from the pages yet.

### B6 · Time and costs — done (commit `b4c7992` `feat(ai): add time tracking, project costs and budget control`)
- Route: delegated (writer trigger: 30+ code files across migration, models, domain, actions, presenters, controllers, form requests, notifications, Telegram, command, routes; 9 test files).
- Test-first: 8 new test files + 1 unit file (48 tests) written first and observed RED (47 of 48 failing: routes, models and classes missing), then GREEN; `StageAShellRoutesTest` updated (B6 placeholders removed).
- Checks: `php artisan test` 725/725 passed; `vendor/bin/pint` + `vendor/bin/pint --test` passed; `npm run typecheck` passed; `route:list --path=ai` 88 routes (timer, time entries, costs, re-approval real)
  and `--path=admin/costs` 6 routes; `schedule:list` shows `0 15 * * 5 ai:hours-reminder` (Bogotá); new migration up/rollback/up + `db:seed` + `AiDemoSeeder` on a temp SQLite file OK (file deleted)).
- Size: ~2810 authored code lines + ~1340 test lines, above the ~400 heuristic: timer, hours, costs, budget rules, alerts and re-approval share ProjectBudget/ProjectCosts and the three pages consume them together.

Decisions:
- Schema (one migration): `ai_timers` (one running timer per person, unique `user_id`, project, task, `started_at`) and `ai_project_costs` (type `license|hosting|external_provider|other` = the design
  `CostType` minus the computed `team`/`ai_usage`; recurrence `once|monthly`; amount COP; date; receipt on the private disk `gestor.ai.attachments.disk`). `ai_time_entries` (B5) unchanged.
  The brief's "equipo" (hardware) type has no design key: use `other`.
- Timer (`App\Actions\Ai\Timers`): start = AiProjectPolicy::logTime (IT with company access, lead, members, task assignees); starting another task stops and logs the running one; same task = no-op.
  Stop → entry origin `timer` rounded to the minute, capped at 16 h (forgotten timer), on the Bogotá date it started, note = task title; runs under 60 s are dropped ("Corrió menos de un minuto").
  `RunningTimer` (board, Mi semana) and `Task.timer` (board, task page) are the viewer's own timer only (`TimerPresenter`), so "Parar" always stops theirs.
- Entries (`App\Actions\Ai\LogTime`, then WatchProjectBudget): manual `ai.time-entries.store` (TimeEntryRequest mirrors useTimeEntryForm: date ≤ today, 0 < hours ≤ 16, note required, task of the project).
  Added (not in the contract): `ai.time-entries.update|destroy {project, entry}` (owner while on the project, or IT). `Ai/TimeEntries/Create`: open projects (planned/in development/in testing) where the viewer
  may log, open tasks in board order, `?project=&task=` preselect only when valid, `telegram_keys` = origin request key per project.
- Telegram `/horas KEY HORAS qué hiciste` (`App\Telegram\Commands\HoursCommand`, UpdateHandler): KEY = request key GIA-n (→ its project) or project key P-n, case-insensitive; hours `2`, `1,5`, `1.5`, `2h`
  (0 < h ≤ 16); note required; today's date; origin telegram; reply "Listo: 2 h en P-1 · … (GIA-…), hoy. Esta semana llevas 12 h." Errors: unlinked chat, usage, hours, missing note, unknown key,
  request not yet a project, not in the team.
- Friday reminder `ai:hours-reminder` (Fridays 15:00 Bogotá): IT (`ai.manage`) + lead/members of planned/in development/in testing projects, active, with no entry Monday–today → `AiHoursReminder`.
- Budget (`ProjectBudget::figures`, `BudgetRules` = PHP twin of `lib/budget.ts` + `projectedEnd`, same test cases in `tests/Unit/Ai/BudgetRulesTest.php`): real cost = Σ of the cost rows (team hours × rate in
  force on the entry date + costs by hand + AI usage), as the page's `costsTotal` adds them, so a monthly cost counts its amount once. Remaining hours = Σ `estimate_hours` of open tasks, else approved − logged.
  Pace = hours of the last 2 weeks ÷ 2; `projected_due_date` = projectedEnd (today when nothing remains), else the due date. `needs_reapproval` (only with an estimate) = hours or cost above the threshold, or
  `scheduleNeedsReapproval` (approval date → due date vs projected) — the schedule rule is new on the server.
- AI spend: `ai_interactions` with source = the project or its product, from the month it was approved (or started), one `ai_usage` row per month, USD → COP with new Settings `ai.usd_cop_rate` (4000, pending
  from Jhon). Computed row ids are negative (`team` −1, AI −YYYYMM).
- Costs page (`ai.manage` + view): without `ai.costs.view` the same keys go out empty (`budget.cost` null, no log, no costs, empty curve) and the page renders NoPermission. Curve: plan approval→due date,
  cumulative real hours per day with entries, projection today→projected end. `pace_note` names other projects where the same people logged hours in the last 2 weeks. Export = CSV (`;`, UTF-8 BOM) of the week
  (no Excel library in the stack). `ai.costs.store` (+ added `ai.costs.update|destroy` and signed `ai.costs.attachment`): AiProjectPolicy::manageCosts (`ai.manage` with company access + `ai.costs.manage`).
- Budget alerts (`App\Actions\Ai\WatchProjectBudget`, after every entry/cost change and after a re-approval): ratio = max(hours, cost); 80 % (≥ 0.8, AlertService `warning`) and 100 % (> 1, `critical`), group key
  `ai.budget:{P-n}:{80|100}`. AlertService is raised with no recipients (record only); the approvers get `AiBudgetAlert` (new event `ai.project.budget`, hours and percentages, no money) and the project feed
  gets a `budget` entry. Nothing is sent while the alert is unresolved; going back below resolves it, so a new crossing alerts again.
- Re-approval (`ai.projects.reapproval.store`, `App\Actions\Ai\RequestReapproval`): only when `needs_reapproval`, the project comes from a request and no round is open. It reuses the B4 approval rows of the
  origin request (`is_reapproval` true, all approvers, `require_all` false) with a new `ai_estimates` row (not linked to the project until approved): logged hours per person + remaining hours on the lead,
  rates today, other real costs as external costs, due = max(projected, current). The request keeps its status; nothing is blocked. `ApprovalRounds::awaiting()` (pending approval or open re-approval) now drives
  `approveProject`, the approvals list, `decisionFor` and the Telegram handler. `RecordApprovalDecision` hands re-approvals to `DecideReapproval`: approve → estimate linked to the project, project due date and
  `approved_at` updated, alerts reset; reject → old estimate kept, comment in the feed. Both clear `reapproval_requested_at`. Approvers get `AiProjectReapprovalRequested` (event `ai.project.reapproval`, same
  ✅/❌ buttons).
- `Admin/Costs/Index` (`admin.costs.*`): page for `ai.costs.view`, `ai.manage` or `admin.access` (403 otherwise); `rates`/`area_values` null without `ai.costs.view`. Rate rows = IT team + anyone with a rate;
  current = latest in force today, history = the other rows newest first with `changed_by`; people without a rate come with `rate: 0`. `admin.costs.rates.update` (`ai.costs.manage`) creates a row from its date
  (same date replaces). Area values = active areas in the company context (company name, area name). Approvers (`ai.manage`): add/remove the role "Aprobador de proyectos" with activity log
  `approver_added|approver_removed`; candidates = active `ai.manage`/`ai.costs.view` holders who do not approve; the last approver cannot be removed. Settings (`ai.manage`): `require_other_approver` →
  `ai.approval.require_other_if_evaluator`, `reapproval_threshold_percent` 1–100 → `ai.reapproval_threshold`.
- Notification catalog: new `ai.requirement.requested` (requested + reminders), `ai.requirement.answered`, `ai.task.unblocked`, `ai.project.budget`, `ai.project.reapproval`, `ai.hours.reminder`; the B5
  notices moved off `ai.request.status_changed`/`alert.info`.
- Placeholders kept (TEMPORARY): `ai.projects.docs`, `ai.reviews.store`, vault/products/docs/value tabs.

Notes for design:
- `RateRow.rate` is a required number: IT people without a rate come with `0` (so "Cambiar tarifa" can set the first one); consider `number | null`.
- No buttons yet for editing/deleting time entries or costs (`ai.time-entries.update|destroy`, `ai.costs.update|destroy`); `ProjectCost.detail` is always null for costs by hand (no field in `CostPayload`).
- Approvers without `ai.manage` have no project page, so `AiBudgetAlert` carries no link; the re-approval notice links the approval.
- The "Exportar" file is CSV (Excel opens it), not .xlsx.
- `lib/timer.ts` has no rounding rule for stored hours: the server rounds to the minute (as `formatHours` shows) and caps a run at 16 h.

Open (pending from Jhon): USD → COP rate for AI spend (`ai.usd_cop_rate`, 4000 placeholder); whether a monthly cost should count once (as the page adds it) or per elapsed month.

### B7 · Products, documentation, search and ratings lifecycle — done (commit `b97ce8d` `feat(ai): add products, documentation, search and ratings lifecycle`)
- Route: delegated (writer trigger: 25 new code files across migration, models, domain, actions, presenters, controllers, policy, notifications, command, routes; 6 test files).
- Test-first: 5 new feature files + 1 unit file + `StageAShellRoutesTest` updated (46 tests) written first and observed RED (36 of 46 failing: routes, `Documents`, models and command missing), then GREEN.
- Checks: `php artisan test` 761/761 passed; `vendor/bin/pint` + `vendor/bin/pint --test` passed; `npm run typecheck` passed; `route:list --path=ai/products` 5 routes (create/edit/incidents.create TEMPORARY),
  `--path=ai/docs` 9 routes, `--path=ai/search` 1 route; `schedule:list` shows `0 9 * * * ai:ratings-lifecycle` (Bogotá); new migration up/rollback/up + `db:seed` + `AiDemoSeeder` on a temp SQLite file OK (file deleted).
  MySQL-only FULLTEXT paths (docs title+body, requests title+problem) not run here (verify on the server).
- Size: ~2590 authored code lines + ~1030 test lines, above the ~400 heuristic: products, docs and search share presenters (`DocSummary` on the product page and in search) and one contract file (`ai-knowledge.ts`).

Decisions:
- Schema (one migration): `ai_documents` (owner = `ai_project_id` XOR `ai_product_id` with real FKs instead of a morph pair: there is no morph map and AI spend already stores class names;
  title, slug unique per owner, template = design `DocTemplate`, `body` = working copy, `visibility` company|it|project, `not_applicable`, `repository_path`, `version` = last published, author/updated_by,
  soft deletes, MySQL FULLTEXT title+body), `ai_document_revisions` (version unique per doc, body, summary, user, created_at), `ai_product_incidents` (no form: always empty today),
  `ai_requests.rating_requested_at|rating_reminded_at|hours_saved_asked_at`.
- Docs model: autosave (`ai.docs.update`, `{markdown, version?}`) writes the working copy only; "Publicar versión" (`ai.docs.publish`, `{summary, version?}`) adds revision n+1; create adds version 1
  ("Primera versión."); restore (`ai.docs.restore {doc, version}`) copies that version into the working copy and publishes it as a new version ("Restaurada la versión N."), nothing is deleted.
  Optimistic concurrency: an optional `version` body field = the version the editor started from; a stale one answers a `markdown` error (the design does not send it yet).
- Secrets guard (`App\Domain\Ai\SecretGuard`, every saved/published body and template): assigned passwords (`password=`, `contraseña:`), assigned API keys/secrets/tokens, any `-----BEGIN … -----` block,
  AWS access keys, `sk-…` and GitHub tokens → 422 "El documento parece tener una contraseña o una llave…". Naming a variable (`DEEPSEEK_API_KEY`) is allowed.
- Templates (`App\Domain\Ai\DocumentTemplates`, keys of `DocTemplate`): ficha, requerimientos y criterios, arquitectura y datos ("nunca contraseñas"), prompts y modelos, registro de decisiones, manual de
  usuario, manual técnico y de despliegue, acta de entrega, blank. Visibility: user manual + delivery record `company`, ficha/requerimientos/decisiones `project`, the technical ones `it`.
  `ConvertRequestToProject` now creates the project base set (ficha, requerimientos, arquitectura, prompts, decisiones, acta) filled with the objective, the origin key and the acceptance criteria.
- Docs pages are IT pages (`ai.manage`, contract "Permissions"); `AiDocumentPolicy::view` also needs the owner in the company context; writing needs `ai.docs.edit` (route + policy).
  `ai.projects.docs` (no design page) renders `Ai/Docs/Index` with only that project's docs (`ai.manage` + AiProjectPolicy::manage). Tree order: products, then projects; owner name, template order, title.
  History: default selection = the version before the current one, against the current; choosing the current compares it with the previous; diff lines go older → newer (`App\Domain\Ai\LineDiff`, LCS,
  remove-all/add-all above 1M cells). `Ai/Docs/Repo` sends `{ connected: false }` (Stage D reads GitHub); `product_slug` = the asked product or the first visible one.
- Products (`ai.manage`): the index filters in PHP over `AiProduct::visibleTo` (`q` on name/stack/owner/slug, `company_id` only in "Todas", `area_id`, `state`; counts ignore only the state filter).
  Show: versions with their requests, open requests of the company context (10, newest first), docs, incidents, `health` `{enabled:false}`, `next_version` null (no planned version stored),
  `requests_from_button_this_month` 0 (the Kit RYR button does not record its origin), `hours_note` from the 30-day answers ("4 h a la semana, según 1 persona que la usa"),
  `cost_note` from hosting + AI provider (null without `ai.costs.view`), `request_notes` = "Proyecto P-n · entrega proyectada 14 nov" (project due date) else "{solicitante} · {estado}".
  Product create/edit and incident create: TEMPORARY `PendingDesignController` (back with "Disponible pronto.").
- Search (`ai.search`, `App\Domain\Ai\KnowledgeSearch`): keywords of `DuplicateFinder::keywords`; MySQL FULLTEXT (docs title+body, requests title+problem), other drivers score the newest 500 in PHP; products in PHP.
  Finished requests are found too. Highlights are UTF-16 `[start,end)` ranges of whole-word prefixes, accent- and case-insensitive (`App\Domain\Ai\Highlighter`); doc snippets are the body around the first match.
  Visibility: requests through CompanyScope (module users see their company's, as the roadmap), products through `visibleTo`, docs only for `ai.manage`. Access: module users (`AiProductPolicy::viewAny`).
  `type_counts` ignore the type filter; `company_counts` (only `companies.view_all`) ignore the company filter, start at 0 for every active company and honour the type filter; 20 hits per type.
  The top-bar `search` goes to `ai.search` for module users (module active), to Colaboradores for `employees.view` without the Gestor, else back with "No tienes dónde buscar todavía.".
- Ratings lifecycle (6.4.5): reaching "En producción" sends `AiRatingRequested` (new event `ai.rating.requested`, with the public note) instead of the status notice when the requester has not rated;
  `ai:ratings-lifecycle` daily 09:00 Bogotá: 3 days unrated → one reminder; 7 days unrated → "Cerrada" with note "Se cerró sin calificación…" (status notice); 30 days after production (in production or
  closed, within 60 days so old deliveries are not asked) without an answer → `AiHoursSavedQuestion` (new event `ai.rating.hours`, links `ai.ratings.hours.create`). The start is the latest move to production.
- Placeholders kept (TEMPORARY): `ai.reviews.store`, `ai.vault.index`, `ai.value.index` (B8), `ai.products.create|edit|incidents.create` (not designed).

Notes for design:
- Autosave/publish accept an optional `version` (the version the editor loaded) for conflict detection; `Ai/Docs/Edit` does not send it yet.
- `ProductDetail.next_version` is always null and `requests_from_button_this_month` always 0 until versions are planned/imported (Stage D) and the Kit RYR button records its origin.
- `ProductIncident` has no create form (`ai.products.incidents.create` goes back with "Disponible pronto."); `ProductHealth` is always `{ enabled: false }` in Stage B.
- Search is open to requesters (requests and products of their company) but `Ai/Search/Index` is drawn in IT mode; docs never reach requesters because the docs pages are IT-only.
- `ai.projects.docs` has no page of its own: it renders `Ai/Docs/Index` filtered to the project (no ProjectLayout header).

Open (app owner): the global `TrimStrings` middleware trims `markdown` (leading indentation and the trailing newline of a doc are lost on autosave); exempting it needs
`$middleware->trimStrings(except: ['markdown'])` in `bootstrap/app.php` (outside this task's surface).

### B8 · Value dashboard and credentials vault — done (commit `b30d020` `feat(ai): add value dashboard and credentials vault`)
- Route: delegated (writer trigger: 25 code files across migration, models, domain, actions, middleware, controllers, presenters, notifications, command, routes, config; 6 test files).
- Test-first: 5 new test files + `TrimStringsTest` + `StageAShellRoutesTest`/`DocumentsTest` updated (41 tests in the first run) written first and observed RED (30 of 41 failing: models, routes, encrypter and command missing; TrimStrings trimming `markdown`/`value`), then GREEN.
- Checks: `php artisan test` 790/790 passed; `vendor/bin/pint` fixed 4 files, `vendor/bin/pint --test` passed; `npm run typecheck` passed; `route:list --path=ai/vault` 6 routes (`ai.vault.edit` TEMPORARY),
  `--path=ai/value` 2 routes (+ `ai.ratings.remind` POST `ai/requests/{request}/rating/remind`); `schedule:list` shows `0 7 * * * ai:vault-reminders` (Bogotá);
  new migration up/rollback/up + `db:seed` on a temp SQLite file OK (file deleted).
- Size: ~2250 authored code lines + ~990 test lines, above the ~400 heuristic: the dashboard (one domain class, controller, PDF) and the vault (key, session, middleware, log, notices, reminders) each need all their parts to be safe or useful.

Decisions:
- Value (`ai.value.index|export`, `can:ai.manage` as the contract says; Leonardo and gerencia need `ai.manage`): `App\Domain\Ai\ValueDashboard`. Scope = requests of the company context (CompanyScope),
  `company_id` only in "Todas", `area_id` = the request's area (unknown ids fall back to null); products = `AiProduct::visibleTo` + company/area pivots.
  Period: last 3/6/12 complete months, `year` = January to last month (January alone in January), Bogotá. Real hours from `ai_hours_saved_reports` (h/week × 52/12), each answer counted
  from the month its request first reached production (else the month it was reported); `current` = last month of the period, `delta` vs the month before ("frente a agosto").
  Satisfaction = `solved_need` of ratings given in the period, `apps` = distinct products (a request without product counts alone). Lead time = days request → first move to production for
  deliveries in the period, `delta` vs the previous period of the same length (0 without deliveries there). Requests by status = every request of the scope today (count desc, then status order).
  `monthly_cost` = Σ `monthly_cost` of products in production/pilot. ROI per product: development cost = `ProjectCosts::total` of its projects (`product_id` or through its requests);
  saving/month = hours × the hour value of each request's area − the product's monthly cost (the page copy says exactly that); payback = cost / saving (1 decimal); only positive savings,
  shortest payback first. `hour_value` = the filtered area's value, else the weighted average (gross saving / hours). Money keys (`monthly_cost`, `roi`, `hour_value`, `payback_months`,
  `cost_note`, `roi_note`) are null without `ai.costs.view`. `metrics` null when the scope has no reports and no ratings in the period → `awaiting_rating` = latest unrated delivery in production.
  `area_notes`: top area ("Ventas ahorra más: 26 h al mes, sobre todo con …") and reported vs the requesters' estimate (only requests with both). `data_note` null.
- Export "Descargar para la junta": PDF (dompdf `loadHTML`, `App\Support\Ai\ValueReportHtml`, escaped, no Blade file: views are outside the surface), same filters and the same money rule.
- `ai.ratings.remind` (added; the empty state calls it): `ai.manage` + `AiRequestPolicy::transition`; only in production and unrated → `AiRatingRequested` reminder + `rating_reminded_at`.
- Vault schema (one migration): `ai_vault_items` (name, `ai_project_id`/`ai_product_id`, environment, type = design `VaultSecretType`, username, `secret_ciphertext`, url, `expires_at`,
  `expiry_note` (design "Falta recibirla"), `rotate_every_days`, `last_rotated_at`, notes, created/updated by, soft deletes) and `ai_vault_access_log` (item + name snapshot, user, action
  view|copy|create|update|delete|denied|unlock|lock, ip, user_agent, channel web|auto|console, created_at). Design actions map: view→viewed, copy→copied, update→changed, create→created,
  delete→deleted, denied→blocked; unlock/lock are not listed on the page.
- Encryption: `App\Support\Vault\VaultEncrypter` = `Illuminate\Encryption\Encrypter` with `services.vault.key` (`base64:` or raw), cipher `services.vault.cipher` (`VAULT_CIPHER`, default
  aes-256-gcm); `VaultKeyException` (Spanish) when the key is missing, has the wrong length or equals APP_KEY. The value is never in `$casts`, not fillable, `#[Hidden]`, no LogsActivity on the
  model; only `AiVaultItem::revealSecret()` decrypts. Tests prove raw DB ≠ plain, `Crypt::decryptString` (APP_KEY) and another vault key fail, `toArray`/`toJson`/collections never carry it.
- Middleware `vault` (`EnsureVaultAccess`) on every vault route: (1) `ai.vault.access` held directly, else `Ai/Vault/Denied` + log `denied` + AlertService `warning` (source `vault`, group
  `ai.vault.denied:{user}:{Bogotá date}`, recipients = holders only); (2) fails closed without a usable key → Inicio with the Spanish reason; (3) auto-lock after 10 min idle (log `lock`,
  channel `auto`), otherwise refreshes; (4) `Inertia::encryptHistory()` + `Cache-Control: no-store, no-cache, private, max-age=0` on every vault response (Denied included).
- Unlock = `ai.vault.reauth` GET `/ai/vault/unlock?item=` behind `reauth:10` (Stage A middleware: stores the intended URL and sends to Microsoft `prompt=login`); back from Microsoft it opens
  the session (`vault.unlocked_at`, `vault.last_used_at`), logs `unlock` and consumes the reauth stamp, so every unlock (also after a lock) needs a new Microsoft sign-in.
  `session.expires_at` = last use + 10 min.
- Reveal `ai.vault.reveal` POST `{item}` (throttle 20/min): locked → back with "La bóveda está cerrada…"; open → renders `Ai/Vault/Index` with `revealed {item_id, value,
  expires_in_seconds: 30}` (never on GET; props are closures so the partial `revealed,access_log` evaluates only those), logs `view`, Telegram notice. Copy `ai.vault.copied` logs `copy`
  and redirects (value never returned). Lock `ai.vault.lock`. `ai.vault.edit` TEMPORARY (PendingDesignController). Store/update/destroy are not typed → no endpoints; the backend is
  `App\Actions\Ai\Vault\ManageVaultItems` (create/update/delete, new value stamps `last_rotated_at`, each logged + noticed) for the future form.
- Notices: `VaultAccessed` (event `vault.accessed`, now with `required_channels` telegram + database) to every holder on view/copy/create/update/delete: "Bóveda: Jhon Castro vio
  DEEPSEEK_API_KEY", body with origin ("Edge en Windows · 10.0.0.5", `App\Support\Vault\AccessOrigin`) and time; never the value. Activity log `ai`, events `vault_{action}` with the item
  name only (added to the audit `sensitive_events`).
- Reminders `ai:vault-reminders` daily 07:00 Bogotá: expiry date and rotation date (`last_rotated_at` else `created_at` + `rotate_every_days`, `App\Domain\Ai\VaultDueDates`) exactly 30, 15
  and 7 days ahead → `VaultReminder` (new event `vault.reminder`) to the holders. Exact-day match: a missed cron day skips that threshold.
- `VaultItem.expires_at` sent to the page = the next due date (expiry or rotation, whichever comes first), so "Rotar en N días" also covers rotations. Home `it` `expiring_credentials`
  (only `ai.vault.access` holders): overdue or due within 30 days, most urgent first, "DEEPSEEK_API_KEY · 12 días" / "· vence hoy" / "· venció hace 2 días", detail "Vencimiento" or
  "Rotación programada", `{item}` param, `late` once past.
- `bootstrap/app.php`: `trimStrings(except: markdown, value, secret, password, password_confirmation, current_password)` (top-level keys only); the B7 autosave test now keeps
  indentation and the trailing newline.

Notes for design:
- The GestorLayout shows the vault tab to every `ai.manage` user; opening it without `ai.vault.access` renders Denied and alerts Jhon (grouped per person and day). Consider hiding the tab
  unless `auth.user.permissions` has `ai.vault.access`.
- There is no form to create/edit/delete credentials (`ai.vault.edit` TEMPORARY), so the vault starts empty; `ManageVaultItems` is ready for it (needs fields for the value, `expiry_note`,
  `rotate_every_days`, project or product).
- `VaultItem.expires_at` carries the next due date (expiry or rotation); the type has no separate rotation date.
- `ValueMetrics.hour_value` is one number while areas have their own values: the server sends the weighted average (or the filtered area's value).
- `ValueIndexProps` has no prioritized backlog (6.4.5 mentions it): it lives in `Ai/Backlog/Index`.
- The value export is a PDF of plain tables (no charts).

## Stage B summary

### What was built
- Requests (B1, B3, B4): wizard with duplicates and +1, keys GIA-n, status machine, first-response deadline alerts, requester pages (mine, show, validate, rate, hours saved, roadmap),
  IT triage (inbox, evaluation, ask, merge, reject, backlog with manual order, by area, Mi semana) and project approval by the "Aprobador de proyectos" role on the web and Telegram (✅/❌).
- Products (B2, B7): the 5 current apps seeded and linked to `monitored_apps`, product pages, versions, docs.
- Projects (B5, B6): projects from approved requests, Kanban with WIP, tasks/subtasks/comments/attachments, requirements to users with reminders, weekly report (mail + PDF), timer, hours
  (manual, timer, Telegram `/horas`), rates, costs, AI spend, budget alerts 80/100 %, re-approval above 20 %, `Admin/Costs/Index`.
- Knowledge (B7): Markdown docs with templates, versions, diff and restore, secrets guard, Gestor search; ratings lifecycle (3/7 days, 30-day hours question).
- Value and vault (B8): value dashboard with ROI and PDF export; credentials vault with its own key, Microsoft re-authentication, 30 s reveal, 10-minute auto-lock, access log, Telegram
  notices, denied-attempt alerts and expiry/rotation reminders.

### How to test on the server
1. Sign in as Jhon (Líder IA + Aprobador de proyectos + vault owner). Inicio shows the IT board; Gestor IA tabs: Bandeja, Backlog, Por área, Proyectos, Productos, Documentación,
   Mi semana, Valor, Bóveda.
2. As a requester (Usuario final): create a request in the wizard, see it in "Mis solicitudes" and the roadmap; as Jhon evaluate it, send it to approval, approve it on the web or in
   Telegram, convert it to a project, move tasks, log hours (timer, manual, `/horas GIA-n 2 nota`), check costs and the weekly report.
3. Move a request to "En producción": the requester gets the rating request; rate it and answer the 30-day hours question, then open "Valor" (with and without `ai.costs.view`).
4. Vault: "Bóveda" as Jhon → "Confirmar identidad con Microsoft" → open; "Ver valor" shows it 30 s and a Telegram notice arrives; 10 minutes idle locks it. As Leonardo (Súper Admin)
   → "Acceso negado" and Jhon gets the alert. Credentials can be loaded only once the item form exists (open question below).
5. MySQL-only paths (FULLTEXT on requests and docs) run only on the server: search a word of a doc and of a request.

### Server steps
- `php artisan migrate --force` (Stage B migrations up to `2026_10_21_000001_create_ai_vault_tables`).
- `php artisan db:seed --force` (roles/permissions, settings, initial users, the 5 products; idempotent). Never `AiDemoSeeder` in production.
- Vault key, own and never APP_KEY (keep a copy in the shared password manager; losing it makes stored values unreadable):
  `php -r "echo 'base64:'.base64_encode(random_bytes(32)).PHP_EOL;"` → `VAULT_KEY=` in `.env` (optional `VAULT_CIPHER=aes-256-gcm`), then `php artisan config:cache`.
- `GESTOR_VAULT_OWNER_EMAIL` = Jhon's email (`ai.vault.access` is granted directly on login/seed by AssignInitialRoles, never by a role).
- Cron `* * * * * php artisan schedule:run` (docs/deploy-cpanel.md). Stage B schedules (Bogotá): `ai:first-response-alerts` */15, `ai:requirement-reminders` 08:00,
  `ai:project-reports` */15, `ai:hours-reminder` Fri 15:00, `ai:ratings-lifecycle` 09:00, `ai:vault-reminders` 07:00; the database queue worker drains every minute.
- `php artisan gestor:telegram-webhook` after deploy so ✅/❌ approvals and `/horas` reach the app.
- `composer install --no-dev` brings `barryvdh/laravel-dompdf` (B5) for the report and value PDFs.

### Environment keys (B1–B8)
- Added in Stage B: `VAULT_KEY` (required by the vault; it fails closed without it), `VAULT_CIPHER` (optional).
- From Stage A, used by Stage B: `GESTOR_SUPER_ADMIN_EMAILS`, `GESTOR_PROJECT_APPROVER_EMAILS`, `GESTOR_VAULT_OWNER_EMAIL`, `GESTOR_SUPPORT_EMAIL`, `GESTOR_ALERTS_RECIPIENT_PERMISSION`,
  `GESTOR_ERROR_PAGES`, `MODULE_AI_ENABLED`, `MICROSOFT_CLIENT_ID`, `MICROSOFT_CLIENT_SECRET`, `MICROSOFT_REDIRECT_URI`, `MICROSOFT_TENANT` (vault re-authentication too),
  `TELEGRAM_BOT_TOKEN`, `TELEGRAM_BOT_USERNAME`, `TELEGRAM_WEBHOOK_SECRET`, `TELEGRAM_REQUIRE_SECRET_HEADER`, `TELEGRAM_PROCESSING`, `QUEUE_CONNECTION=database`, `MAIL_*`.
- Reserved for later stages: `DEEPSEEK_API_KEY` (C), `GITHUB_*` (D).

### Open questions for Jhon
- USD → COP rate for AI spend (Settings `ai.usd_cop_rate`, 4000 placeholder).
- Whether a monthly project cost counts once (as the costs page adds it) or per elapsed month.
- Per product: owner, URL, repository, current version, monthly cost, AI provider/model; areas of Cámaras.
- Hour value of each area (`Admin/Costs/Index`): without it the ROI has no saving for that area.
- How credentials get into the vault until the item form is designed (backend `ManageVaultItems` exists, no screen), and which ones go first.
- Whether gerencia should see "Valor" through its own role (today it needs `ai.manage`).

### Remaining TEMPORARY routes
- `ai.products.create`, `ai.products.edit`, `ai.products.incidents.create`, `ai.vault.edit` → PendingDesignController ("Disponible pronto.").
- `ai.reviews.store` (weekly review, no storage designed) → StageBPlaceholderController.
- Outside the Gestor: `employees.create` (Stage A notice) and `assistant.index` (Stage C).

## Next step
Stacked PRs opened 2026-10-09 on GrupoRyR/GESTOR-TI (one per task, on top of #9): #12 `stage-b/01-requests-products` → #13 `02-requester-pages` → #14 `03-triage-approvals` → #15 `04-design-merge` → #16 `05-projects` → #17 `06-time-costs` → #18 `07-knowledge` → #19 `08-value-vault`.
Then Stage C (Asistente TI): design PR #11 (`claude/diseno-gestor-ia-etapa-c`) also brings the pending Stage B forms (product create/edit, incident, vault item edit).
