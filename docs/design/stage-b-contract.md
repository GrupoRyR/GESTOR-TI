# Stage B · Frontend contract for the app agent (Gestor IA core)

Builds on [stage-a-contract.md](stage-a-contract.md) (same shared props, layouts, conventions). Approved mockups: design canvas, page "Etapa B · Gestor IA".
**Source of truth = the types files.** Each one starts with every route name (method, params, body) and permission its pages use:

| Types file | Pages (Inertia components) |
|---|---|
| `types/pages/ai-requester.ts` | `Ai/Requests/Create` (7-step wizard), `Ai/Requests/Submitted`, `Ai/Requests/Mine`, `Ai/Requests/Show` (`view: 'requester'`), `Ai/Requests/Validate`, `Ai/Ratings/Create`, `Ai/Ratings/HoursSaved`, `Ai/Roadmap/Index` |
| `types/pages/ai-triage.ts` | `Ai/Inbox/Index`, `Ai/Requests/Show` (`view: 'it'`), `Ai/Approvals/Index`, `Ai/Approvals/Show`, `Ai/Backlog/Index`, `Ai/Areas/Index`, `Ai/Week/Index` |
| `types/pages/ai-projects.ts` | `Ai/Projects/Index`, `Ai/Projects/Show`, `Ai/Projects/Board`, `Ai/Tasks/Show`, `Ai/Projects/Requirements`, `Ai/Requirements/Respond`, `Ai/Projects/Costs`, `Ai/TimeEntries/Create`, `Ai/Projects/Report` |
| `types/pages/admin-costs.ts` | `Admin/Costs/Index` (rates, area hour values, project approvers, re-approval threshold) |
| `types/pages/ai-knowledge.ts` | `Ai/Products/Index`, `Ai/Products/Show`, `Ai/Docs/Index`, `Ai/Docs/Create`, `Ai/Docs/Edit`, `Ai/Docs/History`, `Ai/Docs/Repo`, `Ai/Search/Index` |
| `types/pages/ai-value-vault.ts` | `Ai/Value/Index`, `Ai/Vault/Index`, `Ai/Vault/Denied` |

Domain types shared by all of them: `types/ai.ts`. Example data for every page: `mocks/ai-*.ts` (built from `mocks/ai-fixtures.ts`); open them in the gallery with "Ver props".

## Layouts
- `GestorLayout`: Gestor IA tabs (`ai.inbox.index`, `ai.backlog.index`, `ai.areas.index`, `ai.projects.index`, `ai.products.index`, `ai.docs.index`, `ai.week.index`, `ai.value.index`, `ai.vault.index`). Users without `ai.manage` get **requester mode**: no tabs and a phone bottom bar Inicio (`home`) · Solicitudes (`ai.requests.mine`) · Hoja de ruta (`ai.roadmap.index`) · Perfil (`profile.edit`).
- `ProjectLayout`: project header and tabs `ai.projects.{show,board,requirements,costs,docs,report}` with `{project: key}`. It renders the page `<h1>`.
- `AdminLayout`: new tab "Costos y aprobaciones" → `admin.costs.index` (visible with `ai.costs.view`).

## Rules the backend must enforce (the UI only hides)
- **Costs and rates** only with `ai.costs.view`: money fields come as `null` without it; `Ai/Projects/Costs` and `Admin/Costs/Index` render NoPermission. Report recipients without it never get time/cost.
- **Requesters** (no `ai.manage`) never receive internal notes, rates, cost totals or other people's estimates — the requester `Show` type has no `internal_notes`.
- **Project approval** only `ai.projects.approve` (today Jhon and Edward). Reject needs a comment.
- **Vault** only `ai.vault.access` (Jhon). List items never carry values. `ai.vault.reveal` returns `revealed {item_id, value, expires_in_seconds}` once; respond with `Inertia::encryptHistory()` and no-store caching, log every view/copy and alert by Telegram. Others get `Ai/Vault/Denied` and the attempt is logged.
- **Markdown** is rendered client-side with raw HTML disabled; still never store or send secrets in docs.
- **Timer**: `ai.timer.start {project, task}` / `ai.timer.stop` (one running timer per person). Time entries record `origin`: timer, manual or telegram.
- **Budget**: alerts at 80 % and 100 %; re-approval when over the threshold (default 20 %, `admin.costs.settings.update`).
- **Priority**: the suggested score formula is `lib/priority.ts` (documented in the file); the server should compute the same or send `priority_suggested`.

## Partial reloads used (Inertia v2)
`only:['similar']` (wizard, `{q}`), `['draft_saved_at']` (wizard autosave), `['merge_candidates']` (`{q}`), `['selected']` (approvals), `['selected_area']`, `['selected_task']` (board, `?tarea=`), `['compare']` (doc history), `['saved_at','version']` (doc autosave), `['repo']`, `['selected','access_log','revealed']` (vault).

## Pending (not designed in Stage B)
The product, incident and vault item forms were designed in Stage C (see [stage-c-contract.md](stage-c-contract.md)). AI suggestions and GitHub actions are Stage D (shown disabled). Mock numbers (rates $ 160.000/h, hour values, metrics) are sample data.
