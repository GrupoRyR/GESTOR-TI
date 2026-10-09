# Gestor IA frontend · Stage B (Gestor IA core)

Locator: `odd/tasks/gestor-ia-frontend-stage-b.md` · Engram mirror: `odd/gestor-ia-frontend-stage-b/tasks`

## Objective
Implement the approved Stage B screens (design canvas https://claude.ai/artifact/VpJ8rdFwC3jeCVBiSjfn2m, page "Etapa B · Gestor IA", 52 boards) as React + TypeScript + Tailwind Inertia pages with typed props, realistic mocks and gallery variants, ready for the app agent.

## Problem / why
`PROMPT_GESTOR_IA_DISENO.md` §4 Etapa B and §5–6. Jhon approved the mockups on 2026-10-09. The design agent stays one stage ahead of the app agent.

## Scope (design-owned folders only)
`resources/css/`, `resources/js/Components/**`, `resources/js/Layouts/`, `resources/js/Pages/Ai/**`, `resources/js/Pages/Admin/Costs/**`, `resources/js/types/`, `resources/js/mocks/`, `resources/js/lib/`, `ui-preview/` (only if discovery needs it), `package.json` (one markdown dependency at most), `docs/design/`.
Out of scope: PHP, routes, controllers; Stage C (Asistente), D (GitHub actions, AI suggestions) and E (MCP).

## Constraints
- Reuse the Stage A system (tokens, components, layouts, lib helpers, mock conventions: `variants` with `page`, `sharedFor`).
- Requesters never see costs, rates or internal notes. Costs and rates only with `ai.costs.view`. Vault only with `ai.vault.access` (Jhon), always the dark protected zone. Project approval only with `ai.projects.approve`.
- WIP limit shown; budget alerts at 80 % and 100 %; re-approval above 20 % (configurable).
- Mock facts follow the approved canvas (`scratchpad/stage-b-brief.md` facts + reconciled keys: GIA-150 Andrés proveedores, GIA-151 Diego cartera export, GIA-153 Mónica flujo de caja).
- Phone-first for requester screens, approvals, timer and vault phone; desktop-first for inbox, Kanban, docs, dashboards.

## Delivery strategy
`stacked-to-main` chain: branch `claude/diseno-gestor-ia-etapa-b` is stacked on Stage A (`claude/diseno-gestor-ia-a5a314`, PR #8 still open). One PR for Stage B with base = the Stage A branch; retarget to `main` after #8 merges. Forecast far above 400 authored lines; slices are per-task work-unit commits.

## Tasks
- [x] T1 Foundations: domain types (`types/ai.ts`), Stage B shared mocks (`mocks/ai-fixtures.ts`), GestorLayout (AppLayout + Gestor IA tabs, requester mode without tabs), ProjectLayout (project sub-header + tabs), new ui components (Timeline, ChoiceCard, NumberStepper, ChipSelect, RatingScale, Dialog, Drawer, Timer + CountdownRing, VaultZone, KanbanColumn/Card, SortableList keyboard-accessible, MarkdownEditor + safe preview, LineChart, BarList, StatTile, DiffView). Route: delegated.
- [x] T2 Requester pages (B1–B4): request wizard, submitted, my requests (+ empty), request detail requester view, acceptance validation, rating, hours saved, roadmap. Route: delegated.
- [x] T3 IT triage (B5–B10): inbox, request detail IT view + action dialogs, approvals (phone + desktop + done), backlog, by area, my week. Route: delegated.
- [ ] T4 Projects (B11–B17): project index, summary, board + task drawer, task phone, requirements + respond, time and costs + time entry, report, admin costs and approvers. Route: delegated.
- [ ] T5 Products, docs, value, vault (B18–B22): products, product detail, docs editor/templates/history/repo, search, value dashboard, vault locked/open/denied, states. Route: delegated.
- [ ] T6 Handoff: `docs/design/stage-b-contract.md`, gallery check of every new page, PR. Route: inline.

## Acceptance criteria
- `npm run typecheck`, `npm test`, `npm run build:preview` pass.
- Every Stage B page appears in the gallery with variants (incl. empty/error where designed), in light/dark at 390/834/1440.
- Each `types/pages/ai*.ts` lists route names (method, params) and permissions at the top.

## Checks
Typecheck + Vitest for pure helpers written test-first (priority formula, budget ratios, timer formatting, countdown, wizard validation, markdown sanitizing, chart scales) + preview build + visual readback.

## Progress
- 2026-10-09: branch created from Stage A head (124 tests green); document created.

## Next step
T1 foundations.
