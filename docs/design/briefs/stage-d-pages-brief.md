# Stage D pages · common brief for page writers (T1–T4)

Worktree: `C:\Users\Claude\Documents\PROYECTOS_CLAUDE\PAGINAS_WEB\GESTOR_TI\.claude\worktrees\diseno-gestor-ia-a5a314`. Branch: `claude/diseno-gestor-ia-etapa-d`. Windows, Git Bash.
- Do NOT commit.
- Keep browser and Playwright harnesses outside the worktree.
- Delete any `.playwright-mcp/` folder before returning.

## Skills to load before work
- `C:\Users\Claude\.claude\plugins\cache\claude-plugins-official\frontend-design\d182ca456ca0\skills\frontend-design\SKILL.md`

## Read first
- `odd/tasks/gestor-ia-frontend-stage-d.md`, `docs/design/stage-a-contract.md`, `docs/design/stage-b-contract.md`, `docs/design/stage-c-contract.md`.
- Facts and rules: `docs/design/briefs/stage-d-mockups-brief.md`. Reconciled facts:
  - T2 was merged in PR #36, PR #41 (T3 "Endpoint de zonas") is by Leonardo Herrera, Visualizador v3.1.0 was released on 22 sep;
  - T4 has NO issue until the create-issue flow makes #57;
  - the rule-based duplicate warning stays the amber banner (B-RequestIT), while the AI duplicate suggestion uses the neutral suggestion block.
- Existing system:
  - `resources/js/Components/ui/index.ts`, `resources/js/Components/ai/index.ts`
  - `resources/js/Layouts/*.tsx`
  - `resources/js/lib/*.ts`
  - `resources/js/types/shared.ts`, `resources/js/types/ai.ts`
  - `resources/js/mocks/shared.ts` (`MockVariant`, `sharedFor`, `USERS`), `resources/js/mocks/ai-fixtures.ts`
  - Stage C: `resources/js/types/assistant.ts`, `resources/js/Layouts/AssistantLayout.tsx`, `resources/js/Components/assistant/index.ts` (patterns only).
  - after T1 of Stage D: `resources/js/types/github.ts`, `resources/js/types/suggestions.ts`, `resources/js/mocks/github-fixtures.ts`, `resources/js/Components/{github,suggestions,brief}/index.ts`, `resources/js/lib/{brief,githubKeys,issue}.ts`.
- Page patterns to copy:
  - the pages Stage D extends: `resources/js/Pages/Ai/Requests/Show.tsx` (IT view), `Ai/Tasks/Show.tsx`, `Ai/Projects/{Show,Board}.tsx`, `Ai/Products/Show.tsx`, `Ai/Docs/Repo.tsx`, `Admin/Integrations/Index.tsx`
  - their types and mocks: `resources/js/types/pages/{ai-triage,ai-projects,ai-knowledge,admin}.ts`, `resources/js/mocks/{ai-triage,ai-projects,ai-knowledge,admin}.ts`
  - drawers and dialogs: `Components/ui/{Drawer,Dialog}.tsx`, `Components/ai/products/IncidentDrawer.tsx`
- Approved mockups (READ ONLY): `C:\Users\Claude\AppData\Local\Temp\claude\C--Users-Claude-Documents-PROYECTOS-CLAUDE-PAGINAS-WEB-GESTOR-TI--claude-worktrees-diseno-gestor-ia-a5a314\56383c3b-3b7e-452d-b8fe-3de6089fbd86\scratchpad\canvas\project\D-*.dc.html` and the Stage B/A boards they extend (`B-RequestIT`, `B-TaskDetail`, `B-TaskPhone`, `B-ProjectSummary`, `B-ProductDetail`, `B-Kanban`, `A-AdminIntegrations`). Reproduce them faithfully and responsively at 390 / 834 / 1440, in light and dark.

## Contract rules (the app agent wires these)
- **Pages:**
  - Signature: `export default function Name(props: PageProps<NameProps>)`. Pass props to the layout as `shared`. No fetching, no `usePage`.
  - Gestor IA pages use `GestorLayout` / `ProjectLayout`; admin pages use `AdminLayout`. Stage D mostly ADDS sections, dialogs and drawers to existing Stage B pages; extend their props types (optional new props with documented defaults) rather than forking pages.
  - GitHub data arrives as props built by the server from stored webhook events; the page never calls GitHub. External links open in a new tab with an external-link icon and `rel="noopener"`.
- **Types:**
  - `resources/js/types/pages/{file}.ts` starts with a comment block listing every Ziggy route (name, method, params/body) and every permission its pages use.
  - Never name a page prop `companies`, `auth`, `flash`, `modules`, `notifications_unread` or `current_company_id`.
- **Mocks:**
  - `resources/js/mocks/{file}.ts` exports `variants: Record<string, MockVariant<...>>` with `page` = the Inertia component name.
  - Build data from the fixtures; do not duplicate entities.
  - Include every designed variant (states, permission-less, phone-first cases).
- **Forms:** Inertia `useForm` with Laravel-shaped errors. Destructive or irreversible actions confirm with `ui/Dialog` / `ConfirmDialog`.
- **Permissions:**
  - `can()`: `ai.github.manage` for GitHub setup, linking repos, creating issues and repos; `ai.manage` for AI suggestions and briefs; requesters never see suggestions, briefs or GitHub internals.
  - Secrets (webhook secret, tokens) are never props; only `configured: boolean`. The brief never contains vault, credentials, costs, rates or internal notes.
- **Dates:** use `lib/format.ts` (es-CO, America/Bogota). Relative times use `NOW_ISO` from the mocks.
- **Accessibility:**
  - Labels on every control; 44 px targets.
  - Copy actions announce "Copiado" politely; drawers trap focus and return it to the trigger.
  - Focus management in dialogs and drawers.
  - No color-only meaning; the overdue review uses icon + text.
- **New code locations:**
  - Pure logic goes in `resources/js/lib/` or `Components/{github,suggestions,brief}/*.ts`, with Vitest tests written FIRST (RED → GREEN) and relative imports.
  - Page-local components go under `resources/js/Components/{github,suggestions,brief}/` or the existing `Components/ai/{area}/`.
  - Small additive fixes to shared components are allowed; list each one.

## Verification (foreground; report `<command>: <observed result>`)
- `npm run typecheck`
- `npm test` (all existing tests stay green)
- `npm run build:preview`
- Visual check in the gallery of every new page variant at 390 and 1440, light and dark. Use `npm run dev:preview` on a free port other than 5174, or the built `ui-preview/dist`. Fix any layout bugs you find.

## Return
- Status and files.
- The full route list (name, method, params/body) and permissions.
- The variants list.
- Verification results.
- Deviations and facts invented.

The ~400-line size heuristic is advisory, not a cap. Close with `## Key Learnings` (1–5 numbered standalone sentences).
