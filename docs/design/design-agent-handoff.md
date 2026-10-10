# Design agent · handoff and working memory

Read this first when resuming the design work for Gestor TI RYR (after a context compaction or in a new session). Last updated 2026-10-09.

## 1. Who / what
- **User:** Jhon Castro (Líder IA, Segurex, jhon.castro@segurex.com). Talks in Spanish (Colombia). Approves every design before it is coded.
- **Role of this agent:** the **design agent** from `PROMPT_GESTOR_IA_DISENO.md`, with `PROMPT_GESTOR_IA_APP.md` as context. Both prompts live in the repo root folder `C:\Users\Claude\Documents\PROYECTOS_CLAUDE\PAGINAS_WEB\GESTOR_TI\` (outside git). Another agent (the **app agent**, Laravel) wires the pages; it owns PHP, routes and controllers.
- **Rule of the process:** design one stage ahead of the app agent → mockups on the canvas → Jhon approves → code in the repo → one PR per stage → contract doc for the app agent.

## 2. Where things are
| What | Where |
|---|---|
| Repo | `GrupoRyR/GESTOR-TI` (GitHub). Worktree: `GESTOR_TI\.claude\worktrees\diseno-gestor-ia-a5a314` |
| Design canvas (all mockups) | https://claude.ai/artifact/VpJ8rdFwC3jeCVBiSjfn2m — pages "Sistema de diseño", "Etapa A · Base", "Etapa B · Gestor IA". Board files `project/*.dc.html` (`Main`, `Components`, `ShellDesktop`, `PhoneHome`, `Brands`; `A-*`; `B-*`). Edit by reading the canvas files (`Artifact read`), editing locally and republishing with `root` + `files` |
| Shared canvas pieces | `A-AppNav` (props `active`, `requester`, `company`), `A-AppTopBar` (`company`, `initials`, `selector`), `A-AdminTabs` (`active`), `B-AITabs` (`active`) — imported with `<dc-import>` |
| Logo assets on the canvas | Segurex `/_blob/53195d990514faa5d4f0f304e18be3fa` (dark `/_blob/0ee894101340411901ae9abe9d136c35`), Derivados `/_blob/291db11dd20d02d111f4e9e574375812` (dark `/_blob/fa80719349f45010bd88681dcc6b957b`), Derivados mark `/_blob/e0c67e3fd18951f3d27ee2db6cfaa4dc` |
| Logos in the repo | `resources/brand/` (viewBox-fixed copies of `G:\IT\Segurexgris.svg` and `G:\IT\SYDC.svg`, dark variants, Derivados mark) |
| Portal RYR design system (source we extend) | `PAGINAS_WEB\PORTAL_RYR\resources\css\app.css`, `docs/diseno/` |
| Contracts for the app agent | `docs/design/stage-a-contract.md`, `docs/design/stage-b-contract.md` |
| Task logs (ODD) | `odd/tasks/gestor-ia-frontend-stage-a.md`, `odd/tasks/gestor-ia-frontend-stage-b.md` (Engram mirrors `odd/gestor-ia-frontend-stage-{a,b}/tasks`) |
| Briefs reused for delegation | `docs/design/briefs/stage-b-mockups-brief.md` (canvas writers: format rules + shared mock facts), `docs/design/briefs/stage-b-pages-brief.md` (page writers: contract rules), `docs/design/briefs/build-canvas-page.py` (rebuilds a canvas page index from board sizes) |
| Preview | `npm run dev:preview` → http://localhost:5174 (`.claude/launch.json` "ui-preview"). Frame URL: `frame.html#page=<Inertia name>&variant=<mockfile>:<key>&vp=phone|tablet|desktop&theme=light|dark&brand=example|segurex|derivados|andina` |

## 3. Branches, PRs, commits
- **Stage A** — branch `claude/diseno-gestor-ia-a5a314`, PR [GrupoRyR/GESTOR-TI#8](https://github.com/GrupoRyR/GESTOR-TI/pull/8) → `main` (open). Commits 7e819e3, 6b81f4c, 2a67910, 974e54b, b85b5bd, b78a45d. 15 pages, 124 tests.
- **Stage B** — branch `claude/diseno-gestor-ia-etapa-b`, PR [GrupoRyR/GESTOR-TI#10](https://github.com/GrupoRyR/GESTOR-TI/pull/10) **stacked on #8** (base = Stage A branch). Commits 869d274, 50c3df8, b2c84ca, 23e869c, 6c8d516, 9f259e1 (+ this doc). 34 new pages, 300 tests.
- **Pending action:** when #8 is merged, retarget #10 to `main` (`gh pr edit 10 --base main`) and sync.
- No CI on the repo. Commits: Conventional Commits, **no Co-Authored-By / AI attribution** (user rule).

## 4. Stack and code conventions
- React 19, @inertiajs/react 2, Vite 8, Tailwind 4, TypeScript 7, Vitest 5, markdown-it 15 (html disabled). Fonts Figtree + Bricolage Grotesque (fontsource). No Laravel files: the app agent creates `app.tsx` and the Laravel Vite config.
- Design-owned folders: `resources/css/`, `resources/brand/`, `resources/js/{Components,Layouts,Pages,types,mocks,lib}`, `ui-preview/`, `docs/design/`.
- Page = `export default function X(props: PageProps<XProps>)`, props passed to the layout as `shared`; never `usePage`, never fetch.
- Types in `resources/js/types/pages/*.ts`, each starting with a comment block: every Ziggy route (method, params, body) + permissions. Domain types `types/ai.ts`; shared props `types/shared.ts`.
- Mocks in `resources/js/mocks/*.ts` export `variants: Record<string, MockVariant>` with `page` (Inertia name); gallery auto-discovers them. `mocks/shared.ts` (`sharedFor(userKey)`, users jhon, leonardo, edward, andres, paola, contabilidad), `mocks/ai-fixtures.ts` (all Stage B entities).
- Never name a page prop like a shared prop (`companies`, `auth`, `flash`, `modules`…): admin companies use `admin_companies`, roadmap uses `roadmap_companies`.
- Layouts: `AppLayout` (modules only from `shared.modules`; desktop side nav ≥1024, tablet rail, phone bottom bar + "Más"; `bottomNav` override), `AdminLayout` (tabs incl. "Costos y aprobaciones"), `GuestLayout`, `GestorLayout` (Gestor IA tabs; requester mode without `ai.manage` with bar Inicio · Solicitudes · Hoja de ruta · Perfil), `ProjectLayout` (project header + tabs; renders the h1).
- Pure logic in `lib/` or `Components/ai/**/x.ts` with Vitest tests written first; relative imports in tested files (no `@` alias in Vitest).
- Gotchas: Tailwind v4 conflicting utilities are order-dependent (use wrappers/variants); `sr-only` inside `overflow-x-auto` needs a `relative` scroller; grids holding charts need `grid-cols-1`/`minmax(0,1fr)`; es-CO dates built from `formatToParts` ("7 oct 2026"); no-break spaces in "a. m." and "$ ".

## 5. Decisions (approved by Jhon)
- Design system = **extension of the Portal RYR system**, no new hues. 12 request statuses in 6 families: gray (Recibida, En evaluación) · amber = "le toca a alguien fuera de TI" (Necesita información, Pendiente de aprobación, En pruebas) · blue = TI trabajando (Planificada, En desarrollo) · green (Aprobada, En producción) · slate (En backlog, Cerrada) · red (Rechazada). Always icon + text.
- **Phase rail** (signature element): Evaluación · Aprobación · Plan · Construcción · Entrega.
- Brand per company from `companies[].colors`, accessible button/tint/ink derived in `lib/brand.ts` for light and dark. Brand colors never used for status chips. Logo colors differ slightly from prompt tokens (#FC6600 vs #FF671F, #727376 vs #666666, #163C92 vs #183F94); UI uses the prompt tokens.
- **`ai.projects.approve` only for the "Aprobador de proyectos" role** (Jhon and Edward). Not Súper Admin, not Líder IA (Leonardo cannot approve).
- `ai.vault.access` only Jhon, never a role permission; vault always a dark protected zone, value visible 30 s.
- Requesters never see costs, rates or internal notes. Costs/rates only with `ai.costs.view`.
- One PR per stage; stacked when the previous stage is not merged.

## 6. Mock facts to keep consistent
People: Jhon Castro, Leonardo Herrera (Gerente de TI), Edward Ramanan (`[CARGO]`), Paola Ríos (Ventas, Segurex), Juan Pérez, Andrés Méndez (Compras, Derivados), Mónica Salazar (Tesorería, Derivados), Camila Torres, Diego Restrepo, `[RESPONSABLE CONTABILIDAD]`.
Requests: GIA-148 (aviso pedido bloqueado por cartera, Recibida, posible duplicado de GIA-121), GIA-146 (fichas en PDF, Pendiente de aprobación, 40 h · $ 6.400.000 · 31 oct), GIA-144 (conciliación bancaria, 72 h · $ 11.500.000 · 28 nov), GIA-139 (filtros Visualizador → P-07), GIA-131 (WooCommerce → P-05, bloqueada), GIA-127 (descuentos → P-06, En pruebas), GIA-118 (buscador, En producción), GIA-150 (Andrés, proveedores, Necesita información), GIA-151 (Diego, cartera a Excel), GIA-153 (Mónica, flujo de caja). Full list: `docs/design/briefs/stage-b-mockups-brief.md` and `mocks/ai-fixtures.ts`.
P-07: estimated 120 h / $ 16.000.000 / 31 oct; actual 92 h (Jhon 62, Leonardo 30) / $ 14.200.000; projected 14 nov → re-approval. WIP 3 de 3. Sample rate $ 160.000/h.
Products: Toma Pedidos SAP v2.4.0, Portafolio de productos v1.8.2, Cuentas por pagar v1.2.0, Visualizador de cartera v3.1.0, Cámaras / robotcamara v0.9.3. Monitoring "aún no activo".
Placeholders still open: Edward's job title, Telegram bot name, AI monthly cap, GitHub organization, IT support email, product owners, real rates and area hour values.

## 7. How the work was done (repeat for next stages)
- Mockups: write a common brief (format rules + shared facts) → 4 parallel canvas writers (background agents) on disjoint board files → reconcile collisions (invented keys!) → build the canvas page index with `build-canvas-page.py` → publish all files in one call.
- Code: ODD feature doc + Engram mirror → T1 foundations then one delegated writer per area, **sequential** (single writer), each with exact allowed edit surfaces, verification commands and Key Learnings → parent re-runs tests/typecheck, spot-checks the gallery, commits a work unit per task → contract doc → push + PR.
- Receipt-driven review (RDD) is **off** globally; ordinary checks only.

## 8. Next
- Jhon reviews Stage B in the gallery; merge #8, then retarget #10.
- Pending designs from Stage B: product create/edit form, incident form, vault item edit form.
- **Stage C · Asistente TI**: mockups published 2026-10-09 on canvas page "Etapa C · Asistente TI" (23 boards incl. the 3 pending Stage B forms as `C-ProductForm`, `C-IncidentForm`, `C-VaultItemForm`; brief `docs/design/briefs/stage-c-mockups-brief.md`), waiting for Jhon's approval. Scope: chat with cited sources and suggestions, "no lo tengo documentado", delivered-password card (30 s, copy, two-people disambiguation buttons), knowledge base list and cards by type (sistema, informe, flujo de datos, procedimiento, glosario) with visibility and review date, Excel importer with mapping and preview, `/aprender` drafts to approve, unanswered questions, and Telegram message format examples.
- Then Stage D (GitHub, Brief para Claude, AI suggestions) and E (MCP token screen), plus email templates (HTML/CSS for Blade) and the mini design-system manual (§5.5 of the design prompt).
