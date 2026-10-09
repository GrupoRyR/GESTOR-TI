# Stage C mockups · common brief (all writers)

You design mockup artboards for the **Asistente TI** (Stage C) of Gestor TI RYR, plus three forms left from Stage B, on an existing design canvas. You only WRITE `.dc.html` files; the parent publishes them. Do not publish, do not touch any repository.

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

Stage C additions to the shared pieces: Assistant screens use `<dc-import name="A-AppNav" active="assistant" …>` and, right under the top bar, `<dc-import name="C-AssistantTabs" active="chat|knowledge|import|drafts|unanswered" hint-size="100%,56px"></dc-import>` (tabs Chat · Base de conocimiento · Importar desde Excel · Borradores de /aprender (3) · Preguntas sin respuesta (7)). Stage B forms use `B-AITabs active="products"` or `active="vault"`. Also read `Components.dc.html` → "Chat del Asistente" and the Stage B boards named in your assignment.

## Stage C rules (from the app prompt, approved)
- Assistant = chat inside Gestor TI (phone first) and the same bot on Telegram. Short answers in Spanish, ALWAYS cites the knowledge cards it used as links ("Fuentes"), offers suggested questions, never invents menu paths, report names or data. When it does not know: "No lo tengo documentado." and the question is registered in "Preguntas sin respuesta".
- Read-only: it changes nothing. The only write is the `/aprender` draft, pending approval (permission `assistant.knowledge.manage`).
- Permissions: `assistant.use` (chat; today Jhon and Leonardo), `assistant.knowledge.manage` (cards, import, drafts, unanswered; Jhon and Leonardo), `assistant.credentials` + `credentials.view` (password delivery; ONLY Jhon and Leonardo). Card visibility: "Solo TI" or "Toda la empresa"; answers respect it.
- Password delivery (`entregar_credencial`): the password NEVER passes through the AI provider; the app sends it directly in a separate message/card. In the app: card with whose and which system, user name, value hidden, "Mostrar" shows it 30 s with a visible countdown and a "Copiar" button, then it hides again; note "No pasó por la IA · quedó en la auditoría". In Telegram the message deletes itself after 60 s. If two people share the name, buttons to pick one. Vault credentials are NOT delivered by chat: reply with a link to the item in the Bóveda (which asks to confirm identity). Anyone without the permission gets a plain refusal.
- AI provider errors: "El asistente no está disponible en este momento, intenta en unos minutos." If there is no AI key or the monthly cap is reached, the chat is disabled with an explanation for admins; the rest of the app keeps working.
- Privacy: the AI never receives credentials, vault content, rates, salaries or client data; the assistant explains HOW to get a cartera report, it never sends the data.
- No emoji anywhere (app or Telegram mockups). Telegram buttons are plain text ("Aprobar", "Descartar").

## Shared mock facts (use these exact facts so all screens agree)
People: Jhon Castro (Líder IA, Súper Admin, Segurex), Leonardo Herrera (Gerente de TI, Súper Admin), Paola Ríos (Coordinadora de ventas, Ventas, Segurex), Juan Pérez (Asesor comercial, Ventas, Segurex, juan.perez@segurex.com), Juan Pérez (Auxiliar de bodega, Logística, Derivados, juan.perez@derivados.com.co — the namesake for disambiguation), Andrés Méndez (Analista de compras, Compras, Derivados), Mónica Salazar (Tesorera, Tesorería, Derivados). Bot: `[NOMBRE DEL BOT]`. Today is miércoles 7 de octubre de 2026; money "$ 14.200.000"; dates "15 ene 2027", "hace 2 días", "hoy 9:40 a. m.".

Knowledge base: 48 cards = 9 Sistema · 14 Informe · 7 Flujo de datos · 12 Procedimiento · 6 Glosario; 5 with review overdue; 31 "Toda la empresa", 17 "Solo TI". Type icons are NEUTRAL (subtle tile #F0F0EC + icon), never status colors. Cards (key · type · title · company · area · visibility · review · owner · facts):
- K-031 · Informe · Informe de ventas diario · Segurex · Ventas · Toda la empresa · revisar 15 ene 2027 · Paola Ríos · lo envía Toma Pedidos SAP a las 6:00 a. m. a gerencia y a los coordinadores de ventas; datos de SAP B1 (pedidos del día anterior); Excel por correo.
- K-027 · Flujo de datos · Pedidos de Toma Pedidos a SAP B1 · Segurex · Ventas · Solo TI · revisar 30 nov · Jhon Castro · Toma Pedidos → Service Layer de SAP B1 cada [FRECUENCIA]; si falla, el pedido queda "pendiente de sincronizar".
- K-014 · Sistema · SAP Business One · Segurex y Derivados · TI · Solo TI · revisión vencida (30 sep) · Leonardo Herrera · servidor local, acceso por VPN, versión [VERSIÓN].
- K-022 · Procedimiento · Cómo sacar el informe de antigüedad de cartera en SAP B1 · Segurex · Cartera · Toda la empresa · revisar 20 dic · Gerencia de cartera · ruta `[RUTA EN SAP]` (never invent menu paths).
- K-018 · Procedimiento · Conectarse a la VPN · Segurex y Derivados · TI · Toda la empresa · revisar 10 feb 2027 · Leonardo Herrera.
- K-035 · Sistema · Visualizador de cartera · Segurex · Cartera · Toda la empresa · linked to product visualizador-cartera v3.1.0.
- K-009 · Glosario · Pedido bloqueado por cartera · Segurex · Ventas · Toda la empresa · "pedido que SAP retiene porque el cliente superó su cupo o tiene facturas vencidas".
- K-040 · Sistema · Cámaras / robotcamara · Segurex · TI · Solo TI · revisión vencida (1 oct).

Chat conversation (Jhon, hoy): Q "¿Quién recibe el informe de ventas diario y a qué hora sale?" → A "Sale a las 6:00 a. m. desde Toma Pedidos SAP y llega por correo a gerencia y a los coordinadores de ventas de Segurex. Trae los pedidos del día anterior desde SAP B1." Fuentes: K-031 Informe de ventas diario, K-027 Pedidos de Toma Pedidos a SAP B1. Suggestions: "¿Quién es el dueño del informe?", "¿Qué pasa si un pedido no sincroniza?", "Ver la ficha de Toma Pedidos SAP".
No answer: Q "¿Cada cuánto se respalda el servidor de SAP?" → "No lo tengo documentado. Registré la pregunta para que TI la documente." (+ link "Ver preguntas sin respuesta" for managers).
Password: Q "Dame la contraseña de Office de Juan Pérez" → "Hay dos personas con ese nombre. ¿De cuál?" buttons "Juan Pérez · Asesor comercial · Ventas · Segurex" / "Juan Pérez · Auxiliar de bodega · Logística · Derivados" → card "Office · Juan Pérez (Ventas, Segurex)", usuario juan.perez@segurex.com, value hidden; revealed sample `Tq8#vL2m-Rx4p` with "Se oculta en 24 s". Vault case: Q "Dame la llave de API de DeepSeek" → "Esa credencial está en la bóveda del proyecto. Ábrela ahí; te pedirá confirmar tu identidad." link "Abrir en la bóveda". Refusal (Paola): "No puedo entregar contraseñas. Pídela a TI."

Unanswered (7): "¿Cada cuánto se respalda el servidor de SAP?" (3 veces, Jhon y Leonardo, último hoy) · "¿Qué informe muestra las ventas por zona?" (2) · "¿Quién aprueba las órdenes de compra de Derivados?" (2) · "¿Cómo se agrega una impresora en la bodega?" (1) · "¿Qué hace robotcamara cuando una cámara no responde?" (1) · "¿Dónde están los manuales del Portafolio?" (1) · "¿Qué IP tiene el NVR de la bodega?" (1). Actions: Documentar (opens the card form prefilled with the question), Ya está documentada (link to a card), Descartar.

/aprender drafts (3): (1) Jhon, Telegram, hace 2 h: "el informe de ventas diario lo envía Toma Pedidos a las 6 a. m. a gerencia" → draft Informe, warns "Se parece a K-031 Informe de ventas diario" (offer "Agregar a K-031"). (2) Leonardo, Telegram, ayer: "la VPN de Derivados usa FortiClient y la cuenta la crea Leonardo" → draft Procedimiento. (3) Jhon, app, hace 3 días: "robotcamara revisa las cámaras cada 15 minutos y avisa por Telegram si una no responde" → draft Sistema linked to K-040. Actions: Aprobar, Editar antes de aprobar, Descartar.

Excel import: file `mapa-de-sistemas.xlsx`, sheet "Informes", 32 filas; columns Nombre, Tipo, Empresa, Área, Descripción, Sistema origen, Destinatarios, Horario, Visibilidad, Dueño. Result preview: 28 nuevas, 3 actualizan fichas existentes (K-031; K-024 Informe de antigüedad de cartera, Segurex · Cartera; K-029 Informe de compras pendientes por proveedor, Derivados · Compras), 1 con error (fila 17: empresa "RYR" no existe). Downloadable "Plantilla de importación".

Stage B pending forms:
- Product create/edit. Fields: nombre, slug, empresa, área, dueño, tipo de app, stack, dónde corre, URL, versión actual, estado, descripción, solicitudes vinculadas; the GitHub repo is Stage D, shown disabled "Se conecta en la etapa D". Edit example: Toma Pedidos SAP (toma-pedidos · Segurex · Ventas · Laravel · GoDaddy · v2.4.0 · [DUEÑO]).
- Incident on Toma Pedidos SAP: "Los pedidos no sincronizan con SAP B1", inició hoy 8:10 a. m., impacto Alta (los asesores no pueden despachar), estado Abierto, registrado por Jhon Castro, origen manual (monitoring not active yet).
- Vault item edit: "Llave de API de DeepSeek" · proyecto Asistente TI · tipo Llave de API · usuario "—" · value never prefilled ("Déjalo en blanco para no cambiarla") · rotar cada 90 días, próxima 5 ene 2027 · notas · delete with confirmation typing the name; every change is logged and alerted to Jhon by Telegram.

## Output
Write each assigned file, then return a list: `file · width × height · one-line description · any placeholder used`. Also list facts you had to invent. Close with a `## Key Learnings` block of 1–5 numbered standalone sentences (omit if none).
