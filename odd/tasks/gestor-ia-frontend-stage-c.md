# Gestor IA frontend · Stage C (Asistente TI) + pending Stage B forms

Locator: `odd/tasks/gestor-ia-frontend-stage-c.md` · Engram mirror: `odd/gestor-ia-frontend-stage-c/tasks`

## Objective
Implement the approved Stage C screens and the three pending Stage B forms as React + TypeScript + Tailwind Inertia pages with typed props, realistic mocks and gallery variants, ready for the app agent. The source is the design canvas https://claude.ai/artifact/VpJ8rdFwC3jeCVBiSjfn2m, page "Etapa C · Asistente TI", 22 boards plus the `C-AssistantTabs` piece.

## Problem / why
`PROMPT_GESTOR_IA_DISENO.md` §4 Etapa C and `PROMPT_GESTOR_IA_APP.md` §5 (6.8 Asistente TI). Jhon approved the mockups on 2026-10-09. The design agent stays one stage ahead of the app agent.

## Scope (design-owned folders only)
- `resources/css/`
- `resources/js/Components/**`
- `resources/js/Layouts/`
- `resources/js/Pages/Assistant/**`
- `resources/js/Pages/Ai/Products/**`
- `resources/js/Pages/Ai/Vault/**`
- `resources/js/types/`
- `resources/js/mocks/`
- `resources/js/lib/`
- `docs/design/`

Out of scope: PHP, routes, controllers, the Telegram bot itself, Stage D (GitHub, AI suggestions) and Stage E (MCP).

## Constraints
- Reuse the Stage A and B system: tokens, ui components, layouts, lib helpers and mock conventions (`variants` with `page`, `sharedFor`).
- **Permissions:**
  - `assistant.use`: the chat.
  - `assistant.knowledge.manage`: cards, import, drafts and unanswered questions.
  - `assistant.credentials` + `credentials.view`: password delivery, today only Jhon and Leonardo.
  - Vault items are never delivered through the chat; the chat only links to the vault.
- **Passwords never pass through the AI.** The credential card value arrives only in its own prop, shows for 30 s with a countdown, and has copy and hide actions. The page uses no-store and history encryption, like the vault.
- **Card visibility:** "Solo TI" or "Toda la empresa". Type icons are neutral and never use status colors.
- **Mock facts:** follow `docs/design/briefs/stage-c-mockups-brief.md`, with these reconciled facts:
  - the import updates K-031, K-024 and K-029;
  - the vault item is `DEEPSEEK_API_KEY` · Gestor TI · producción, with rotation on 19 oct;
  - the password refusal is shown generically.
- **Layout priority:**
  - Phone-first: chat, the draft approval and the knowledge list on phone.
  - Desktop-first: the knowledge base, import, drafts, unanswered questions and the forms.

## Delivery strategy
`stacked-to-main` chain. Branch `claude/diseno-gestor-ia-etapa-c` is stacked on Stage B (`claude/diseno-gestor-ia-etapa-b`, PR #10, which is stacked on #8). There is one PR for Stage C with base = the Stage B branch; retarget it once the lower PRs merge. Slices are per-task work-unit commits.

## Tasks
- [x] T1 Foundations. Route: delegated (2+ non-trivial files).
  - Domain types `types/assistant.ts` and mocks `mocks/assistant-fixtures.ts`.
  - `AssistantLayout`: AppLayout plus the Assistant tabs (Chat · Base de conocimiento · Importar desde Excel · Borradores de /aprender · Preguntas sin respuesta); the management tabs need `assistant.knowledge.manage`.
  - Chat components: message thread, assistant and user messages, sources list, suggestion chips, composer, typing indicator, person picker, credential card with a 30 s countdown and copy, vault link card.
  - `KnowledgeTypeIcon`, `VisibilityBadge` and an import stepper.
  - Pure helpers written test-first: import column auto-mapping, review-date status, and chat message grouping/time labels.
- [ ] T2 Chat pages (C1–C3): `Assistant/Chat` (phone and desktop) with variants for conversation, no answer, person picker, credential, vault link, first open, unavailable, off (admin and other users), no permission, refused and searching. Route: delegated.
- [ ] T3 Knowledge base (C4): `Assistant/Knowledge/Index` (+ phone and overdue filter), `Show`, `Create` and `Edit` (form by type with visibility and review date), plus the types reference. Route: delegated.
- [ ] T4 Import, drafts and unanswered questions (C5–C7): `Assistant/Import/Create` (upload), `Assistant/Import/Mapping`, `Assistant/Import/Preview`, `Assistant/Drafts/Index` (+ phone approval) and `Assistant/Unanswered/Index`. Route: delegated.
- [ ] T5 Pending Stage B forms and the Telegram spec. Route: delegated.
  - `Ai/Products/Create` and `Ai/Products/Edit`.
  - The incident drawer on `Ai/Products/Show` (`Ai/Products/Incidents/Create`).
  - `Ai/Vault/Edit`.
  - `docs/design/telegram-format.md`, the backend's answer and button format examples from `C-TelegramAnswers` and `C-TelegramActions`.
- [ ] T6 Handoff. Route: inline.
  - `docs/design/stage-c-contract.md`.
  - A gallery check of every new page.
  - The PR.

## Acceptance criteria
- `npm run typecheck`, `npm test` and `npm run build:preview` pass.
- Every Stage C page appears in the gallery with its variants, in light and dark at 390, 834 and 1440.
- Each `types/pages/assistant*.ts` lists its route names (method, params) and permissions at the top.

## Checks
- Typecheck.
- Vitest for the pure helpers, written test-first.
- Preview build.
- Visual readback in the gallery.

## Progress
- 2026-10-09: branch created from the Stage B head (`b658f2d`, 300 tests); document created.
- T1 `e3f3567` foundations: types, fixtures, AssistantLayout (+ AppLayout phoneHeader/fullHeight), chat components, credential card, helpers test-first (knowledge, importMapping, chat) — 334 tests. Delegated writer; parent re-ran typecheck + tests.

## Next step
T2 chat pages.
