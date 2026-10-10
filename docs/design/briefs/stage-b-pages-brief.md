# Stage B pages · common brief for page writers (T2–T5)

Worktree: `C:\Users\Claude\Documents\PROYECTOS_CLAUDE\PAGINAS_WEB\GESTOR_TI\.claude\worktrees\diseno-gestor-ia-a5a314` (branch `claude/diseno-gestor-ia-etapa-b`, Windows, Git Bash). Do NOT commit. Keep browser/Playwright harnesses outside the worktree; delete any `.playwright-mcp/` folder before returning.

## Skills to load before work
- `C:\Users\Claude\.claude\plugins\cache\claude-plugins-official\frontend-design\d182ca456ca0\skills\frontend-design\SKILL.md`

## Read first
- `odd/tasks/gestor-ia-frontend-stage-b.md`, `docs/design/stage-a-contract.md`.
- Foundations from T1: `resources/js/types/ai.ts`, `resources/js/mocks/ai-fixtures.ts`, `resources/js/Layouts/GestorLayout.tsx`, `ProjectLayout.tsx`, `AppLayout.tsx`, `AdminLayout.tsx`, `resources/js/Components/ui/index.ts`, `resources/js/Components/ai/index.ts`, `resources/js/lib/*.ts` (priority, budget, timer, countdown, markdown, chart, wizard, status, format, can, route, useClock).
- Stage A page patterns to copy exactly: `resources/js/Pages/Employees/Index.tsx`, `resources/js/types/pages/employees.ts`, `resources/js/mocks/employees.ts`, `resources/js/mocks/shared.ts` (`MockVariant`, `sharedFor`, `USERS`, companies), `resources/js/Pages/Admin/Roles/Index.tsx`.
- Approved mockups (READ ONLY): `C:\Users\Claude\AppData\Local\Temp\claude\C--Users-Claude-Documents-PROYECTOS-CLAUDE-PAGINAS-WEB-GESTOR-TI--claude-worktrees-diseno-gestor-ia-a5a314\56383c3b-3b7e-452d-b8fe-3de6089fbd86\scratchpad\canvas\project\B-*.dc.html` — reproduce them faithfully and responsively (390 / 834 / 1440). Facts: `...\scratchpad\stage-b-brief.md`.

## Contract rules (same as Stage A — the app agent wires these)
- Pages: `export default function Name(props: PageProps<NameProps>)`, pass props to the layout as `shared`; no fetching, no `usePage`. Use `GestorLayout` (tab per section; requester mode for requesters) or `ProjectLayout` for project pages; `AdminLayout tab="costs"` for admin costs.
- Types: `resources/js/types/pages/{file}.ts` starting with a comment block listing every Ziggy route (name, method, params/body) and permissions used by its pages. Never name a page prop `companies`, `auth`, `flash`, `modules`, `notifications_unread` or `current_company_id` (they collide with shared props).
- Mocks: `resources/js/mocks/{file}.ts` exporting `variants: Record<string, MockVariant<...>>` with `page` = Inertia component name; build data from `ai-fixtures.ts` (do not duplicate entities); include the variants designed (empty, error, other company, requester vs IT, permission-less) so the gallery shows every approved board.
- Forms: Inertia `useForm`, Laravel-shaped errors; destructive or irreversible actions confirm with `ui/Dialog`/`ConfirmDialog`.
- Permissions via `can()`: costs/rates only with `ai.costs.view`; vault only with `ai.vault.access`; approve/reject only with `ai.projects.approve`; IT actions with `ai.manage`. Requesters never see costs, rates or internal notes — enforce in types (nullable/optional fields documented) and UI.
- Dates via `lib/format.ts` (es-CO, America/Bogota); timers use `useClock` with mock start `NOW_ISO`.
- Accessibility: labels, 44 px targets, keyboard alternatives for drag, focus management in dialogs/drawers, `aria-live` for timers/autosave where meaningful, no color-only meaning.
- Pure logic new to your pages goes in `resources/js/lib/` or `Components/ai/*.ts` with Vitest tests written FIRST (RED → GREEN), relative imports.
- Page-local components under `resources/js/Components/ai/{area}/`.
- Small additive fixes to shared components are allowed; list each one.

## Verification (foreground; report `<command>: <observed result>`)
- `npm run typecheck`
- `npm test` (all existing tests stay green)
- `npm run build:preview`
- Visual check in the gallery (`npm run dev:preview` on a free port other than 5174, or the built `ui-preview/dist`) of every new page variant at 390 and 1440, light and dark; fix layout bugs you find.

Return: status, files, full route list (name, method, params/body), permissions, variants list, verification, deviations, facts invented. Advisory size heuristic (~400 lines) is not a cap. Close with `## Key Learnings` (1–5 numbered standalone sentences).
