# Gestor IA frontend · Stage A (Base)

Locator: `odd/tasks/gestor-ia-frontend-stage-a.md` · Engram mirror: `odd/gestor-ia-frontend-stage-a/tasks`

## Objective
Implement the approved Stage A screens (design canvas https://claude.ai/artifact/VpJ8rdFwC3jeCVBiSjfn2m, page "Etapa A · Base") as React + TypeScript + Tailwind pages for Inertia, with typed props, realistic mocks and a backend-free `ui-preview` gallery, ready for the app agent to wire.

## Problem / why
`PROMPT_GESTOR_IA_DISENO.md` §5–6: design goes one stage ahead of the app agent. The repo is empty (README only); the Laravel project does not exist yet, so the frontend must run standalone in `ui-preview` and merge cleanly later.

## Scope (design-owned folders only)
`resources/css/`, `resources/js/Components/ui/`, `resources/js/Layouts/`, `resources/js/Pages/`, `resources/js/types/`, `resources/js/mocks/`, `resources/js/lib/`, `resources/brand/`, `ui-preview/`, root `package.json`, `tsconfig.json`, `docs/design/`.
Out of scope: PHP, `routes/`, controllers, `HandleInertiaRequests`, Laravel `vite.config` (app agent).

## Constraints
- Extends the Portal RYR design system (Figtree + Bricolage Grotesque, neutral tokens, brand vars, 6 status colors). Approved 2026-10-07.
- Pages never fetch data: everything arrives through props; shared props per contract (§6).
- UI copy in Colombian Spanish; code, props and file names in English.
- `ai.projects.approve` only for the "Aprobador de proyectos" role (not Súper Admin, not Líder IA).
- Light/dark mode, phone-first for requester and phone screens, 44 px touch targets, focus visible, reduced motion.
- Brand logos: `resources/brand/` (viewBox-fixed copies + dark variants + Derivados mark).

## Delivery strategy
`single-pr` — the design prompt requires one pull request per stage. Forecast well above 400 authored lines (scaffold + ~15 components + 13 pages + types + mocks + gallery); slices are per-task work-unit commits inside that PR.

## Tasks
- [x] T1 Scaffold and tokens: package.json, tsconfig, ui-preview Vite config with Inertia/Ziggy shims, `resources/css/app.css` tokens (light/dark, brand vars, status families), brand assets, `lib/` helpers (`can`, `route`, date formatting es-CO / America/Bogota), shared types. Route: delegated (writer trigger: 2+ non-trivial files).
- [x] T2 Design-system components and layouts: Button, StatusChip, PhaseRail, CompanyMark, Panel, DataTable parts, EmptyState/ErrorState/Skeleton, Switch, Checkbox, TextField, Select, Tabs; AppLayout (side nav from `modules[]`, top bar with company selector, phone bottom bar + "Más"), AdminLayout tabs, GuestLayout. Route: delegated.
- [x] T3 Stage A pages + prop types + mocks: Auth/Login, Auth/Emergency, Auth/CompanyNotEnabled, Home, Employees/Index, Profile/Edit, Admin/Companies/Index, Admin/Companies/Create (wizard), Admin/Areas/Index, Admin/Users/Index, Admin/Roles/Index, Admin/Integrations/Index, Admin/Audit/Index. Route: delegated.
- [ ] T4 ui-preview gallery: page × mock-variant list, viewport (phone/tablet/desktop), theme (system/light/dark), brand (Segurex/Derivados/Andina). Route: delegated.
- [ ] T5 Handoff: `docs/design/stage-a-contract.md` (pages, props types, route names, permissions) and short design-system usage notes. Route: inline.

## Acceptance criteria
- `npm run typecheck` and `npm run build:preview` pass.
- Every Stage A page renders in `ui-preview` with its mocks, in light and dark, at 390 / 834 / 1440 px.
- Each `types/pages/*.ts` lists the Ziggy route names it uses; mocks satisfy the types.

## Checks
No runnable test framework exists yet; functional checks are typecheck + preview build + visual readback in the browser pane. Test-first exception: presentational pages without logic; the `can()` and date helpers get small Vitest tests in T1 (RED → GREEN).

## Progress
- 2026-10-07: document created; starting T1.
- 2026-10-07 T1 done (delegated writer). Evidence: `npm test` RED (modules missing) → GREEN 45/45; `npm run typecheck` pass; `npm run build:preview` pass (parent re-ran test + typecheck). Stack resolved: React 19.3, @inertiajs/react 2.3, Vite 8.3, Tailwind 4.3, Vitest 5, TypeScript 7. Deviations accepted: `@types/node` added; no vitest config (type-only `@/` imports); dates built from `formatToParts` ("7 oct 2026"); brand derivation approximates canvas values (Segurex button #CD4B00 vs canvas #C4470F, both ≥ 4.5:1). RDD off → ordinary checks.

- T1 commit: `7e819e3`.
- 2026-10-07 T2 done (delegated writer): 30 ui components + brandScope/utils, AppLayout (side nav ≥1024, icon rail 768–1023, bottom bar + "Más" dialog <768, modules only from `shared.modules`), AdminLayout tabs, GuestLayout; `dark:` custom variant honoring system + `data-theme`; `.brand-scope` switches brand vars per theme. Evidence: typecheck pass, 45/45 tests, preview build pass; parent visual check in browser pane at 1440 and 390, light and dark, "Más" sheet, no console errors. Route names: company.switch, search, notifications.index, profile.edit, logout, home, admin.{companies,areas,users,roles,integrations,audit}.index. Added `.claude/launch.json` (ui-preview on port 5174).

- T2 commit: `6b81f4c`.
- 2026-10-07 T3 done in two delegated runs. Run A (auth, home, employees, profile, errors) commit `2a67910`; run B (7 admin pages) commit `974e54b`. Evidence: typecheck pass; 85/85 tests (helpers test-first RED→GREEN: greeting, telegramExpiry, roleMatrix, companyWizard, brandContrast, auditQuery); preview build pass; writers' headless visual checks at 390/834/1440 light/dark. Accepted deviations: admin companies prop is `admin_companies` (avoids overwriting shared `companies`); wizard uses its own full-screen shell per canvas; Home date computed (2026-10-07 is Wednesday; canvas said Martes); mocks carry `page` field for gallery grouping.

## Next step
T4 ui-preview gallery.
