# Gestor TI design system · mini manual

How to build screens that look and behave like the Gestor TI, in this app and in the other Grupo RYR apps. It covers the tokens, the status language, the reusable patterns and the agreement between the design agent and the app agent. **The code is the source of truth:** every token, component and helper named here exists in this repository.

## 1. What this is

| Question | Answer |
|---|---|
| What | The Gestor TI extension of the **Portal RYR** design system: same neutrals, fonts, radii and brand mechanics, plus a status language, protected zones, AI and GitHub patterns. |
| Who | Anyone building a page for Gestor TI or a new Grupo RYR app (Segurex Latam, Soluciones y Derivados). |
| Stack | React 19, Inertia 2, Tailwind 4, TypeScript, Vitest. Laravel serves the pages. |
| See it live | `npm run dev:preview` → http://localhost:5174, sidebar "Sistema de diseño". |

### Inherited vs. extended

The base rules live in the `ryr-design` skill (`~/.claude/skills/ryr-design/`): `references/marca.md` (official colors, logos, typography), `references/componentes.md` (Portal patterns), `assets/tokens.css` and `assets/tailwind-theme.css`. Do not repeat them here; follow them.

| Inherited from Portal RYR (unchanged) | Extended or overridden by Gestor TI |
|---|---|
| Neutral tokens light/dark, status tokens, `viz-1..3`, radii | `.vault-zone` (always-dark protected zone) and `.vault-stripe` |
| `--brand-*` variables, never a company hex in a component | `.brand-scope` with both light and dark brand sets inline (`brandScopeStyle`) |
| Figtree + Bricolage Grotesque, self-hosted with Fontsource | 12 request statuses in 6 families, phase rail, semáforo, talla, budget bar |
| 44 px touch targets, AA contrast, dark mode, reduced motion | `.md-prose` for rendered Markdown, `tabular` utility |
| Spanish UI with tú; code in English; **sentence case, no ALL CAPS** (group-wide rule since 2026-10-10; the `ryr-design` skill was updated) | AI suggestion block, Brief para Claude and GitHub reference patterns |

## 2. Quick start for a new app

1. **Install** Tailwind 4 (`@tailwindcss/vite`), `@fontsource-variable/figtree` and `@fontsource-variable/bricolage-grotesque`.
2. **Copy the files** below.
3. **Send `companies[].colors`** from the backend; the brand flows by itself (next table).
4. **Run the gallery** and check both brands, light/dark, 390 / 834 / 1440.

| Copy | From | Why |
|---|---|---|
| Tokens, dark mode, layers | [`resources/css/app.css`](../../resources/css/app.css) | `@theme` tokens, `dark:` variant, `.brand-scope`, `.vault-zone`, `.md-prose`, `tabular` |
| Brand derivation | [`resources/js/lib/brand.ts`](../../resources/js/lib/brand.ts) + `brand.test.ts` | Accessible button/tint/ink per scheme |
| UI kit | [`resources/js/Components/ui/`](../../resources/js/Components/ui/index.ts) | Buttons, fields, chips, dialogs, states, charts |
| Shell pattern | [`resources/js/Layouts/AppLayout.tsx`](../../resources/js/Layouts/AppLayout.tsx) | Nav built from `modules[]` |
| Formatting and permissions | [`lib/format.ts`](../../resources/js/lib/format.ts), [`lib/can.ts`](../../resources/js/lib/can.ts) | es-CO dates and money; UI-only permission check |
| Shared props | [`resources/js/types/shared.ts`](../../resources/js/types/shared.ts) | The contract with Laravel's `HandleInertiaRequests` |
| Gallery | [`ui-preview/`](../../ui-preview/main.tsx) | Backend-free preview of every page × mock |

### How brand color flows

```text
companies[].colors {primary, accent, dark}
  → brandScopeStyle(colors)            Components/ui/brandScope.ts (memoized per color triple)
      → brandStyle(colors, 'light' | 'dark')   lib/brand.ts
  → inline --brand-light-* and --brand-dark-* on the .brand-scope element (AppLayout, CompanyMark)
  → app.css picks the set for the active scheme as --brand-*
  → utilities bg-brand-button, text-brand-button-ink, bg-brand-tint, text-brand-ink, bg-brand-mark …
```

| Derived variable | Light | Dark |
|---|---|---|
| `--brand-button` | primary, darkened in OKLCH until white text ≥ 4.5:1 | brightest of primary/accent, lightened until ≥ 4.5:1 on `#121315` |
| `--brand-button-ink` | `#FFFFFF` | `#121315` |
| `--brand-tint` | L 0.96, low chroma, same hue | L 0.27, low chroma, same hue |
| `--brand-ink` | darkened until ≥ 7:1 on the tint | lightened until ≥ 7:1 on the tint |

- Without a company the `@theme inline` fallbacks apply: a neutral, unbranded shell.
- Brand shell company = the selected company, or the user's own in "Todas" (`brandCompany` in `AppLayout.tsx`).
- Dark mode follows the system. `data-theme="light|dark"` on `<html>` forces one; never add a `dark` class.

## 3. Foundations

### Color tokens

Use the utility (`bg-surface`, `text-fg-soft`, `border-line`), never the hex. Dark values are redefined, not inverted.

| Token | Use | Light | Dark |
|---|---|---|---|
| `ground` | Page background | `#f5f5f2` | `#121315` |
| `surface` | Cards, bars, panels | `#ffffff` | `#1c1d20` |
| `subtle` | Table headers, quiet fills, hover | `#f0f0ec` | `#26272b` |
| `line` | 1 px borders | `#e5e5e0` | `#2c2e33` |
| `line-strong` | Control borders, dividers that must show | `#d6d6d0` | `#3a3c42` |
| `fg` | Main text, dark buttons | `#17191c` | `#f2f2ef` |
| `fg-soft` | Secondary text | `#45484e` | `#c9cbd0` |
| `fg-muted` | Meta, hints | `#5e6168` | `#a3a6ad` |
| `on-fg` | Text on `bg-fg` | `#ffffff` | `#121315` |
| `viz-1` / `viz-2` / `viz-3` | Chart marks only, never text | `#2a78d6` · `#eb6834` · `#1baf7a` | `#3987e5` · `#d95926` · `#199e70` |

### Typography

| Role | Font / size | Notes |
|---|---|---|
| Headings `h1–h3` | `font-display` (Bricolage Grotesque), letter-spacing −0.02em | Set in `@layer base` |
| Interface | `font-sans` (Figtree) | Body default |
| Page title | `text-xl` → `md:text-2xl` (ProjectLayout); Home 28 → 32 px (`text-[28px] md:text-[32px]`) | One `h1` per page |
| Body | `text-sm` (14) in dense UI, 15 px in `.md-prose`, `text-base` in forms | |
| Meta / labels | `text-[13px]`, `text-xs` (12) | Sentence case, never uppercase |
| Numbers that align | `tabular` utility | Amounts, counts, times, keys (`GIA-148`) |

### Spacing, radii, elevation, motion

| Topic | Rule |
|---|---|
| Spacing | Tailwind 4 px scale; Portal steps 4 · 8 · 12 · 16 · 20 · 24 · 32 · 40 |
| Radii | `rounded-chip` 10 · `rounded-control` 12 · `rounded-icon` 14 · `rounded-card` 18 |
| Elevation | Flat by default: `bg-surface` + `border-line`. `shadow-card` only for a raised card on hover |
| Motion | `transition-colors duration-150`. No decorative animation |
| Reduced motion | Global rule in `app.css` sets animations to 0.01 ms and transitions to 0 s; do not override it |
| Focus | One `:focus-visible` outline (2 px `fg`, offset 2 px); inside `.vault-zone` it turns light |

### Iconography

- `Icon` from `Components/ui`: inline stroke SVG, 24 × 24 grid, 2 px round strokes, `currentColor`, default `size` 20.
- Decorative by default (`aria-hidden`). Pass `label` only when the icon carries meaning alone.
- Names are typed (`IconName`, `ICON_NAMES`, `isIconName`). Groups: navigation (`home`, `ai`, `assistant`, `employees`, `admin`, `monitoring`, `tickets`, `inventory`, `operations`), actions (`plus`, `edit`, `copy`, `download`, `upload`, `refresh`, `send`, `move`, `grip`), status (`inbox`, `search`, `question`, `hourglass`, `check-circle`, `x-circle`, `calendar`, `code`, `clipboard-check`, `signal`, `archive`, `list`, `blocked`), security (`lock`, `key`, `shield`, `eye`, `eye-off`), AI and GitHub (`sparkle`, `power`, `branch`, `commit`, `merge`, `pull-request`, `issue`, `rocket`), knowledge (`kb-system`, `kb-report`, `kb-flow`, `kb-procedure`, `kb-glossary`).
- Need a new one? Add the path to `ICONS` in `Icon.tsx` on the same grid. No icon fonts, no emoji.

### Breakpoints and touch

| Width | Gallery frame | What changes |
|---|---|---|
| < 768 | Celular 390 × 844 | Bottom bar (3 modules + "Más"), drawers full screen, cards instead of tables |
| ≥ 768 (`md`) | Tablet 834 × 1194 | 84 px icon rail, top bar 68 px |
| ≥ 1024 (`lg`) | — | 248 px side nav with labels, search in the top bar, Guest aside |
| ≥ 1280 (`xl`) | Escritorio 1440 × 900 | Extra columns: knowledge table, chat source preview, `max-w-[1280px]` detail pages |

- **Touch targets:** every control ≥ 44 × 44 px: `size-11`, `h-11` or `min-h-11` (`--spacing-touch` is also available).
- No action only on hover. Grids that hold charts use `grid-cols-1` or `minmax(0,1fr)`.

## 4. Status language

**Rule:** status is always **icon + text**; the color only groups. The brand color is never a status color.

### The 6 families

| Family (`StatusFamily`) | Meaning | Token | Light text / bg | Dark text / bg |
|---|---|---|---|---|
| `gray` | Just arrived, being looked at | `status-neutral` | `#45484e` / `#f0f0ec` | `#c9cbd0` / `#26272b` |
| `amber` | Waiting on someone outside TI | `status-maintenance` | `#7a4f00` / `#fff4db` | `#f5d58a` / `#2a2418` |
| `blue` | TI is working on it | `status-scheduled` | `#1e4fa8` / `#e8eefa` | `#a9c1f5` / `#1b2540` |
| `green` | Approved or live | `status-active` | `#1f7a4d` / `#e6f5ec` | `#5fd39b` / `#12332a` |
| `slate` | Parked or finished | `status-local` | `#334155` / `#e6eaf0` | `#cbd5e1` / `#262b33` |
| `red` | Rejected or failing | `status-failure` | `#b42318` / `#feeeec` | `#ff8f84` / `#3a1d1a` |

Class names per family: `FAMILY_TONE` in `Components/ui/utils.ts`.

### The 12 request statuses

Source: `REQUEST_STATUSES` in [`lib/status.ts`](../../resources/js/lib/status.ts); icons: `STATUS_ICONS` in `StatusChip.tsx`.

| Key | Label | Family | Phase | Icon |
|---|---|---|---|---|
| `received` | Recibida | gray | 1 Evaluación | `inbox` |
| `evaluating` | En evaluación | gray | 1 | `search` |
| `needs_info` | Necesita información | amber | 1 | `question` |
| `pending_approval` | Pendiente de aprobación | amber | 2 Aprobación | `hourglass` |
| `approved` | Aprobada | green | 2 | `check-circle` |
| `backlog` | En backlog | slate | 2 | `list` |
| `rejected` | Rechazada | red | 2 | `x-circle` |
| `planned` | Planificada | blue | 3 Plan | `calendar` |
| `in_development` | En desarrollo | blue | 4 Construcción | `code` |
| `in_testing` | En pruebas | amber | 4 | `clipboard-check` |
| `in_production` | En producción | green | 5 Entrega | `signal` |
| `closed` | Cerrada | slate | 5 | `archive` |

### Related indicators

| Indicator | Component | Rule |
|---|---|---|
| Status | `StatusChip` (`size` `sm` / `md`) | Icon + label from `lib/status.ts` |
| Phase rail (signature) | `PhaseRail` (`compact` rows, `bar` cards, `full` with labels) | Evaluación · Aprobación · Plan · Construcción · Entrega. Done = text color, current = family color, unreachable after a rejection = dashed |
| Semáforo | `Semaphore` (`ok` / `attention` / `late`) | Shape per level (circle, triangle, square) + "Al día" / "Atención" / "Atrasada" |
| Talla | `SizeTag` (`S` / `M` / `L` / `XL`) | Letter + 1–4 rising bars |
| Priority | Text "Prioridad 72" or "Prioridad: sin evaluar" | 0–100 from `suggestedPriority` in `lib/priority.ts`; TI can override (`priority_final`) |
| Budget | `BudgetBar`, `budgetLevel` | Marks at 80 % and 100 %: < 80 neutral · 80–100 warning · > 100 over. Logic in `lib/budget.ts` (`budgetState`, `needsReapproval`, default threshold 20 %) |
| CI result | `CiStatusLabel` | Icon + words |
| Generic label | `Chip` (`tone` = family or `outline`) | Never brand colors |

## 5. Patterns

Each pattern: **when** → **rules** → **where**. Components come from `@/Components/ui` unless noted.

### App shell and modules nav

- **When:** every signed-in page.
- **Anatomy:** phone top bar (60 px) + bottom bar; tablet rail (84 px); desktop side nav (248 px) + top bar with search, notifications, company selector.
- **Rules:**
  - The menu is built **only** from `shared.modules` with `active: true`. A new module = a new `modules[]` entry (`key`, `name`, `icon`, `route`) and an `Icon` name. No redesign.
  - Phone shows the first 3 modules + "Más"; `bottomNav` replaces the bar with exact items (requester mode).
  - The company selector shows only with `companies.view_all`.
- **Where:** `Layouts/AppLayout.tsx` (`phoneHeader`, `fullHeight`, `bottomNav` props).

### Tab layouts

| Layout | Tabs | Permission rule |
|---|---|---|
| `GestorLayout` | Bandeja · Backlog · Por área · Proyectos · Productos · Documentación · Mi semana · Valor · Bóveda | All need `ai.manage`; Bóveda also `ai.vault.access`. Without `ai.manage`: requester mode, no tabs, bar Inicio · Solicitudes · Hoja de ruta · Perfil |
| `ProjectLayout` | Resumen · Tablero · Requerimientos · Tiempo y costos · Documentación · Informe | "Tiempo y costos" only with `ai.costs.view`. Renders the page `h1` |
| `AdminLayout` | Empresas · Áreas · Usuarios · Roles y permisos · Integraciones · Costos y aprobaciones · Auditoría | Costs tab with `ai.costs.view` |
| `AssistantLayout` | Chat · Base de conocimiento · Importar desde Excel · Borradores de /aprender · Preguntas sin respuesta | Management tabs need `assistant.knowledge.manage`; a single tab is never shown |

- `Tabs` are page-level links with an underline (`tone="brand"` uses `border-brand-button`) and scroll sideways on phone.
- The layouts filter tabs by permission: the user only sees tabs they can open.

### Lists and tables

- **When:** more than a handful of comparable rows.
- **Rules:**
  - Desktop: `DataTable` (ARIA table roles on CSS grid, 40 px header, rows ≥ 56 px, `rowHref` makes the whole row one link). It scrolls sideways below `minWidth`.
  - Phone: render two-line cards instead, one link per row (example: `KnowledgeList`, table from 1280 px, cards below).
  - Paginate with `Pagination` (Laravel `Paginated<T>` links and meta: "1–8 de 214"). Filters are a GET query with `preserveState`.
- **Do:** keep the key (`GIA-148`) `tabular` and bold. **Don't:** squeeze 8 columns into 390 px.

### Forms

- **Rules:**
  - Inertia `useForm`; errors are Laravel's `Record<string, string>` (`FormErrors`), shown under the field by `Field`.
  - Label above, then control, error and hint (`Field`, `TextField`, `TextArea`, `Select`, `Checkbox`, `Switch`, `ChipSelect`, `ChoiceCardGroup`, `NumberStepper`, `FileDrop`, `ColorField`).
  - Mark optional fields with `Field optional` ("(opcional)"), not the required ones.
  - `Button type="submit"` with `loading`. Variants: `primary` (brand), `secondary`, `quiet`, `danger`, `success`, `dark`, `vault`.
  - Destructive or audited actions go through `ConfirmDialog` (`tone="danger"`). Very destructive ones (vault item) ask to type the exact name.
- **Where:** `Components/ui/Field.tsx`, `Button.tsx`, `Dialog.tsx`.

### Drawers and dialogs

| Component | When | Behavior |
|---|---|---|
| `Dialog` (`sm` / `md` / `lg`) | Short decision or small form | Native modal `<dialog>`: Esc, close button and backdrop close it; focus returns to the opener |
| `ConfirmDialog` | Yes/no on destructive actions | Secondary "cancel" + `danger` or `primary` confirm |
| `Drawer` (default 560 px) | Detail next to a list (task, incident, brief) | Right side; full screen below 768 px |
| `useModalDialog` | Custom modal | Same focus handling |

Show-once dialogs (MCP token) close only by an explicit action, not Esc or backdrop.

### Empty, loading, error and no-permission states

| State | Component | Copy rule |
|---|---|---|
| Empty | `EmptyState` (`StateTile` icon) | Say why it is empty and the next action ("Aún no tienes…") |
| Loading | `Skeleton`, `SkeletonRows`, `SkeletonBoard` | Same shape as the real content; `label` "Cargando…" |
| Error | `ErrorState`, `Alert tone="danger"` | Announced immediately; offer "Reintentar" |
| No permission | `NoPermission` | Say who has access, link home |
| Server flash | `FlashMessages` | `flash.success` / `flash.error`, dismissible |

### Timer and countdowns

- **Timer:** `TimerButton` and `Elapsed` (`Components/ai`). The server stores `started_at`; the client derives the time (`lib/timer.ts`: `elapsedSeconds`, `formatElapsed` "01:12:40", `formatElapsedSpoken` for `aria-label`). One running timer per person.
- **Countdown:** `CountdownRing` + `lib/countdown.ts` (`secondsLeft`, `formatCountdown` "24 seg" / "9:12", `countdownSpoken`). The server decides the deadline. The ring is decorative; the text says what ends and when.

### Protected zone and show-once secrets

**Rule:** a secret is visible once, briefly, and never stored by the client.

| Piece | Where | Rules |
|---|---|---|
| Protected zone | `VaultZone` (`Components/ai`), `.vault-zone` + `.vault-stripe` | Always dark in both themes; amber hazard stripe; lock tile; `Button variant="vault"` for the main action |
| Vault reveal | `Components/ai/vault/useRevealedSecret.ts` | Value shown 30 s, then dropped and the `revealed` prop wiped; vault locks after 10 min |
| Delivered password | `CredentialCard`, `useRevealedCredential` (`Components/assistant`) | Hidden by default; "Mostrar" for 30 s with ring, "Copiar" / "Ocultar"; delivered by the Gestor, never by the AI |
| MCP token | `CreatedTokenDialog` (`Components/mcp`) | Full value only once, after creation; then only the last 4 characters |

- The server responds with `Inertia::encryptHistory()` and `Cache-Control: no-store`.
- Never put a secret in a URL, a log, `console`, a toast or an audit entry.
- Every view and copy is logged and alerted by Telegram (backend).

### AI suggestion block

- **When:** the AI proposes a value (duplicate, size, acceptance criteria, release notes).
- **Anatomy:** `SuggestionBlock` = dashed gray border (`SUGGESTION_SURFACE`), `SparkleTile`, header "Sugerencia de IA · …", content, actions.
- **Rules:**
  - **Neutral look**, never status colors, so it is not read as a warning or a decision.
  - Actions "Usar" · "Editar" · "Descartar". Nothing applies on its own: "Usar" hands the value to the page, which saves it as the person's own.
  - After use: `AcceptedTrace` ("Basado en una sugerencia · …", "Ver original").
  - Off states: `AiOffNote`, one short note for admins only ("Las sugerencias están apagadas.", link "Ir a Integraciones"). Everyone else sees nothing; the rest of the page keeps working.
- **Where:** `Components/suggestions/`.

### Brief para Claude

- `BriefTrigger` opens `BriefPanel`: drawer (~560 px) on desktop, full-screen sheet on phone.
- Built on the client from a template (`lib/brief.ts`); no AI call, no route. Copy and download are client-only.
- Never includes vault, credentials, costs, rates, hours or internal notes; the builders drop them and tests cover it.
- **Where:** `Components/brief/`.

### GitHub references

| Need | Component (`Components/github`) |
|---|---|
| Link out | `ExternalLink`: new tab, `rel="noopener noreferrer"`, external icon, screen-reader hint "abre en GitHub" |
| Which request/task a PR moves | `GitHubKeyChip` ("GIA-139 · T3 Endpoint de zonas") |
| Compact card ref | `GitHubRefChip` ("PR #36 · fusionado") |
| Rows | `PullRequestRow`, `IssueRow`, `CommitList`, `ReleaseList`, `RepoHeader` |

Data comes from props built from stored webhooks; the page never calls GitHub. Linking key rules: `lib/githubKeys.ts`.

### Markdown

- Render only through `MarkdownPreview` (`lib/markdown.ts`, markdown-it with `html: false`): raw HTML escaped, unsafe URLs refused, links open in a new tab with `noopener noreferrer`.
- Styles: `.md-prose` (15 px base, headings in em). Edit with `MarkdownEditor`; compare with `DiffView`.
- Never put secrets in docs, even though HTML is disabled.

### Charts and KPIs

- Follow the `dataviz` skill. Categorical marks `viz-1..3`, never as text color.
- `LineChart`: 2 px lines, hairline grid, crosshair with keyboard (← →), "Ver tabla" twin.
- `BarList`: horizontal bars with the value at the tip; status bars use their family color.
- `StatTile`: label, one number, one context line; the delta has an arrow, never color alone; `restricted` adds a lock that marks figures visible only with `ai.costs.view`.

### Kanban with a keyboard alternative

- Columns `KANBAN_COLUMNS`: Backlog · Por hacer · En progreso · En revisión · Hecho; `WipChip` "2 de 3" / "3 de 3 · al límite" / "4 de 3 · pasó el límite".
- `KanbanCard`: drag between columns **or** use "Mover a…" (menu button, `aria-label` "Mover GIA-… a…") with keyboard or touch.
- Ranked lists: `SortableList` with drag handle plus "Subir" / "Bajar", announced in a polite live region.

## 6. Page contract (design agent ↔ app agent)

**Rule:** design owns the frontend; the app agent owns PHP, routes and controllers. The props of each page are the contract.

| Piece | Rule |
|---|---|
| Page signature | `export default function X(props: PageProps<XProps>)`; pass props to the layout as `shared`. Never `usePage`, never fetch |
| Shared props | Exactly `SharedProps` in `types/shared.ts`: `auth.user`, `companies`, `current_company_id`, `flash`, `notifications_unread`, `modules`. Guest pages: `GuestSharedProps` |
| Prop names | Never reuse a shared name (`companies`, `auth`, `flash`, `modules`): use `admin_companies`, `roadmap_companies` |
| Types file | `resources/js/types/pages/<module>.ts`, starting with a comment block listing every Ziggy route (method, params, body) and permission used |
| Domain types | `types/ai.ts`, `types/assistant.ts`, `types/github.ts`, `types/suggestions.ts`, `types/mcp.ts` |
| Mocks | `resources/js/mocks/<module>.ts` exports `variants: Record<string, MockVariant<P>>` with `page` (Inertia name), `label`, `props`. Build from fixtures and `sharedFor(userKey)` |
| Gallery | Auto-discovers pages and variants. Frame URL: `frame.html#page=<Inertia name>&variant=<mock file>:<key>&theme=light\|dark&brand=example\|segurex\|derivados\|andina`. "Ver props" shows what the controller must return |
| Helpers | Pure logic in `lib/` (or next to its component) with a Vitest test written first; relative imports in tested files |
| Permissions | `can(user, permission)` only hides or disables; the server re-checks every action |
| Contract doc | One `docs/design/stage-*-contract.md` per stage: types files, routes added by design, rules the backend must enforce, open points |

| Folder | Owner |
|---|---|
| `resources/css/`, `resources/brand/`, `resources/emails/`, `resources/js/{Components,Layouts,Pages,types,mocks,lib}`, `ui-preview/`, `docs/design/` | Design agent |
| PHP, routes, controllers, `resources/js/app.tsx`, Laravel Vite config | App agent |

```bash
npm run dev:preview   # gallery on http://localhost:5174
npm test              # helper and email rule tests
npm run typecheck
```

## 7. Content and voice

| Rule | Example |
|---|---|
| Colombian Spanish, tú | "Toca para ver", "Aún no tienes solicitudes" |
| Sentence case, no ALL CAPS | "Pendiente de aprobación", not "PENDIENTE DE APROBACIÓN" |
| Short labels, verb first on buttons | "Enviar", "Mover a…", "Ver tabla"; no "→" |
| Code, props and file names in English | `priority_final`, `StatusChip` |
| Dates (`formatDate`) | "7 oct 2026"; in the year: "26 sep" (`formatShortDate`); promise: "martes 13 de octubre" (`formatLongDate`) |
| Times (`formatTime`) | "9:40 a. m." with no-break spaces inside "a. m." and before it |
| Relative (`formatRelative`) | "hace 12 min", "hoy 9:40 a. m.", "ayer 8:00 p. m." |
| Money (`formatCOP`) | "$ 14.200.000" (no decimals, no-break space) |
| Hours (`formatHours`) | "6 h 20 min" |
| Locale and zone | `es-CO`, `America/Bogota`; ISO 8601 in, built from `formatToParts` |
| Unknown facts | Bracket placeholders: `[DOMINIO DEL GESTOR]`, `[RUTA EN SAP]` |
| Never invent | Menu paths, product steps, people's titles: use a placeholder and list it as an open point |

## 8. Accessibility checklist

- [ ] Text contrast AA (4.5:1; 3:1 for large text and UI borders); brand buttons via `bg-brand-button`.
- [ ] Status, CI, semáforo, talla, deltas and budget never rely on color alone.
- [ ] Every control ≥ 44 × 44 px; no hover-only action.
- [ ] Every page has one `h1`; headings in order.
- [ ] Everything works with the keyboard; drag has a button alternative ("Mover a…", "Subir" / "Bajar").
- [ ] Focus is visible (`:focus-visible`) and returns to the opener after a dialog or drawer closes.
- [ ] Icon-only buttons use `IconButton` with a `label`.
- [ ] Inputs have a visible label; errors are linked to the field (`Field`, `useFieldIds`).
- [ ] Danger alerts use `role="alert"`; other updates are polite (`role="status"`).
- [ ] Timers and countdowns give a spoken text (`formatElapsedSpoken`, `countdownSpoken`) without flooding live regions.
- [ ] Charts have a table twin ("Ver tabla") and keyboard tooltips.
- [ ] Sizes in rem; the page works at 200 % text and at 390 px without horizontal page scroll.
- [ ] Light and dark checked; reduced motion respected.
- [ ] External links say they open outside (`ExternalLink`).

## 9. Email and Telegram

### Email

Source of truth: [`resources/emails/README.md`](../../resources/emails/README.md); backend contract: [email-templates-contract.md](email-templates-contract.md).

| Topic | Rule |
|---|---|
| Templates | 7: `new-request`, `request-received`, `status-changed`, `it-question`, `delivered`, `requirement`, `project-report` (`.html` + `.txt`) |
| Anatomy | Brand bar and logo · title · body · one bulletproof button (44 px) + the same link in plain text · footer "Gestor TI · Grupo RYR" with why you got it and "Cambiar mis avisos" |
| Layout | Single column, `max-width: 600px`, `role="presentation"` tables, inline styles; no web fonts, SVG, scripts, flex or grid |
| Brand | `brand_color` = the accessible button color from `lib/brand.ts` (Segurex `#C4470F`, Derivados `#183F94`); logos as PNG |
| Privacy | Requester templates never get cost, rate, hours or internal-note variables; report costs only inside `@if can_view_costs`; no tracking |
| Status | Glyph + text, never color alone |
| Preview | Gallery → "Correos"; rules enforced by `resources/emails/emails.test.ts` |

### Telegram

Source of truth: [telegram-format.md](telegram-format.md).

- `parse_mode` HTML; only `<b>`, `<i>`, `<a href>` (and `<code>` in the password message). No tables, headings, Markdown or emoji.
- At most 4 short paragraphs; sources last under "Fuentes:"; long answers cut with "Ver respuesta completa en el Gestor TI".
- Escape all AI and user text before adding tags.
- Buttons: plain text; `callback_data` ≤ 64 bytes with an opaque id only. Every tap re-checks permission.
- Passwords: separate message, deleted at 60 s, never sent to the AI. Vault items: a link button only.

## 10. Privacy by design

The UI hides; **the server enforces**. Every rule below needs both.

| Rule | How the UI encodes it |
|---|---|
| Costs and rates only with `ai.costs.view` | Money fields arrive `null`; `StatTile restricted` marks cost figures; costs pages render `NoPermission`; "Tiempo y costos" tab hidden |
| Requesters never see internal notes, rates, costs or others' estimates | The requester types have no such fields (`Ai/Requests/Show` `view: 'requester'`); requester emails have no such variables |
| Project approval only `ai.projects.approve` | Approve controls hidden for everyone else; reject needs a comment |
| Vault only `ai.vault.access` | `.vault-zone`, values only in a one-time `revealed` prop, 30 s, never in lists |
| Secrets never leave once | Show-once dialogs and cards, `encryptHistory()` + `no-store`, no URLs, no logs |
| AI never receives sensitive data | No credentials, vault content, rates, costs, salaries, client data, attachments or internal notes in prompts, briefs or MCP results |
| AI never decides | Suggestions need "Usar"; `/aprender` only creates a draft to approve |
| Card visibility | "Solo TI" / "Toda la empresa" (`VisibilityBadge`); lists include only what the person may see |

## 11. Checklist for a new screen

- [ ] Mockup approved on the design canvas before code.
- [ ] Types file with the route and permission header; page props typed as `PageProps<XProps>`.
- [ ] No `usePage`, no fetch; no page prop named like a shared prop.
- [ ] Right layout (`AppLayout` or a tab layout) with `shared`.
- [ ] Only tokens and `brand-*` utilities; no hex in components.
- [ ] Status shown with `StatusChip` / `Chip` (icon + text), never brand color.
- [ ] Empty, loading, error and no-permission states designed.
- [ ] Phone (390), tablet (834) and desktop (1440) checked in both brands and both themes.
- [ ] Tables become cards on phone; no horizontal page scroll.
- [ ] Forms with `useForm`, Laravel errors and `ConfirmDialog` for destructive actions.
- [ ] Sensitive values follow §10 (permission, show-once, no URLs).
- [ ] Dates, times and money through `lib/format.ts`.
- [ ] Copy in Colombian Spanish, tú, sentence case; unknown facts as `[PLACEHOLDERS]`.
- [ ] Mock `variants` for every state, visible in the gallery; helper tests written first; `npm test` and `npm run typecheck` pass.
- [ ] Contract doc updated: routes added by design, backend rules, open points.
