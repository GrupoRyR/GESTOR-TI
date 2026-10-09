# Stage B mockups · common brief (all writers)

You design mockup artboards for the **Gestor IA** (Stage B, core) of Gestor TI RYR, on an existing design canvas. You only WRITE `.dc.html` files; the parent publishes them. Do not publish, do not touch any repository.

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
Approved rules: AI suggestions are Stage D (do not design them). Vault is ONLY Jhon (`ai.vault.access`), always dark zone with amber hazard stripe. Project approval only by Jhon Castro or Edward Ramanan. Requesters never see costs, rates or internal notes. WIP limit = 3 projects "En desarrollo". Budget alerts at 80% and 100%; an increase over 20% needs re-approval.

## Shared mock facts (use these exact facts so all screens agree)
People: Jhon Castro (Líder IA, Súper Admin, Aprobador de proyectos, Segurex, jhon.castro@segurex.com), Leonardo Herrera (Gerente de TI, Súper Admin), Edward Ramanan (Aprobador de proyectos, Gerencia, Segurex, cargo [CARGO]), Paola Ríos (Coordinadora de ventas, Ventas, Segurex), Juan Pérez (Asesor comercial, Ventas, Segurex), Andrés Méndez (Analista de compras, Compras, Derivados), Mónica Salazar (Tesorera, Tesorería, Derivados), Camila Torres (Diseñadora, Derivados), Diego Restrepo (Auxiliar de cartera, Segurex), Gerencia de cartera (Segurex area owner).
Requests (key · title · company · area · requester · state · facts):
- GIA-149 · Reporte de comisiones en Excel · Segurex · Ventas · Juan Pérez · Recibida · primera respuesta vence hoy.
- GIA-148 · Toma Pedidos SAP: avisar al asesor cuando el pedido quede bloqueado por cartera · Segurex · Ventas · Paola Ríos · Recibida · 6 h/sem × 8 personas · +3 votos · primera respuesta vencida hace 1 día · posible duplicado de GIA-121.
- GIA-146 · Portafolio de productos: exportar fichas técnicas en PDF · Derivados · Compras · Andrés Méndez · Pendiente de aprobación · 3 h/sem × 4 · talla M · prioridad 68 · estimado 40 h, $ 6.400.000, entrega 31 oct.
- GIA-144 · Conciliación bancaria automática desde SAP B1 · Derivados · Tesorería · Mónica Salazar · Pendiente de aprobación · talla L · 72 h · $ 11.500.000 · entrega 28 nov.
- GIA-139 · Visualizador de cartera: filtro por asesor y zona · Segurex · Cartera · Gerencia de cartera · En desarrollo · 4 h/sem × 5 · +2 · talla L · prioridad 82 (sugerida 74) · aprobada por Edward Ramanan el 30 sep · proyecto P-07.
- GIA-131 · Portafolio: conectar la API de WooCommerce · Derivados · Ventas · Andrés Méndez · En desarrollo · bloqueada por el requerimiento "API de WooCommerce" a Mercadeo (6 días esperando) · proyecto P-05.
- GIA-127 · Toma Pedidos SAP: descuentos por volumen · Segurex · Ventas · Paola Ríos · En pruebas (falta validar 2 de 4 criterios) · proyecto P-06.
- GIA-121 · Alerta de pedidos bloqueados por cartera · Segurex · Ventas · En backlog · 4 personas la pidieron.
- GIA-118 · Portafolio: buscador por referencia · Derivados · Compras · Andrés Méndez · En producción desde el 2 oct (v1.8.2) · pendiente de calificar.
- GIA-102 · Alertas de stock bajo por correo · Derivados · Compras · En backlog · puesto 6 en la hoja de ruta de Compras.
- GIA-097 · Cuentas por pagar: aprobación de facturas en celular · Segurex · Contabilidad · Rechazada (motivo: SAP ya lo trae con el módulo de aprobaciones).
Projects (WIP 3 de 3 en desarrollo):
- P-07 · Visualizador de cartera · filtros (GIA-139) · Segurex · Cartera · estimado 120 h, $ 16.000.000, entrega 31 oct · real 92 h, $ 14.200.000 (89 %, pasó el 80 %) · fecha proyectada 14 nov (14 días tarde).
- P-05 · Portafolio · WooCommerce (GIA-131) · Derivados · Ventas · estimado 60 h, $ 8.000.000, 15 nov · real 21 h.
- P-06 · Toma Pedidos · descuentos por volumen (GIA-127) · Segurex · Ventas · estimado 48 h, $ 6.300.000 · real 46 h · en pruebas.
Kanban columns: Backlog, Por hacer, En progreso, En revisión, Hecho. Example tasks of P-07: T1 Diseñar filtros (Hecho), T2 Consulta SAP por asesor (Hecho), T3 Endpoint de zonas (En revisión, PR #41), T4 Consulta de cartera por asesor desde SAP B1 (En progreso, cronómetro corriendo 01:12:40, 2 de 5 subtareas, vence 18 oct), T5 Pruebas con cartera (Por hacer), T6 Manual de usuario (Backlog).
Products (slug · company · stack · host · version · owner): Toma Pedidos SAP (toma-pedidos · Segurex · Laravel · GoDaddy · v2.4.0 · [DUEÑO]) · Portafolio de productos (portafolio · Derivados · PHP · GoDaddy · v1.8.2 · [DUEÑO]) · Cuentas por pagar (cuentas-por-pagar · Segurex · Next.js · Vercel · v1.2.0) · Visualizador de cartera (visualizador-cartera · Segurex · Next.js · Vercel · v3.1.0 · Gerencia de cartera) · Cámaras / robotcamara (robotcamara · Segurex · Python · local · v0.9.3). Monitoring is not active yet: health block says "Monitoreo aún no activo".
Money: Colombian pesos "$ 14.200.000"; dates "14 nov", "hace 2 días", "hoy 9:40 a. m.". Today is miércoles 7 de octubre de 2026.

## Output
Write each assigned file, then return a list: `file · width × height · one-line description · any placeholder used`. Also list facts you had to invent. Close with a `## Key Learnings` block of 1–5 numbered standalone sentences (omit if none).
