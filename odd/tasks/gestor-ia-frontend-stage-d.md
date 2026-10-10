# Gestor IA frontend · Stage D (Conexiones: GitHub, Brief para Claude, AI suggestions)

Locator: `odd/tasks/gestor-ia-frontend-stage-d.md` · Engram mirror: `odd/gestor-ia-frontend-stage-d/tasks`

## Objective
Implement the approved Stage D screens as React + TypeScript + Tailwind Inertia pages and additions to existing pages, with typed props, realistic mocks and gallery variants, ready for the app agent. The source is the design canvas https://claude.ai/artifact/VpJ8rdFwC3jeCVBiSjfn2m, page "Etapa D · Conexiones", 14 boards.

## Problem / why
`PROMPT_GESTOR_IA_DISENO.md` §4 Etapa D and `PROMPT_GESTOR_IA_APP.md` 6.4.6, 6.4.7 and 7.2. Jhon approved the mockups on 2026-10-09 with two decisions:
1. **Duplicate warnings:** the rule-based duplicate warning keeps the amber banner, while the AI duplicate suggestion uses the neutral suggestion block.
2. **Task T4:** it starts without an issue in the Stage B mocks (its issue gets created as #57).

## Scope (design-owned folders only)
- `resources/css/`
- `resources/js/Components/**`
- `resources/js/Layouts/`
- `resources/js/Pages/Ai/**`
- `resources/js/Pages/Admin/Integrations/**`
- `resources/js/types/`
- `resources/js/mocks/`
- `resources/js/lib/`
- `docs/design/`

Out of scope: PHP, routes, controllers, the webhook endpoint, and Stage E (MCP).

## Constraints
- Reuse the Stage A–C system. Stage D adds panels, dialogs and sections to existing pages (requests, tasks, projects, products, admin integrations) and does not fork them.
- **GitHub:** `ai.github.manage` for setup, linking repos and creating issues/repos. Never show the webhook secret or tokens, only "Configurado" / "No configurado".
- **Brief para Claude:**
  - Built from a template by the app; it does not use AI.
  - It never includes vault content, credentials, costs, rates or internal notes.
  - The Markdown builder is a pure, tested helper, so the backend can mirror it.
- **AI suggestions:**
  - Only for `ai.manage`; requesters never see them.
  - Always a neutral block with Usar / Editar / Descartar (+ Volver a sugerir) and never applied automatically.
  - Hidden when there is no AI key or the monthly cap is reached (admins see one short note).
- **Facts:**
  - Follow `docs/design/briefs/stage-d-mockups-brief.md`.
  - Reconciled with Stage B: T2 merged in PR #36, PR #41 by Leonardo Herrera, Visualizador v3.1.0 on 22 sep, and T4 has no issue until #57 is created.

## Delivery strategy
`stacked-to-main` chain. Branch `claude/diseno-gestor-ia-etapa-d` is stacked on Stage C (`claude/diseno-gestor-ia-etapa-c`, PR #11). There is one PR with base = the Stage C branch. Slices are per-task work-unit commits.

## Tasks
- [x] T1 Foundations. Route: delegated.
  - Types `types/github.ts` (repo, PR, issue, CI run, deploy, release, webhook event) and `types/suggestions.ts`.
  - Fixtures `mocks/github-fixtures.ts`.
  - Components:
    - `Components/github/*`: CI status, PR/issue rows, release list, external link.
    - `Components/suggestions/*`: SuggestionBlock with accepted/discarded/stale/generating/error states, the size suggestion, and the duplicate card.
    - `Components/brief/BriefPanel`: drawer and phone sheet, section toggles, preview/Markdown, copy/download.
  - Pure helpers written test-first:
    - the brief Markdown builders (request, task, project, initial CLAUDE.md), which omit forbidden data;
    - the issue title/body builder;
    - the `GIA-123` key extraction from title/body/branch;
    - word/line counts.
  - Also: remove T4's issue from the Stage B fixtures.
- [x] T2 GitHub. Route: delegated.
  - `Admin/Integrations/GitHub` (or a section of Index), plus the repo link dialog on `Ai/Products/Show` and `Ai/Projects/Show`.
  - A GitHub activity section on `Ai/Products/Show`.
  - The create-issue dialog on `Ai/Tasks/Show` and the board chips.
  - The release page `Ai/Releases/Show` (notes, AI-drafted user notes suggestion, notify requesters).
  - PR status on the task phone view.
  - Replace the "etapa D" placeholders, including `Ai/Docs/Repo`.
- [x] T3 Brief para Claude. Route: delegated.
  - The BriefPanel wired into `Ai/Requests/Show` (IT view), `Ai/Tasks/Show`, `Ai/Projects/Show` (with the "CLAUDE.md inicial" tab and create-repo), and phone.
- [ ] T4 AI suggestions. Route: delegated.
  - The suggestions inside `Ai/Requests/Show` (IT view): summary, duplicate, criteria, size.
  - The duplicate comparison `Ai/Requests/Compare`.
  - The states (generating, error, off for admins, too short, stale) and phone.
- [ ] T5 Handoff. Route: inline.
  - `docs/design/stage-d-contract.md`.
  - A gallery check.
  - The PR.

## Acceptance criteria
- `npm run typecheck`, `npm test` and `npm run build:preview` pass.
- Every new page, section and dialog appears in the gallery with its variants, in light and dark at 390, 834 and 1440.
- The types headers list routes and permissions.
- The brief builder never outputs vault, credential, cost or internal-note data (tested).

## Checks
- Typecheck.
- Vitest for the pure helpers, written test-first.
- Preview build.
- Visual readback in the gallery.

## Progress
- 2026-10-09: branch created from the Stage C head (`78e1795`, 393 tests); document created.
- T1 `38b9056` foundations: types github/suggestions, github-fixtures, Components github/suggestions/brief, helpers brief/issue/githubKeys test-first (forbidden data tested); T4 issue removed from Stage B fixtures — 436 tests. Delegated writer; parent re-ran typecheck + tests.
- T2 `d39aa41` Admin/Integrations/GitHub, repo link dialog (product + project), product GitHub section, create issue (task page + board), PR card/history, Ai/Releases/Show with notes suggestion + notify; helpers repoLink/releaseNotice/taskGitHub/githubAdmin test-first — 454 tests. Writer swept all variants 390/1440 (+834) light/dark with Playwright; parent re-ran typecheck + tests. Follow-up: .md-prose heading sizes too large.
- T3 `ccfd5f0` BriefPanel wired on request IT view, task page, board drawer, project summary (+ CLAUDE.md tab, create repo route ai.projects.repository.create); .md-prose moved into @layer components with relative headings — 466 tests. Writer checked variants 390/1440; parent re-ran typecheck + tests.

## Next step
T4 AI suggestions.
