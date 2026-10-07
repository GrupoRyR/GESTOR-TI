# Stage A · Platform base (Gestor TI RYR)

Source of truth: `PROMPT_GESTOR_IA_APP.md` v1.1 (sections 2, 3, 6, 7.1, 8, 9) and `PASO_A_PASO_INICIO.md` step 3.

## Objective
Build the platform base so Stage B (Gestor IA) and C (Asistente TI) can be added without rework:
Laravel 13 + Inertia/React/TS, Microsoft multitenant login + emergency login, companies, areas,
employees (Graph sync) and their accesses, roles/permissions, notifications (mail, Telegram, database),
audit, modules by configuration, open doors (`monitored_apps`, `alerts`, `AlertService`), and deploy to cPanel.

## Constraints
- Shared cPanel hosting (GoDaddy): PHP 8.3, MySQL, cron every minute, database queue, no long-running processes.
- No screens: the design agent owns `resources/js/{Components,Layouts,Pages,types,mocks}`, `resources/css`, `ui-preview/`.
  Backend returns exactly the props of each page type; `assertInertia` tests check keys.
- Secrets only in `.env`. Code/tables/identifiers in English; visible text in Spanish.
- Local tests run on SQLite in-memory. MySQL-only features (FULLTEXT) are verified on the GoDaddy server (user decision 2026-10-07).
- Nothing may assume exactly two companies.

## Delivery
- Branch: `claude/gestor-ia-app-prompt-d14b73`. Strategy: `ask-on-risk` (pending chain choice).
- Forecast: ~3,500 authored lines (well over the 400-line budget) → chained PRs.
- RDD: off (global). Ordinary checks only.

## Tasks
- [x] A0 · Laravel 13.35 skeleton (generated; composer.phar verified by sha384). Route: inline (mechanical).
- [ ] A1 · Inertia + React + TS wiring, packages (socialite, microsoft provider, spatie permission/activitylog/backup), project `CLAUDE.md`, `.env.example`, phpunit on SQLite.
- [ ] A2 · Modules by configuration (`config/modules.php`) + shared Inertia props (`HandleInertiaRequests`).
- [ ] A3 · Companies (configurable, sender mailbox, tenant), areas, `BelongsToCompany` global scope + company selector (`companies.view_all`).
- [ ] A4 · Roles and permissions seeder (module prefixes, `ai.vault.access` exclusive), role × permission matrix endpoints.
- [ ] A5 · Microsoft multitenant login (only active companies' tenants, company from `tid`) + emergency local login (lockout after 5).
- [ ] A6 · Employees + daily Graph sync per company (skip guests, department→area mapping), access systems, employee accesses (encrypted, reveal with audit).
- [ ] A7 · Notifications: preferences, Telegram link + webhook (secret in URL), `AlertService` + `alerts`, `monitored_apps`, `settings`.
- [ ] A8 · Audit log listing with filters.
- [ ] A9 · Scheduler (`queue:work --stop-when-empty`), backups, GitHub Actions deploy to cPanel over SSH.

## Acceptance
Each task: feature tests green (`php artisan test`), Pint clean, commit on the branch.

## Progress / evidence
- A0: skeleton created with `composer create-project laravel/laravel:^13.0`.

## Next step
A1.
