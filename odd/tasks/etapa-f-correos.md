# Email templates (backend)

Source of truth: `docs/design/email-templates-contract.md` and `resources/emails/README.md`. These came in design PR GrupoRyR/GESTOR-TI#35, which is merged to `main` and into this branch. The design manual (#36) is docs only and needs no backend.

## Objective
Every Gestor TI email uses the approved templates:
- 7 Blade mailables (HTML + plain text);
- sent from the mailbox of the record's company, with its PNG logo and brand color;
- signed one-click rating links;
- no tracking;
- the privacy rules per recipient.

## Decisions
- 2026-10-10 (user): "Sí, fusiónalos tú". The design PRs #8, #10, #11, #20, #34, #35 and #36 were merged to `main` in order, each retargeted to `main` first (merge commits).
- Templates stay design-owned in `resources/emails/`. The backend keeps Blade copies under `resources/views/mail/` and a test checks that they still match their source.

## Delivery
- Slices `stage-f/NN-*` stacked on `stage-e/05-personal-tokens`. RDD off.
- Checks: `php artisan test`, `vendor/bin/pint --test`, `npm run typecheck`, `npm test`, `npm run build`.

## Tasks
- [x] F0 · Merge `main` (with design #35/#36) into the branch: typecheck, 598 vitest and build green.
- [ ] F1 · Mail foundation and the four requester templates plus `new-request`.
  - **Rendering:**
    - Blade views for `new-request`, `request-received`, `status-changed`, `it-question` and `delivered` (HTML + text).
    - The From address and name come from the record's company, and the subject comes from the template.
    - PNG logo URLs (light and dark) and `brand_color`.
    - `action_url` plus a plain fallback link, and `preferences_url`.
    - `reply_by_email` comes from a setting (off).
  - **Ratings:** signed one-click links `rating_url_1..5` and their route.
  - **Wiring:** connect them to the existing notifications.
  - **Tests:** a variable whitelist so requester mails never carry cost, hours, rates or internal fields.
  - Route: delegated (writer trigger).
- [ ] F2 · `requirement` and `project-report`.
  - The cost blocks render only for `ai.costs.view` recipients: one render per group, and no cost data at all for the others.
  - The approver lists come from the current `ai.projects.approve` holders.
  - Reminders for requirements.
  - Route: delegated.
- [ ] F3 · Close: full checks, deploy doc (mail setup, PNG logos), PRs.

## Progress / evidence

## Open questions (for Jhon)
- Does the app process email replies? If not, `reply_by_email` stays off.
