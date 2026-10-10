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
- [x] F1 · Mail foundation and the four requester templates plus `new-request`.
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
- [x] F2 · `requirement` and `project-report`.
  - The cost blocks render only for `ai.costs.view` recipients: one render per group, and no cost data at all for the others.
  - The approver lists come from the current `ai.projects.approve` holders.
  - Reminders for requirements.
  - Route: delegated.
- [ ] F3 · Close: full checks, deploy doc (mail setup, PNG logos), PRs.

## Progress / evidence

### F1 · Mail foundation, requester templates and `new-request` — done (`07b046e`)
- **Route:** delegated writer (writer trigger: 2+ non-trivial files).
- **Views:** `resources/views/mail/{html,text}/<name>.blade.php` for `new-request`, `request-received`, `status-changed`, `it-question`, `delivered`. Generated from `resources/emails/` with `App\Support\Mail\TemplateToBlade` (escaped echoes only, Outlook conditionals kept, documentation comments dropped). `tests/Feature/Mail/TemplateViewsTest.php` fails when a view drifts from its source: exact match with the converted source, plus a render with `samples.json` (both companies, blocks on and off) compared with an independent reference renderer of the markers.
- **Mail layer:** `App\Mail\TemplateMail` + `App\Support\Mail\{EmailTemplate, MailBranding, BrandColor, MailDates, RequestEmails}`.
  - From = company `sender_email` as "{company_name} · Gestor TI"; subject from `EmailTemplate::subject()` (tested against the `subject:` comment).
  - Data filtered to a per-template whitelist; the text part is entity-decoded.
  - `brand_color`: `colors.button` when stored, else derived from `colors.primary` with a PHP port of `lib/brand.ts`.
  - `preferences_url` = `route('profile.edit')`.
- **PNG logos:** the uploaded logo is used when it is PNG, JPEG or GIF. Otherwise `public/brand/logo-{slug}.png` and `logo-{slug}-dark.png` are used (the dark one falls back to the light one). `logo_width` is read from the PNG, else `gestor.mail.logo_width` (140). An SVG never reaches mail. F3 must document exporting the PNGs to `public/brand/` at deploy.
- **Wiring (event → template):**
  - `AiRequestCreated` (new, `ai.request.created`, mail + bell, to `ai.manage` holders who reach the company, never the actor or requester) → `new-request`.
  - `AiRequestReceived` (new, `ai.request.status_changed`) → `request-received`.
  - `AiRequestStatusChanged` → `status-changed`; a move to "Necesita información" with a question → `it-question`.
  - `AiRequestReminder::question` → `it-question`; the validation reminder keeps the default layout.
  - `AiRatingRequested` (notice and reminder) → `delivered`.
  - `AiReleasePublished` → `delivered` for the requester's first request, all titles joined, user notes as paragraphs.
  - Channels still come from preferences. A release still sends one notice per requester.
- **Ratings:** `ai.ratings.quick` `GET /ai/requests/{key}/rating/{score}?user=…` (signed, 30 days, `module:ai` + `signed` + `throttle:30,1`, no login).
  - The named user must be the requester (else 403).
  - Saves `solved_need` through `RateRequest` (`ease_of_use` now nullable, migration `2026_11_01_000001`). Idempotent, race-safe. Then redirects to `ai.requests.show` with a flash.
- **Setting:** `mail.reply_by_email` (default false) drives the `it-question` reply block.
- **RED:** 26 of 26 new tests failed on the base commit (missing classes and route). **GREEN:** 56 tests in `tests/Feature/Mail`.
- **Checks:** `php artisan test --filter='Mail|Notification|Rating'` 119 passed; `php artisan test` 1236/1237, then the one failure fixed (`CreateRequestTest` asserted that nothing was sent) and re-run: 1237 passed; `vendor/bin/pint --test` passed.
- **Notes for design:**
  - (a) `lib/brand.ts` derives Segurex's button as `#CD4B00`, not the README's `#C4470F`. Store `colors.button` or update the README.
  - (b) `RequestRating.ease` can now be null (one-click rating), so the type and its display need `| null`.
  - (c) `delivered` has no variant without a product or version: a manual "En producción" without a release reads "en el Gestor v".
  - (d) After a one-click rating there is no "add a comment" step. The request page's conversation is the fallback.

### F2 · Requirement and project report — done (this commit)
- **Route:** delegated writer (writer trigger: 2+ non-trivial files).
- **Views:** `resources/views/mail/{html,text}/{requirement,project-report}.blade.php`, generated with `TemplateToBlade`. They are new `EmailTemplate` cases, so the drift test covers them.
  - Two converter fixes, needed by these templates; the five F1 views regenerate byte-identical:
    - A line with several markers is no longer read as a single marker.
    - Blade skips a directive glued to a letter (`esperando@else`) or a bare one followed by one (`@elseRecibes`). Those cases become the equivalent PHP tags.
  - The test's reference renderer got the same multi-marker fix. New drift cases cover the no-costs `project-report` and the hidden secrets warning.
- **`requirement`** (`App\Support\Mail\ProjectEmails::requirement`):
  - Built by `AiRequirementRequested` (registered or passed on) and `AiRequirementReminder` (manual "Recordar" and `ai:requirement-reminders`) through `toMail`. The requirement is read fresh, with the default layout if it is gone.
  - Channels come from the preferences of `ai.requirement.requested`. One email per event.
  - `reminder_text`: "Este es el primero / segundo / tercero…"; the reminder number is captured before `reminders_sent` increments. On registration it reads "Si sigue pendiente, te lo recordamos cerca de la fecha límite".
  - `asks_for_secrets` is true when the title or detail mention keys, passwords, credentials, tokens or secrets.
  - `needs`: the detail lines, else the title.
  - `blocked_task`: "T2 · title", else "que depende de este dato".
  - `due_note`: "vence hoy / mañana / en N días", "venció ayer / hace N días".
  - Sender and branding come from the project's company. `action_url` is `ai.requirements.respond`.
- **`project-report`** (`App\Mail\ProjectReportMail` now extends `TemplateMail`; data from `ProjectEmails::projectReport`):
  - `ProjectReports::send` is unchanged and still builds the content per recipient group. `budget` carries money only with `ai.costs.view`, the internal version, the section chosen and `include_costs`.
  - With costs: `can_view_costs` true and every cost variable, including the date risk and `approvers_and` / `approvers_or`.
  - Without costs: the data has no cost, hour, budget, rate, date-vs-approval or approver key at all. `EmailTemplate::COST_VARIABLES` is dropped by the whitelist whenever `can_view_costs` is not true.
  - The approvers are the current `ai.projects.approve` holders (`TriagePeople::approvers`), joined "A y a B" / "A o B" (README sample), "A, a B y a C" / "A, B o C" for three or more, and "los aprobadores de proyectos" when there are none.
  - From, branding and subject come from the project's company. The PDF attachment stays. The old `mail/project-report.blade.php` was removed; the PDF still uses `mail/partials/project-report-body`.
- **No tracking.** `action_url` comes with the plain fallback link, plus `preferences_url`.
- **RED:** 10 of the new tests failed on the base (missing cases, `ProjectEmails`, template wiring). **GREEN:** `RequirementEmailTest` (5), `ProjectReportEmailTest` (4), `TemplateViewsTest` (46 with the new cases).
- **Checks:** `php artisan test --filter='Mail|Report|Requirement'` 136 passed; `php artisan test` 1260 passed (baseline 1237 + 23 new); `vendor/bin/pint --test` passed.
- **Notes for design:**
  - (e) `requirement` always says "Lleva {{ days_waiting }} días esperando", so the first email reads "Lleva 0 días esperando" and a later one can read "1 días". A variant or a plural rule is needed.
  - (f) `project-report` has no `project_title` subtitle in the app, so the title repeats the project name.
  - (g) `done_items` / `next_items` always show, even when the report settings drop those sections; `risks` follows the `blockers` section.

## Open questions (for Jhon)
- Does the app process email replies? If not, `reply_by_email` stays off.
