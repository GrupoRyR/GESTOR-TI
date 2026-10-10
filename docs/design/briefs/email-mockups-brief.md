# Email templates mockups · brief

You design mockup artboards for the **email templates** of Gestor TI RYR, on an existing design canvas. You only WRITE `.dc.html` files; the parent publishes them. Do not publish, do not touch any repository.

## Where
- Canvas folder (read + write): `C:\Users\Claude\AppData\Local\Temp\claude\C--Users-Claude-Documents-PROYECTOS-CLAUDE-PAGINAS-WEB-GESTOR-TI--claude-worktrees-diseno-gestor-ia-a5a314\56383c3b-3b7e-452d-b8fe-3de6089fbd86\scratchpad\canvas\project\`
- Write ONLY the files assigned to you (exact names). Never edit existing files.

## Visual system (light mode unless the board says dark)
Ground #F5F5F2, surface #FFFFFF, subtle #F0F0EC, line #E5E5E0, line-strong #D6D6D0, text #17191C / #45484E / muted #5E6168. Fonts: Bricolage Grotesque 700 for titles (letter-spacing -0.02em), Figtree for UI, tabular numbers (`font-feature-settings: 'tnum' 1` on root). Radii: chip 10, control 12, icon tile 14, panel 18, kanban/inner card 12. Primary button (Segurex brand) #C4470F with white text; Derivados #183F94. Active nav/tab = Segurex tint #FFEFE6 / ink #8F360B.
Status families (chip bg/text): gray #F0F0EC/#45484E (Recibida, En evaluación) · amber #FFF4DB/#7A4F00 (Necesita información, Pendiente de aprobación, En pruebas) · blue #E8EEFA/#1E4FA8 (Planificada, En desarrollo) · green #E6F5EC/#1F7A4D (Aprobada, En producción) · slate #E6EAF0/#334155 (En backlog, Cerrada) · red #FEEEEC/#B42318 (Rechazada). Always icon + text. Dark values: gray #26272B/#C9CBD0, amber #2A2418/#F5D58A, blue #1B2540/#A9C1F5, green #12332A/#5FD39B, slate #262B33/#CBD5E1, red #3A1D1A/#FF8F84; dark ground #121315, surface #1C1D20, line #2C2E33, text #F2F2EF/#A3A6AD.
Phase rail: 5 segments Evaluación · Aprobación · Plan · Construcción · Entrega; done = #17191C, current = its family text color, pending = #E5E5E0, rejected = rest dashed.
Company marks in "Todas" views: Segurex chip #FFEFE6/#8F360B letter S; Derivados #E8EEFA/#03256C letter D.
Approved rules: Vault is ONLY Jhon (`ai.vault.access`), always dark zone with amber hazard stripe. Project approval only by Jhon Castro or Edward Ramanan. Requesters never see costs, rates or internal notes. WIP limit = 3 projects "En desarrollo". Budget alerts at 80% and 100%; an increase over 20% needs re-approval.



Shared pieces: personal screens use `A-AppNav active="none"` and `A-AppTopBar`; read `A-Profile.dc.html` and `A-ProfilePhone.dc.html` (profile layout and Telegram linking — the MCP connector is a personal setting next to them), `A-AdminIntegrations.dc.html`, `B-VaultOpen.dc.html` (show-once secret pattern), `D-BriefProject.dc.html` (the 3-step strip "Cómo trabajar con Claude": step 3 is this connector), `A-States.dc.html`.


Also read: `Main.dc.html`, `Brands.dc.html` (Segurex and Derivados logos and colors; the logo assets on the canvas are `/_blob/53195d990514faa5d4f0f304e18be3fa` Segurex and `/_blob/291db11dd20d02d111f4e9e574375812` Derivados — use them as `<img>`), `B-RequestDetail.dc.html` (phase rail and requester view), `B-Rate.dc.html` (rating), `B-RequirementRespond.dc.html`, `B-ProjectReport.dc.html` (the report content — the email is its HTML version), `B-ApprovalDone.dc.html`, `D-ReleaseNotes.dc.html` (requester notice after a release). Board format and rules: follow the `.dc.html` skeleton and silent-failure rules of an existing board exactly (copy `A-States.dc.html`'s skeleton).

## Email rules (from the prompts, approved)
- Deliverable later: simple HTML + CSS that the backend turns into Blade. So design emails that are buildable as **email HTML**: single column, max 600 px wide, table-like stacking, no flex/grid tricks that email clients drop, inline styles, system font stack fallback (Figtree is a nice-to-have), no SVG for essential content (logos and icons will be PNG), bulletproof buttons (solid rectangle, 44 px tall, real text), text never inside images, works without images (alt text), good on phone (fluid width) and readable in dark-mode clients (no pure white text on images, no meaning by color alone; status always icon/glyph + text).
- Each email is sent from the **mailbox of the record's company**: Segurex `segurex.info@segurex.com`, Derivados `derivados.info@derivadoscol.com`; header uses that company's logo and brand color (thin top bar + button color); body copy is in Colombian Spanish, "tú", short. Subject lines start with the key (`[GIA-148] …`).
- Footer: "Gestor TI · Grupo RYR", why you got it ("Recibes este correo porque…"), link "Cambiar mis avisos" (profile notification preferences), no tracking pixels.
- Requesters never get costs, rates or internal notes. The project report has two versions: with time and costs (only for `ai.costs.view` recipients) and without.
- Every email has one primary action button linking to the app and a plain-text fallback link.

## Templates to design (sample facts from the approved boards)
1. **Nueva solicitud** (to IT, Jhon): GIA-149 "Reporte de comisiones en Excel" · Segurex · Ventas · Juan Pérez · Recibida · "Primera respuesta antes de hoy 5:00 p. m." [HORA LÍMITE] · button "Ver solicitud". Also the confirmation to the requester ("Recibimos tu solicitud GIA-149").
2. **Cambio de estado** (to requester): GIA-146 Andrés Méndez (Derivados) → "Pendiente de aprobación" with phase rail rendered as 5 labeled steps (text + check glyph, not color only), what happens next, button "Ver mi solicitud".
3. **Pregunta de TI** (to requester): GIA-150 Andrés Méndez (Derivados): Jhon asks "¿Qué proveedores necesitas comparar y cada cuánto?" · button "Responder" + note "También puedes responder este correo" [only if the backend supports it → mark as optional].
4. **Entrega en producción** (to requester, with rating): GIA-118 Portafolio v1.8.2 (Derivados) for Andrés: user notes (from D-ReleaseNotes), "¿Cómo te fue?" with 5 one-click rating buttons (1–5, each a link with text label) + "Calificar en el Gestor".
5. **Requerimiento** (to a user outside IT): P-05 requires "API de WooCommerce" from Mercadeo [RESPONSABLE MERCADEO], due date, why it blocks GIA-131, button "Responder el requerimiento".
6. **Informe de proyecto**: P-07 Visualizador de cartera weekly report: progress, criteria 2 of 5, tasks done/next, risks; version A with time and costs (92 h of 120, $ 14.200.000 of $ 16.000.000, 89 %, alerta 80 %, proyectada 14 nov) and version B without them.
Also one **anatomy/spec** board.

## Output
Write each assigned file, then return a list: `file · width × height · one-line description · any placeholder used`. Also list facts you had to invent. Close with a `## Key Learnings` block of 1–5 numbered standalone sentences (omit if none).
