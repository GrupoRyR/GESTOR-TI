# Stage E mockups · brief

You design mockup artboards for **Stage E · Conector MCP** of Gestor TI RYR, on an existing design canvas. You only WRITE `.dc.html` files; the parent publishes them. Do not publish, do not touch any repository.

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



Shared pieces: personal screens use `A-AppNav active="none"` and `A-AppTopBar`; read `A-Profile.dc.html` and `A-ProfilePhone.dc.html` (profile layout and Telegram linking — the MCP connector is a personal setting next to them), `A-AdminIntegrations.dc.html`, `B-VaultOpen.dc.html` (show-once secret pattern), `D-BriefProject.dc.html` (the 3-step strip "Cómo trabajar con Claude": step 3 is this connector), `A-States.dc.html`.

## Stage E rules (from the app prompt, approved)
- The app exposes an MCP server over HTTP so Claude can query and update the Gestor IA from a conversation. It is added in Claude as a **custom connector**. It does not use the paid AI API.
- **Token per user**, only for holders of `ai.mcp.use` (roles Líder IA and Gerente de TI: today Jhon Castro and Leonardo Herrera). Each person creates, names and revokes their own tokens.
- The token value is shown **once**, right after creating it, with Copiar and a clear warning; afterwards only its last 4 characters (`…7f3a`). Never in URLs, never in logs.
- Tools the connector offers: `buscar_solicitudes`, `ver_solicitud` (with its documentation), `crear_tarea`, `actualizar_tarea`, `agregar_nota`, `adjuntar_documento`. A token can be "Solo lectura" (first two) or "Lectura y escritura" (all six).
- It never returns vault content, credentials, rates, costs or salaries. Every call is audited (who, which tool, when, result).
- It must work on the shared hosting: there is a "Probar conexión" check and a state for "El conector aún no está activo en este servidor".
- Do NOT invent Claude's menu paths: write setup steps generically ("En Claude, agrega un conector personalizado") and use placeholders like `[PASOS EN CLAUDE]` where an exact path would be needed. For Claude Code, the command `claude mcp add --transport http gestor-ia https://[DOMINIO DEL GESTOR]/mcp --header "Authorization: Bearer <token>"` may be shown.

## Mock facts
Today is sábado 10 de octubre de 2026. Server URL `https://[DOMINIO DEL GESTOR]/mcp`. Jhon's tokens: "Claude · portátil" (Lectura y escritura, creado 8 oct, último uso hoy 9:12 a. m., vence 6 ene 2027, …7f3a) and "Claude Code · oficina" (Solo lectura, creado 1 sep, último uso hace 3 días, vence 30 nov, …c21b); one revoked "Prueba" (revocado 2 oct). Recent activity: hoy 9:12 a. m. `ver_solicitud` GIA-139 ok; 9:10 `buscar_solicitudes` "cartera" ok (4 resultados); ayer 5:40 p. m. `agregar_nota` P-07 · T4 ok; ayer 5:38 p. m. `actualizar_tarea` T4 rechazada (token de solo lectura). Expiration options: 30, 90 (default) o 180 días.

## Output
Write each assigned file, then return a list: `file · width × height · one-line description · any placeholder used`. Also list facts you had to invent. Close with a `## Key Learnings` block of 1–5 numbered standalone sentences (omit if none).
