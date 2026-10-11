# Gestor TI RYR

IT platform for Grupo Empresarial RYR (Segurex Latam, Soluciones y Derivados de Colombia). First delivery: platform base, Gestor IA and Asistente TI.

## Stack
- Laravel 13, PHP 8.3, MySQL 8 (utf8mb4). Tests run on SQLite in-memory; MySQL-only features (FULLTEXT) are verified on the server.
- Inertia + React + TypeScript + Tailwind, built with Vite and uploaded already compiled.
- Shared cPanel hosting (GoDaddy): cron every minute (`schedule:run`), `QUEUE_CONNECTION=database`, no long-running processes (no Redis, no supervisor, no websockets).

## Rules
- Code, variables and tables in English; visible text in Spanish.
- Secrets live only in `.env` (never in code, repo or chat).
- Never assume exactly two companies: everything is driven by the `companies` table.
- Company data carries `company_id` and the `BelongsToCompany` global scope; `companies.view_all` disables it.
- Permissions are prefixed by module (`ai.*`, `assistant.*`, reserved `monitoring.*`, `tickets.*`, `inventory.*`, `automations.*`). `ai.vault.access` is never inherited from a role.
- Modules are registered in `config/modules.php`; the menu comes from the `modules` shared prop.

## Ownership (contract with the design agent)
- Design owns: `resources/css/`, `resources/js/Components/ui/`, `resources/js/Layouts/`, `resources/js/Pages/`, `resources/js/types/`, `resources/js/mocks/`, `ui-preview/`.
- App owns: PHP, `routes/`, controllers, `HandleInertiaRequests`. Each controller returns exactly the props of its page type and is covered by an `assertInertia` test.
- If a page needs a missing field, add it to the type and mock in the same PR and flag it for design; never edit visual components.

## Commands
- `php artisan test` — test suite.
- `vendor/bin/pint` — code style.
- Deploy to cPanel (GitHub Actions, first install, cron, backups): `docs/deploy-cpanel.md`.

## Work tracking
Feature documents live in `odd/tasks/`.
