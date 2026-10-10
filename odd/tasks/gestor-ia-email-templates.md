# Gestor IA · Email templates (HTML/CSS for Blade)

Locator: `odd/tasks/gestor-ia-email-templates.md` · Engram mirror: `odd/gestor-ia-email-templates/tasks`

## Objective
Deliver the approved email templates as simple, email-safe HTML with inline CSS. The backend turns them into Blade. The templates also get a preview in the `ui-preview` gallery.

- **Source:** the design canvas https://claude.ai/artifact/VpJ8rdFwC3jeCVBiSjfn2m, page "Plantillas de correo" (11 M-* boards). Jhon approved them on 2026-10-10.
- **Optional block:** the "También puedes responder este correo" box stays as an optional block, and the backend sends it only if it accepts replies.

## Scope (design-owned)
- `resources/emails/**` (new folder: the templates, the shared partials and the variables README).
- `ui-preview/**` (an email preview section).
- `resources/js/lib/` only if a helper is needed.
- Tests next to the templates.
- `docs/design/`.

Out of scope: Blade files, Mailables and Notifications (owned by the app agent).

## Constraints
- **Layout:** single column, max 600 px, table-based layout with inline styles, a system font stack (Figtree optional), no SVG, no scripts, no web-font dependency, alt text on every image, solid 44 px buttons, a plain fallback link, and dark-mode safe.
- **Branding:** each company has its own logo, brand color and sender (`segurex.info@segurex.com`, `derivados.info@derivadoscol.com`).
- **Subject:** starts with the key.
- **Content:**
  - The footer has "Cambiar mis avisos" and no tracking pixels.
  - Requester emails never contain costs, rates or internal notes.
  - The report has a costs block that is only for `ai.costs.view` recipients.
- **Variables:** each one is marked so the backend can map it, documented in `resources/emails/README.md`.

## Delivery strategy
Branch `claude/diseno-gestor-ia-correos` is stacked on Stage E (`claude/diseno-gestor-ia-etapa-e`, PR #34). One PR.

## Tasks
- [x] T1 Templates, rule tests and gallery preview.
  - **Templates:** the shared layout (header per company, footer) and 7 templates: `new-request`, `request-received`, `status-changed`, `it-question`, `delivered`, `requirement` and `project-report` (with an optional costs block). Each template also has a plain-text version.
  - **Tests:** Vitest rule tests written first.
  - **Gallery:** an emails section in the `ui-preview` gallery (600 / 358, light / dark client simulation, both companies).
  - **Docs:** the variables README.
  - **Route:** delegated.
- [x] T2 Handoff.
  - `docs/design/email-templates-contract.md` and the PR.
  - **Route:** inline.

## Acceptance criteria
- The rule tests pass: no `<svg>` or `<script>`, every `<img>` has `alt`, max width is 600, every template has a button and a fallback link, the footer is present, and no 1×1 tracking images. Requester templates contain no cost or rate variables.
- `npm run typecheck`, `npm test` and `npm run build:preview` pass.
- Every template is visible in the gallery.

## Progress
- 2026-10-10: branch created from the Stage E head (`e6b5846`, 496 tests); document created.
- T1 `8273110` 7 templates (.html + .txt), partials, samples.json, README variables, emails.test.ts rule tests, gallery Correos section with emailRender helper — 598 tests. Parent replaced hardcoded approver names with approvers_and/approvers_or.
- T2 inline: `docs/design/email-templates-contract.md`; PR stacked on Stage E.

## Next step
Jhon reviews the gallery; merge the stacked PRs in order (#8, #10, #11, #20, #34, this one). Then the mini design-system manual.
