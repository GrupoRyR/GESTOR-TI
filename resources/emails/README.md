# Email templates

Email-safe HTML (plus a plain-text version) for the seven Gestor TI notification emails, built from the approved "Plantillas de correo" boards (M-* boards, approved 2026-10-10). The backend turns each file into a Blade view. Nothing here is Blade yet: the markers below map 1:1 to Blade.

## Quick path for the backend

1. Copy `<name>.html` and `<name>.txt` into Blade views (the `.txt` becomes the `text` part of the Mailable).
2. Replace the markers (table below). Variables are already snake_case, so `{{ request_key }}` becomes `{{ $request_key }}`.
3. Set the subject and sender from the comments at the top of each file.
4. Fill the variables listed for that template. The sample values in `samples.json` show the exact format (dates in Spanish, amounts with `$` and dots).
5. Check it in the gallery: `npm run dev:preview` → sidebar "Correos".

| Marker in the files | Blade |
|---|---|
| `{{ name }}` | `{{ $name }}` (escaped; never `{!! !!}`) |
| `{{ item.field }}` inside a loop | `{{ $item['field'] }}` or `{{ $item->field }}` |
| `<!-- @if flag -->…<!-- @else -->…<!-- @endif -->` | `@if ($flag) … @else … @endif` |
| `<!-- @optional flag -->…<!-- @endoptional -->` | `@if ($flag) … @endif` (a block the backend may drop for good) |
| `<!-- @foreach list as item -->…<!-- @endforeach -->` | `@foreach ($list as $item) … @endforeach` |
| `<!-- subject: … -->`, `<!-- from: … -->`, `<!-- to: … -->`, `<!-- when: … -->` | Documentation only: use them in the Mailable, then drop the comments. |
| `<!-- partial: … -->` | Documentation only. |

A block marker alone on its line removes that whole line when rendered (as Blade directives do), so the `.txt` lists have no blank lines between items.

Keep the `<!--[if mso]>` and `<!--[if !mso]><!-->` comments: they are Outlook conditionals, not markers.

## Templates

Every email leaves from the mailbox of the record's company (`{{ company_sender }}`) with display name `{{ company_name }} · Gestor TI`, uses that company's logo and brand color, and has one primary button to `{{ action_url }}` plus the same link in plain text.

| File | When it is sent | To | Subject | Company on the board |
|---|---|---|---|---|
| `new-request` | A requester sends a new request | IT staff who receive new requests of that company | `[{{ request_key }}] Nueva solicitud: {{ request_title }}` | Segurex |
| `request-received` | Right after the requester sends it | The requester | `[{{ request_key }}] Recibimos tu solicitud` | Segurex |
| `status-changed` | The request changes status | The requester | `[{{ request_key }}] Tu solicitud está {{ status_text }}` | Derivados |
| `it-question` | IT asks the requester something (status "Necesita información") | The requester | `[{{ request_key }}] TI tiene una pregunta sobre tu solicitud` | Derivados |
| `delivered` | The release that contains the request reaches production | The requester | `[{{ request_key }}] Ya está en producción: {{ request_title }}` | Derivados |
| `requirement` | IT registers a requirement for a project, and on each reminder | The person outside IT who must answer it | `[{{ project_key }}] TI necesita {{ requirement_title }} · {{ due_summary }}` | Derivados |
| `project-report` | Weekly project report | Project followers; the costs block only for `ai.costs.view` | `[{{ project_key }}] Informe semanal: {{ project_name }} al {{ report_date_short }}` | Segurex |

## Privacy rules

- **Requester templates** (`request-received`, `status-changed`, `it-question`, `delivered`) never receive costs, rates, hours or internal notes. The rule tests fail if one of these files uses a variable whose name contains `cost`, `costs`, `hours`, `budget`, `rate`, `rates` or `internal`.
- **`project-report`**: every cost and hour variable lives inside `<!-- @if can_view_costs -->`. Set `can_view_costs` per recipient (`true` only with `ai.costs.view`), render once per group, and do not send cost values to the view for the other group at all.
- **No tracking**: no pixels, no 1×1 images, no open tracking. Links go straight to the app.
- **Secrets**: `requirement` tells people not to send keys by email when `asks_for_secrets` is true.

## Shared variables (all templates)

| Variable | Type | Example | Notes |
|---|---|---|---|
| `company_name` | string | `Segurex` | Short company name. Also the logo `alt`. |
| `company_sender` | string | `segurex.info@segurex.com` | The company mailbox; also the From address. Derivados: `derivados.info@derivadoscol.com`. |
| `brand_color` | hex string | `#C4470F` | Brand bar and button background. Use the **accessible button color** (white text must pass AA), not the raw `colors.primary`: Segurex `#C4470F`, Derivados `#183F94`. `resources/js/lib/brand.ts` derives it from `companies[].colors`. |
| `logo_url` | absolute URL | `https://…/brand/logo-segurex.png` | **PNG**, served by the backend (`companies[].logo_url` converted to PNG). Email clients do not show SVG. Render it at 2× (56 px tall) for sharp screens. |
| `logo_dark_url` | absolute URL | `https://…/brand/logo-segurex-dark.png` | PNG for dark-mode clients (`companies[].logo_dark_url`). If a company has none, pass `logo_url`. |
| `logo_width` | integer (px) | `175` | Logo width at 28 px tall: `round(28 × width / height)`. Segurex `175`, Derivados `154`. |
| `action_url` | absolute URL | `https://…/solicitudes/GIA-149` | Target of the button and of the plain fallback link: the exact screen of the record. |
| `preferences_url` | absolute URL | `https://…/perfil/avisos` | "Cambiar mis avisos" (profile notification preferences). |

The gallery uses the SVGs in `resources/brand/` only as a preview stand-in for the PNGs.

## Variables per template

Loop fields are written `list.field`. Status colors come from the status families of the design system (`gray`, `amber`, `blue`, `green`, `slate`, `red`).

### `new-request`

| Variable | Type | Example | Notes |
|---|---|---|---|
| `request_key` | string | `GIA-149` | |
| `request_title` | string | `Reporte de comisiones en Excel` | |
| `area_name` | string | `Ventas` | Requester's area. |
| `requester_name` | string | `Juan Pérez` | |
| `time_spent_today` | string | `Sin dato` | "Tiempo de hoy": how long the task takes the requester today, as entered in the request. `Sin dato` when empty. |
| `first_response_due` | string | `hoy 5:00 p. m.` | First-response deadline, already phrased. |

### `request-received`

| Variable | Type | Example | Notes |
|---|---|---|---|
| `request_key` | string | `GIA-149` | |
| `request_title` | string | `Reporte de comisiones en Excel` | |
| `review_due_date` | string | `martes 13 oct` | Last day of the 3 business days for IT's first review. |

### `status-changed`

| Variable | Type | Example | Notes |
|---|---|---|---|
| `request_key` | string | `GIA-146` | |
| `request_title` | string | `Portafolio de productos: exportar fichas técnicas en PDF` | |
| `status_label` | string | `Pendiente de aprobación` | Status as shown in the app. |
| `status_text` | string | `pendiente de aprobación` | Lower-case status for the subject ("Tu solicitud está …"). |
| `status_glyph` | string | `◐` | Glyph of the status (○ ◐ ✓ ✕ !); never color alone. |
| `status_family` | string | `amber` | Status family; drives the dark-mode chip colors (`em-<family>` class). |
| `status_bg` | hex string | `#FFF4DB` | Light chip background of the family. |
| `status_fg` | hex string | `#7A4F00` | Light chip text color of the family. |
| `current_phase` | string | `aprobación` | Lower-case phase for the title ("Tu solicitud pasó a …"). |
| `phases` | list | 5 items | Evaluación, Aprobación, Plan, Construcción, Entrega. |
| `phases.state` | `done` \| `current` \| `pending` | `done` | Also a CSS class for dark mode. |
| `phases.glyph` | string | `✓` | `✓` done, status glyph for current, empty for pending. |
| `phases.label` | string | `Evaluación · hecha` | Phase + state in words. |
| `phases.note` | string | `TI la evaluó y la dejó lista.` | Optional; empty string hides it. |
| `phases.dot_bg` | hex string | `#17191C` | Done `#17191C`, current = family bg, pending `#FFFFFF`. |
| `phases.dot_fg` | hex string | `#FFFFFF` | Done `#FFFFFF`, current = family text, pending `#5E6168`. |
| `phases.dot_border` | hex string | `#17191C` | Same as `dot_bg`, pending `#D6D6D0`. |
| `phases.color` | hex string | `#17191C` | Label color: done `#17191C`, current = family text, pending `#5E6168`. |
| `next_step_text` | string | `Gerencia decide si entra a la hoja de ruta. No tienes que hacer nada; te avisamos por correo.` | "Qué sigue" for the new status. |
| `roadmap_position` | integer \| null | `3` | Optional. When set, shows "Si se aprueba, queda en el puesto N…". |
| `roadmap_area` | string | `Compras` | Roadmap name, used with `roadmap_position`. |

### `it-question`

| Variable | Type | Example | Notes |
|---|---|---|---|
| `request_key` | string | `GIA-150` | |
| `request_title` | string | `Comparar precios de proveedores en el Portafolio` | |
| `asker_name` | string | `Jhon Castro` | IT person who asks. |
| `asked_at` | string | `hoy, 9:14 a. m.` | When it was asked, already phrased. |
| `question_text` | string | `¿Qué proveedores necesitas comparar y cada cuánto?` | The question, plain text. |
| `reply_by_email` | boolean | `true` | **Optional block.** Show "También puedes responder este correo" only if the backend accepts replies by email (Reply-To into the conversation). If not supported, drop the block and keep only the "Responder" button. |

### `delivered`

| Variable | Type | Example | Notes |
|---|---|---|---|
| `request_key` | string | `GIA-118` | |
| `request_title` | string | `Buscador por referencia` | |
| `requester_first_name` | string | `Andrés` | |
| `product_name` | string | `Portafolio de productos` | |
| `release_version` | string | `1.8.2` | Without the `v`. |
| `released_on` | string | `viernes 2 oct` | |
| `change_title` | string | `Buscar por referencia` | User-facing release note title. |
| `change_paragraphs` | list of strings | 2 items | User-facing release note text (no internal notes), one paragraph per item. |
| `rating_url_1` … `rating_url_5` | absolute URL | `https://…/solicitudes/GIA-118/calificar?nota=4` | One-click rating links (1 Nada útil … 5 Muy útil). They must be signed, single-purpose links that save the score without logging in. |

The five links are `rating_url_1`, `rating_url_2`, `rating_url_3`, `rating_url_4` and `rating_url_5`. `action_url` points to the rating screen ("Calificar en el Gestor").

### `requirement`

| Variable | Type | Example | Notes |
|---|---|---|---|
| `project_key` | string | `P-05` | |
| `request_key` | string | `GIA-131` | Request of the project. |
| `request_title` | string | `Portafolio: conectar la API de WooCommerce` | |
| `area_name` | string | `Ventas` | Area that asked for the request. |
| `requirement_title` | string | `la API de WooCommerce` | What IT needs, with its article (reads "TI necesita …"). |
| `requested_by_name` | string | `Jhon Castro` | IT person who registered the requirement. |
| `is_overdue` | boolean | `true` | Red "Vencido" chip when true, amber "Vence el …" chip otherwise. |
| `days_waiting` | integer | `6` | |
| `due_date` | string | `jueves 1 oct` | |
| `due_note` | string | `venció hace 6 días` | Short status of the due date. |
| `due_summary` | string | `venció el 1 oct` | Subject suffix, e.g. `vence el 9 oct` when not overdue. |
| `blocked_task` | string | `T2 · Conectar la API de WooCommerce` | Task stopped by the requirement. |
| `needs` | list of strings | 2 items | What exactly is needed. |
| `asks_for_secrets` | boolean | `true` | Shows the "No envíes las claves por correo…" warning. |
| `reminder_text` | string | `Este es el tercero` | Reminder count in words. |

### `project-report`

Outside the costs block:

| Variable | Type | Example | Notes |
|---|---|---|---|
| `can_view_costs` | boolean | `true` | `true` only for recipients with `ai.costs.view`. Switches the summary, the costs block and the footer reason. |
| `project_key` | string | `P-07` | |
| `project_name` | string | `Visualizador de cartera` | Used in the subject. |
| `project_title` | string | `Visualizador de cartera · filtros` | Title in the body. |
| `request_key` | string | `GIA-139` | Request that originated the project. |
| `requested_by` | string | `Gerencia de cartera` | Who asked for it. |
| `report_date` | string | `miércoles 7 oct` | |
| `report_date_short` | string | `7 oct` | For the subject. |
| `status_label`, `status_glyph`, `status_family`, `status_bg`, `status_fg` | | `En desarrollo`, `◐`, `blue`, `#E8EEFA`, `#1E4FA8` | Same as in `status-changed`. |
| `summary` | string | `Avanza en alcance… 14 días después de lo previsto.` | Summary **without** cost mentions. |
| `phases` | list | 5 items | Phase rail. |
| `phases.state` | `done` \| `current` \| `pending` | `current` | |
| `phases.glyph` | string | `◐` | `✓` done, status glyph current, `○` pending. |
| `phases.label` | string | `Construcción (ahora)` | |
| `phases.bar_color` | hex string | `#1E4FA8` | Done `#17191C`, current = family text, pending `#E5E5E0`. |
| `phases.color` | hex string | `#1E4FA8` | Label color: done `#17191C`, current = family text, pending `#5E6168`. |
| `phases.weight` | string | `700` | `700`, or `500` for pending. |
| `tasks_done`, `tasks_total` | integer | `2`, `9` | |
| `tasks_percent` | integer 1–100 | `22` | Bar width. Send at least `1` so the bar cell keeps a width. |
| `criteria_met`, `criteria_total` | integer | `2`, `5` | Acceptance criteria. |
| `criteria_percent` | integer 1–100 | `40` | Bar width. |
| `criteria_met_marks` | string | `✓✓` | One `✓` per met criterion. |
| `criteria_pending_marks` | string | `○○○` | One `○` per pending criterion. |
| `done_items` | list of strings | 3 items | "Hecho esta semana". |
| `next_items` | list of strings | 2 items | "Lo que sigue". |
| `risks` | list | 2 items | Empty list hides the section. Write the text for the recipient group (no amounts in the no-costs version). |
| `risks.title` | string | `Fecha en riesgo.` | |
| `risks.text` | string | `La entrega proyectada es el 14 nov, 14 días después de lo previsto (31 oct).` | |

Inside `<!-- @if can_view_costs -->` only:

| Variable | Type | Example | Notes |
|---|---|---|---|
| `summary_with_costs` | string | `… y el costo ya pasó el 80 % del presupuesto.` | Summary for cost viewers. |
| `hours_used`, `hours_budget`, `hours_left` | integer | `92`, `120`, `28` | |
| `hours_percent` | integer 1–100 | `77` | Bar width. |
| `cost_used`, `cost_budget` | string | `$ 14.200.000`, `$ 16.000.000` | Already formatted in COP. |
| `cost_percent` | integer | `89` | Bar width and label. |
| `cost_bar_color` | hex string | `#B26B00` | `#17191C` below 80 %, `#B26B00` from 80 %, `#B42318` from 100 %. |
| `cost_alert` | boolean | `true` | Shows "Pasó el 80 % del presupuesto. Ya avisamos a {{ approvers_and }}." |
| `projected_date`, `approved_date` | string | `14 nov`, `31 oct` | |
| `days_late` | integer | `14` | `0` hides "N días tarde". |
| `cost_reapproval_limit` | string | `$ 19.200.000` | Approved cost + 20 %. |

| `approvers_and` | string | `Jhon Castro y a Edward Ramanan` | Current project approvers (`ai.projects.approve`), joined with "y a" for the alert. The list is configurable in Administración. |
| `approvers_or` | string | `Jhon Castro o Edward Ramanan` | Same list joined with "o" for the re-approval note. |

## Email-safety rules (checked by `emails.test.ts`)

- Single column, `max-width: 600px`, fluid on phones; layout only with `role="presentation"` tables and inline styles. One small `<style>` block holds the phone media queries and dark-mode tweaks (`em-*` classes), which clients without `<style>` support simply ignore.
- Font stack `Figtree, 'Segoe UI', Arial, sans-serif`; titles try Bricolage Grotesque first. No web fonts, no SVG, no scripts, no external CSS, no flex or grid.
- Every `<img>` has `alt`, `width` and `height`. No tracking pixels.
- Bulletproof button: a colored table cell plus a link with real text, `padding: 12px 24px` and `line-height: 20px` (44 px tall), always followed by the plain fallback link.
- Status always as glyph + text, never color alone.
- Hidden preheader text after `<body>`, padded so the client does not pull body text into the preview.
- Footer: "Gestor TI · Grupo RYR", why you got it, the sender, and "Cambiar mis avisos".

## Files

| Path | What it is |
|---|---|
| `<name>.html` | Full, self-contained email (opens alone in a browser or an email tester). Partials are already inlined. |
| `<name>.txt` | Plain-text version with the same markers. |
| `partials/header.html`, `partials/footer.html`, `partials/button.html` | Reference snippets of the shared pieces. In Blade they can become `@include`s. |
| `samples.json` | Sample data per template and per company, plus the report's without-costs override. Used by the gallery and the tests. |
| `emails.test.ts` | Rule tests (`npm test`). |
