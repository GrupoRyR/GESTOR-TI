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
- [ ] B3 · Wire requester pages (ai-requester.ts): wizard + duplicates, submitted, mine, show (requester view), validate acceptance, ratings + hours saved, roadmap. Replace Stage B placeholder routes.
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

## Next step
B3 · Wire requester pages (ai-requester.ts); the `?producto={slug}` entry uses `ProductResolver::forUser`.
