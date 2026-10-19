# Design agent · handoff and working memory

Read this first when you resume the design work for Gestor TI RYR, after a context compaction or in a new session. Last updated 2026-10-14.

## 0. Status in one line
**All design deliverables are done and merged into `main`.** That covers stages A–E, the three forms left over from Stage B, the email templates, the design-system manual and the confirmed group data. PRs #8, #10, #11, #20, #34, #35, #36 and #46 are merged. The next work only happens on request:
- replace the remaining placeholders when Jhon sends the data;
- adjust contracts if the app agent reports a mismatch;
- design new modules (Monitoreo, Tickets, Inventario, Operación).

## 1. Who / what
- **User:** Jhon Castro (Líder IA, Segurex, jhon.castro@segurex.com). He writes in Spanish (Colombia) and approves every design before it is coded.
- **This agent:** the **design agent** from `PROMPT_GESTOR_IA_DISENO.md`, with `PROMPT_GESTOR_IA_APP.md` as context. Both prompts are in `C:\Users\Claude\Documents\PROYECTOS_CLAUDE\PAGINAS_WEB\GESTOR_TI\`, outside git.
- **The other agent:** the **app agent** (Laravel) works in another session and wires the pages. It owns PHP, routes, controllers, `HandleInertiaRequests` and the root files (`package.json`, `package-lock.json`, `composer.*`, `tsconfig.json`, `vite.config.*`, `.gitignore`).
  - It can message this session (cross-session).
  - Reply by sending to the `from` address of its message.
- **Process:** mockups on the canvas → Jhon approves → code in the repo → one PR per stage → a contract doc for the app agent → Jhon receives a message to forward to the app agent.

## 2. Where things are
| What | Where |
|---|---|
| Repo | `GrupoRyR/GESTOR-TI` (GitHub; `GrupoRyR` is a **user account**, not an org). Worktree: `GESTOR_TI\.claude\worktrees\diseno-gestor-ia-a5a314` |
| Repo rules | `CLAUDE.md` at the repo root. Start every change from an up-to-date `main`; at most about 6 open PRs per agent; run the checks before opening a PR |
| Design canvas (all mockups) | https://claude.ai/artifact/VpJ8rdFwC3jeCVBiSjfn2m — see §2.1 |
| Docs index | `docs/design/README.md` |
| Design-system manual | `docs/design/design-system.md` (group-wide; extends the `ryr-design` skill) |
| Contracts for the app agent | `docs/design/stage-{a,b,c,d,e}-contract.md`, `docs/design/email-templates-contract.md`, `docs/design/telegram-format.md` |
| Email templates | `resources/emails/` (7 templates as .html + .txt, partials, `samples.json`, `README.md` with variables, `emails.test.ts`) |
| Task logs (ODD) | `odd/tasks/gestor-ia-frontend-stage-{a,b,c,d,e}.md`, `odd/tasks/gestor-ia-email-templates.md` (Engram mirrors `odd/<same-name>/tasks`) |
| Briefs reused for delegation | `docs/design/briefs/` (mockup briefs and page briefs per stage, `email-mockups-brief.md`, `build-canvas-page.py`) |
| Logos in the repo | `resources/brand/` (viewBox-fixed copies of `G:\IT\Segurexgris.svg` and `G:\IT\SYDC.svg`, dark variants, Derivados mark) |
| Portal RYR (the base system) | `PAGINAS_WEB\PORTAL_RYR\`, plus the `ryr-design` skill at `~/.claude/skills/ryr-design/` |
| Preview | `npm run dev:preview` → http://localhost:5174 (`.claude/launch.json` "ui-preview"). Frame URL: `frame.html#page=<Inertia name>&variant=<mockfile>:<key>&theme=light\|dark&brand=example\|segurex\|derivados\|andina`. The viewport belongs to the gallery hash, not to the frame URL. The gallery has a "Correos" section |

### 2.1 Canvas
- **Pages:**
  - "Sistema de diseño"
  - "Etapa A · Base" (`A-*`)
  - "Etapa B · Gestor IA" (`B-*`)
  - "Etapa C · Asistente TI" (`C-*`, which includes `C-ProductForm`, `C-IncidentForm` and `C-VaultItemForm`)
  - "Etapa D · Conexiones" (`D-*`)
  - "Etapa E · Conector MCP" (`E-*`)
  - "Plantillas de correo" (`M-*`)
- **Shared pieces:** `A-AppNav` (`active`, `requester`, `company`), `A-AppTopBar` (`company`, `initials`, `selector`), `A-AdminTabs`, `B-AITabs`, `C-AssistantTabs`.
- **Logo blobs:**
  - Segurex `/_blob/53195d990514faa5d4f0f304e18be3fa` (dark `/_blob/0ee894101340411901ae9abe9d136c35`).
  - Derivados `/_blob/291db11dd20d02d111f4e9e574375812` (dark `/_blob/fa80719349f45010bd88681dcc6b957b`).
  - Derivados mark `/_blob/e0c67e3fd18951f3d27ee2db6cfaa4dc`.
- **Local copy:** the scratchpad of session 56383c3b (`…\scratchpad\canvas\project\`) with the builders `build_b.py` … `build_m.py`. Before republishing, re-read `project/canvas.json` with `Artifact read` and compare it with the local copy.
- **Publishing:** `Artifact publish` with `url`, `root` = the canvas folder, `file_path` = `project/canvas.json`, and `files` = only the changed boards.
- **Rendering:** the built-in browser cannot render the canvas, because it needs a claude.ai login. Writers check layout with `scratchpad\preview\shot.sh <Name> <w> <h>` (headless Chrome; `/_blob/` logos do not load there).

## 3. PR history (all merged into `main`)
| PR | What |
|---|---|
| #8 | Stage A: base (login, home, employees, profile, admin) |
| #10 | Stage B: Gestor IA (requests, triage, projects, Kanban, costs, products, docs, value, vault) |
| #11 | Stage C: Asistente TI (chat, knowledge base, import, `/aprender` drafts, unanswered questions) and the product, incident and vault-item forms |
| #20 | Stage D: GitHub, Brief para Claude, AI suggestions |
| #34 | Stage E: MCP connector (`Profile/Mcp`) |
| #35 | Email templates (`resources/emails/`) and the gallery "Correos" section |
| #36 | Design-system manual and `docs/design/README.md` |
| #46 | `[ORG]` → `GrupoRyR`; confirmed data (Edward CEO, bot Ingenierito, AI cap US$ 10, support email). It also removed an accidentally committed vitest cache |

Branch for this doc: `claude/diseno-handoff-update`. Tests on `main` after #46: 598 Vitest, typecheck and build:preview all green.

## 4. Stack and code conventions
- **Stack:** React 19, @inertiajs/react 2, Vite 8, Tailwind 4, TypeScript 7, Vitest 5, markdown-it 15 (HTML disabled). Fonts are Figtree and Bricolage Grotesque (fontsource).
- **Design-owned folders:** `resources/css/` (including `app.css`), `resources/brand/`, `resources/emails/`, `resources/js/{Components,Layouts,Pages,types,mocks,lib}`, `ui-preview/`, `docs/design/`.
- **Pages:** `export default function X(props: PageProps<XProps>)`. Props go to the layout as `shared`. Never `usePage`, never fetch.
- **Types:** `resources/js/types/pages/*.ts`. Each file starts with a header listing every Ziggy route (method, params, body) and every permission.
- **Mocks:** they export `variants` with `page`, and the gallery discovers them automatically. Fixtures:
  - `mocks/shared.ts` (`sharedFor(userKey)`)
  - `mocks/ai-fixtures.ts`
  - `mocks/assistant-fixtures.ts`
  - `mocks/github-fixtures.ts`
- **Naming:** never name a page prop like a shared prop (`companies`, `auth`, `flash`, `modules`…).
- **Layouts:** `AppLayout` (with `phoneHeader` and `fullHeight`), `AdminLayout`, `GuestLayout`, `GestorLayout`, `ProjectLayout` (with a `projectActions` slot) and `AssistantLayout`.
- **Pure logic:** goes in `lib/` or `Components/**/x.ts`, with Vitest tests written first and relative imports (Vitest has no `@` alias).
- **Commits:** Conventional Commits. **No Co-Authored-By and no AI attribution** (user rule). There is no CI; run the checks locally.
- **Gotchas:**
  - **Install first:** run `npm install` before the checks. `main` gets new dependencies from the app agent, for example `laravel-vite-plugin`. Do not commit `package-lock.json` changes; the app owns that file.
  - **Line endings:** files mix CRLF and LF. `sed -i` rewrites CRLF files as LF. Prefer the Edit tool, or Python with `newline=''`.
  - **Cache files:** never commit `node_modules` or vitest cache files, even ones under `resources/js/node_modules/`.
  - **Tailwind and layout:**
    - Tailwind v4 conflicting utilities depend on their order.
    - `sr-only` inside a scroller needs a `relative` scroller.
    - `.md-prose` lives in `@layer components`.
  - **Mock times:** fixtures must not be later than `NOW_ISO` (9:40 a. m.), or `formatRelative` shows "en N min".
  - **Dialogs:** a native `<dialog>` that opens on mount inside a Drawer needs a one-tick delay.

## 5. Decisions (approved by Jhon)
- **The system:** the design system extends the Portal RYR system with no new hues.
  - 12 request statuses in 6 families, always shown with an icon plus text.
  - The phase rail: Evaluación · Aprobación · Plan · Construcción · Entrega.
  - Brand colors per company come from `companies[].colors` through `lib/brand.ts`. They are never used for statuses.
- **Sentence case in every Grupo RYR app** (decided 2026-10-10): nothing in sustained uppercase.
  - The rule is in the `ryr-design` skill and the manual.
  - **The Portal RYR code keeps its existing uppercase labels:** do not change them unless Jhon asks.
- **Permissions:**
  - `ai.projects.approve`: only the "Aprobador de proyectos" role (Jhon and Edward). Leonardo cannot approve.
  - `ai.vault.access`: only Jhon, never through a role.
  - `assistant.use`, `assistant.credentials` and `credentials.view`: Jhon and Leonardo.
  - `ai.mcp.use`: Jhon and Leonardo.
- **Secrets** (passwords, vault values, MCP tokens):
  - They travel only in their dedicated prop, once, with `encryptHistory` and no-store.
  - Passwords never pass through the AI.
- **Requesters** never see costs, rates or internal notes. Costs and rates require `ai.costs.view`.
- **AI suggestions:** a neutral block with Usar / Editar / Descartar, never applied automatically.
  - A rule-based duplicate keeps the amber banner.
  - An AI duplicate card only appears for a request the rules did not flag.
- **The brief for Claude:** built from a template, without AI. It never includes the vault, credentials, costs or internal notes.
- **Emails:** each one is sent from its company's mailbox (`segurex.info@segurex.com`, `derivados.info@derivadoscol.com`).
  - The subject starts with the key.
  - No tracking.
  - Costs appear only inside `@if can_view_costs`.
  - Approver names are variables (`approvers_and`, `approvers_or`).
- **GitHub:** the account is `GrupoRyR`, a user account. The installations page is `https://github.com/settings/installations`. An organization is not required.

## 6. Data
- **Confirmed (2026-10-14):**
  - Edward Ramanan is **CEO**.
  - The Telegram bot is named **Ingenierito**.
  - The monthly AI cap is **US$ 10**.
  - The GitHub account is **GrupoRyR**.
  - The IT support email is **jhon.castro@segurex.com**.
  - The Gestor domain is **ti.segurex.co** (confirmed 2026-10-19; emails stay @segurex.com). Replaced in PR #50; the canvas boards still show `[DOMINIO DEL GESTOR]`.
- **Still open:**
  - The bot's Telegram `@username`: it must end in "bot".
  - `[RUTA EN SAP]`, `[PASOS EN CLAUDE]`, `[TAMAÑO]`, `[FRECUENCIA]` and `[VERSIÓN]`.
- **Not design data:** product owners, IT hourly rates and area hour values. Jhon enters them later in Administración and on each product page; the mocks keep sample values (rate $ 160.000/h).
- **People in the mocks:**
  - Jhon Castro, Leonardo Herrera (Gerente de TI), Edward Ramanan (CEO).
  - Segurex: Paola Ríos (Ventas), Juan Pérez, Diego Restrepo.
  - Derivados: Andrés Méndez (Compras), Mónica Salazar (Tesorería), Camila Torres.
- **Key records in the mocks:**
  - Requests GIA-118 to GIA-153 (see `mocks/ai-fixtures.ts`).
  - Project P-07, Visualizador de cartera (120 h / $ 16.000.000 estimated, 92 h / $ 14.200.000 actual, projected 14 nov, re-approval).
  - Knowledge cards K-0xx (`mocks/assistant-fixtures.ts`).
  - GitHub: PR #41 by Leonardo on T3, PR #36 merged on T2, issue #57 created for T4 (`mocks/github-fixtures.ts`).

## 7. How the work was done (repeat for new modules)
- **Mockups:**
  1. Write a common brief: format rules, shared facts and allowed files.
  2. Run 1–4 parallel canvas writers (background agents) on disjoint boards.
  3. Reconcile facts with the approved boards (keys, PR numbers, dates).
  4. Build the canvas page index with `build_<x>.py`.
  5. Publish once.
  6. Jhon approves.
- **Code:**
  1. Branch from `main`.
  2. Create the ODD feature doc and its Engram mirror.
  3. T1 foundations, then one delegated writer per area, **in sequence**. Each writer gets exact allowed edit surfaces, verification commands and a Key Learnings block.
  4. The parent re-runs typecheck, tests and the build, and spot-checks the gallery with the built-in browser (`preview_start` "ui-preview").
  5. One work-unit commit per task.
  6. Write the contract doc, then push and open the PR.
  7. Give Jhon a message for the app agent.
- **Review:** receipt-driven review (RDD) is **off**; ordinary checks only.
