# Email templates · Contract for the app agent

The approved mockups are on the design canvas, page "Plantillas de correo". **The source of truth is `resources/emails/README.md`.** It lists every template (when it is sent, to whom, subject and sender), every variable (type, example, notes), the conditional blocks and the safety rules. `resources/emails/emails.test.ts` enforces those rules.

## What there is
- **7 templates:** `new-request`, `request-received`, `status-changed`, `it-question`, `delivered`, `requirement` and `project-report`.
  - Each one is a self-contained `.html` (partials already inlined) plus a plain-text `.txt`.
  - The partials in `partials/` are a reference for the shared header, footer and button.
- **Sample data:** `samples.json`, per template, for Segurex and Derivados, with and without costs.
- **Gallery:** the "Correos" section in `npm run dev:preview`. It shows each template at 600 / 358 px, for both companies, in a light or dark client, with the cost and reply toggles and "Ver texto plano".

## Turning them into Blade
- **Variables:** they are written as `{{ snake_case }}` and map one-to-one to Blade variables.
- **Blocks:**

  | Template marker | Blade |
  |---|---|
  | `<!-- @if x -->` / `@else` / `@endif` | `@if` / `@else` / `@endif` |
  | `<!-- @foreach items as item -->` | `@foreach ($items as $item)` |
  | `<!-- @optional reply_by_email -->` | an `@if` driven by a backend setting |

  A marker that stands alone on its line removes the whole line.
- **Subject and sender:** each file starts with a `subject:` and a `from:` comment. Every email comes from the mailbox of the record's company (`segurex.info@segurex.com`, `derivados.info@derivadoscol.com`). The subject starts with the key, for example `[GIA-148]`.
- **Logos:** serve them as **PNG** at `{{ logo_url }}` / `{{ logo_dark_url }}`, many clients block SVG. The gallery uses the SVGs only for preview.
- **Color:** `brand_color` is the accessible button color (`#C4470F` / `#183F94`), not the raw logo color.

## Rules the backend must keep
- **Requesters:** `request-received`, `status-changed`, `it-question` and `delivered` never receive cost, rate, hours or internal-note variables.
- **Project report:** cost and time variables go only inside `@if can_view_costs`, and only recipients with `ai.costs.view` get that version.
- **Approvers:** `approvers_and` and `approvers_or` come from the current project approvers (`ai.projects.approve`), not from fixed names.
- **Rating links:** `rating_url_1` to `rating_url_5` must be signed one-click links.
- **No tracking:** no tracking pixels and no click tracking that rewrites `action_url`.
- **Every email** has `action_url` both as a button and as a plain fallback link, plus the footer with `preferences_url` ("Cambiar mis avisos").
- **Reply box:** "También puedes responder este correo" (`it-question`) is sent only if the app processes email replies.

## Open points
- **Placeholders:** `[DOMINIO DEL GESTOR]` in sample URLs.
- **Sample routes:** the preferences route (`/perfil/avisos`) and the rating link path are sample values.
- **Preheader texts:** they were written by design and can be adjusted.
