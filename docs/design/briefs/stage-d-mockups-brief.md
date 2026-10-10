# Stage D mockups · common brief (all writers)

You design mockup artboards for **Stage D · Conexiones** (GitHub, "Brief para Claude", AI suggestions) of Gestor TI RYR, on an existing design canvas. You only WRITE `.dc.html` files; the parent publishes them. Do not publish, do not touch any repository.

## Where
- Canvas folder (read + write): `C:\Users\Claude\AppData\Local\Temp\claude\C--Users-Claude-Documents-PROYECTOS-CLAUDE-PAGINAS-WEB-GESTOR-TI--claude-worktrees-diseno-gestor-ia-a5a314\56383c3b-3b7e-452d-b8fe-3de6089fbd86\scratchpad\canvas\project\`
- Write ONLY the files assigned to you (exact names). Never edit existing files.

## Read before writing (approved, the look to follow exactly)
- `Main.dc.html` (tokens: 12 statuses in 6 families with their icons, 5-phase rail, semáforo shapes, talla bars, priority, budget bar with 80/100 marks, density), `Components.dc.html` (inbox row, kanban card with WIP and timer, timeline, form step with +1, buttons Aprobar/Rechazar, chat, vault zone), `Brands.dc.html`.
- Stage A boards for structure and patterns: `A-Employees.dc.html` (desktop page using `<dc-import>` of `A-AppNav`, `A-AppTopBar`), `A-AdminCompanies.dc.html` (+ `A-AdminTabs`), `A-HomePhone.dc.html` and `PhoneHome.dc.html` (phone header + bottom bar), `A-States.dc.html` (empty/error/no permission), `A-Profile.dc.html` (checkbox matrix), `A-AdminWizard.dc.html` (stepper).
- Shared pieces you MUST reuse on desktop Gestor IA screens: `<dc-import name="A-AppNav" active="ai" hint-size="248px,900px"></dc-import>`, `<dc-import name="A-AppTopBar" company="all" hint-size="100%,68px"></dc-import>`, and `<dc-import name="B-AITabs" active="inbox|backlog|areas|projects|products|docs|week|value|vault" hint-size="100%,56px"></dc-import>` right under the top bar. For tall desktop boards set the AppNav hint height to the board height.
- Also read `artifact-type/reference/format.md` rules summarized here: each file is one self-contained Design Component page. Skeleton:

```html
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Short screen name</title>
<script src="./support.js"></script>
</head>
<body>
<x-dc>
<helmet>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:opsz,wght@12..96,600;12..96,700&amp;family=Figtree:wght@400;500;600;700&amp;display=swap" rel="stylesheet">
<style>
body{margin:0}
a{color:#17191C}a:hover{color:#45484E}
</style>
</helmet>
<div style="width: 1440px; height: 900px; box-sizing: border-box; …root…">…</div>
</x-dc>
<script type="text/x-dc" data-dc-script data-props='{"$preview":{"width":1440,"height":900}}'>
class Component extends DCLogic {
  renderVals() { return {}; }
}
</script>
</body>
</html>
```

Rules that fail silently: keep `<script src="./support.js"></script>` exactly; close every non-void element; quote every attribute; `{{hole}}` is a dotted lookup into `renderVals()` only (never an expression); repeats with `<sc-for list="{{items}}" as="x" hint-placeholder-count="N">`, branches with `<sc-if value="{{flag}}" hint-placeholder-val="{{ false }}">`; style holes allowed for per-item computed styles (`style="{{x.style}}"`); no `innerHTML`, no emoji, icons are inline stroke SVG (24 viewBox, stroke-width 2, round caps); no iframes; fixed root size `width × height` matching `$preview`; inline `style="…"` for layout; real `<button>`, `<a href>`, `<input>` + `<label>` even in static mockups; aria-label on icon-only buttons; 44 px touch targets; `&amp;` inside the Google Fonts URL. UI text in Colombian Spanish, sentence case, no ALL CAPS labels, no "→" on buttons, no lorem ipsum; unknown facts as bracket placeholders like `[DUEÑO]`.

## Visual system (light mode unless the board says dark)
Ground #F5F5F2, surface #FFFFFF, subtle #F0F0EC, line #E5E5E0, line-strong #D6D6D0, text #17191C / #45484E / muted #5E6168. Fonts: Bricolage Grotesque 700 for titles (letter-spacing -0.02em), Figtree for UI, tabular numbers (`font-feature-settings: 'tnum' 1` on root). Radii: chip 10, control 12, icon tile 14, panel 18, kanban/inner card 12. Primary button (Segurex brand) #C4470F with white text; Derivados #183F94. Active nav/tab = Segurex tint #FFEFE6 / ink #8F360B.
Status families (chip bg/text): gray #F0F0EC/#45484E (Recibida, En evaluación) · amber #FFF4DB/#7A4F00 (Necesita información, Pendiente de aprobación, En pruebas) · blue #E8EEFA/#1E4FA8 (Planificada, En desarrollo) · green #E6F5EC/#1F7A4D (Aprobada, En producción) · slate #E6EAF0/#334155 (En backlog, Cerrada) · red #FEEEEC/#B42318 (Rechazada). Always icon + text. Dark values: gray #26272B/#C9CBD0, amber #2A2418/#F5D58A, blue #1B2540/#A9C1F5, green #12332A/#5FD39B, slate #262B33/#CBD5E1, red #3A1D1A/#FF8F84; dark ground #121315, surface #1C1D20, line #2C2E33, text #F2F2EF/#A3A6AD.
Phase rail: 5 segments Evaluación · Aprobación · Plan · Construcción · Entrega; done = #17191C, current = its family text color, pending = #E5E5E0, rejected = rest dashed.
Company marks in "Todas" views: Segurex chip #FFEFE6/#8F360B letter S; Derivados #E8EEFA/#03256C letter D.
Approved rules: Vault is ONLY Jhon (`ai.vault.access`), always dark zone with amber hazard stripe. Project approval only by Jhon Castro or Edward Ramanan. Requesters never see costs, rates or internal notes. WIP limit = 3 projects "En desarrollo". Budget alerts at 80% and 100%; an increase over 20% needs re-approval.


Stage D shared pieces: desktop Gestor IA screens use `A-AppNav active="ai"`, `A-AppTopBar company="all"` and `B-AITabs active="…"`; admin screens use `A-AdminTabs active="integrations"`. Read the Stage B boards named in your assignment and reuse their exact layout; Stage D adds panels, buttons and dialogs to them.

## Stage D rules (from the app prompt, approved)
- **GitHub:** a GitHub App of the organization (or a fine-grained token limited to the group's repos), configured in Administración › Integraciones (permission `ai.github.manage`). Each product and project links to its repository. A task or approved request creates a GitHub issue in one click: title with the key (`GIA-139 · …`), description, acceptance criteria, link back, label `gestor-ia`.
- **Webhooks** (`POST /api/github/webhook`, signature `X-Hub-Signature-256` verified): events `issues`, `pull_request`, `release`, `workflow_run` (CI), `push` to the main branch (last deploy). Linking by the key `GIA-123` in the title/body or the branch name `gia-123-…`. A PR mentioning the key moves the task to "En revisión"; merged → "Hecho"; a release creates the product version, drafts the notes with the included requests and notifies each requester. Events are stored and processed by the queue; failed ones can be reprocessed. Never show the webhook secret or tokens; only "configurado" / "no configurado".
- **Technical docs live in the repo** (README, CLAUDE.md, docs/) and the app shows them read from GitHub (already designed in B-DocsRepo).
- **"Brief para Claude":** a button on every request, task and project that generates Markdown with the problem, acceptance criteria, product context and links to docs and repo, ready to paste in Claude; Copiar and Descargar .md. It also generates the initial `CLAUDE.md` of a new project. It never includes vault content, credentials, costs, rates or internal notes marked private. It does NOT use the paid AI API (it is a template filled by the app).
- **AI suggestions** (provider DeepSeek, `ai.manage`): summarize long requests, detect duplicates, propose acceptance criteria and size (talla), draft user-facing release notes. ALWAYS shown as a suggestion that Jhon confirms: a clearly marked block ("Sugerencia de IA · revísala antes de usarla"), never applied automatically, each with Usar / Editar / Descartar. A neutral "sparkle" icon is allowed; never use status colors for it. Requesters never see suggestions. If there is no AI key or the monthly cap is reached, the suggestion blocks are hidden and admins see one short note; everything else keeps working. The AI never receives credentials, vault content, rates, salaries or client data.
- **Stage E** (MCP connector) is not designed now; at most a disabled "Conector MCP · etapa E" mention.

## Shared mock facts (keep consistent with Stage B; today is miércoles 7 de octubre de 2026)
- GitHub organization: `[ORG]` (placeholder; the real name is still pending). GitHub App "Gestor TI RYR" installed on 6 repositories; webhook configured; 214 events received this month, 2 failed (one `workflow_run` with a timeout, one `release` for a repo that is not linked yet).
- Repositories: `[ORG]/toma-pedidos` (Toma Pedidos SAP, main `main`), `[ORG]/portafolio` (Portafolio de productos), `[ORG]/cuentas-por-pagar`, `[ORG]/visualizador-cartera` (Visualizador de cartera, linked to project P-07), `[ORG]/robotcamara`, `[ORG]/gestor-ti`. Not linked yet: Cámaras / robotcamara (repo exists, not linked).
- P-07 Visualizador de cartera · filtros (GIA-139): T3 "Endpoint de zonas" has PR #41 "GIA-139 endpoint de zonas por asesor" (open, CI passing, 2 commits, by Leonardo Herrera) → task En revisión. T4 "Consulta de cartera por asesor desde SAP B1" (En progreso) has no issue yet → the create-issue flow creates issue #57. T2 "Consulta SAP por asesor" merged in PR #36 → Hecho. CI on `main`: passing; last deploy to Vercel hace 3 h (commit `a1b2c3d`). One failed CI run on PR #40 (closed).
- Releases: Portafolio de productos v1.8.2 published 2 oct, included GIA-118 (buscador por referencia) → version created, requester Andrés Méndez notified. Visualizador de cartera v3.1.0 (12 sep). Next planned: v3.2.0 with GIA-139.
- Brief example: GIA-139 "Visualizador de cartera: filtro por asesor y zona" (Segurex · Cartera · Gerencia de cartera · talla L · 4 h/sem × 5 personas · criteria: 1) filtrar por asesor, 2) filtrar por zona, 3) combinar ambos filtros, 4) exportar el resultado a Excel), product context (Next.js on Vercel, v3.1.0, data from SAP B1 via [FUENTE]), links (repo, docs, CLAUDE.md). For a new project the initial CLAUDE.md template: project goal, stack, commands, conventions, where docs live, never commit secrets.
- AI suggestion examples on GIA-148 (Toma Pedidos SAP: avisar al asesor cuando el pedido quede bloqueado por cartera, Paola Ríos): summary in 2 lines; possible duplicate GIA-121 "Alerta de pedidos bloqueados por cartera" (En backlog, 4 personas) with the reason "mismo problema y misma app"; proposed criteria (3); proposed size M with a one-line reason; Jhon's final values stay separate. Release notes draft for Portafolio v1.8.2 written for users (no technical jargon).

## Output
Write each assigned file, then return a list: `file · width × height · one-line description · any placeholder used`. Also list facts you had to invent. Close with a `## Key Learnings` block of 1–5 numbered standalone sentences (omit if none).
