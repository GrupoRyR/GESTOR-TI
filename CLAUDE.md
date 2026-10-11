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
- Shared root files have one owner; the other agent asks instead of editing:
  - The app owns `package.json`, `package-lock.json`, `composer.*`, `tsconfig.json`, `vite.config.*` and `.gitignore`.
  - Design owns `resources/css/app.css`.

## Delivery (both agents)
- `main` is the only source of truth. Every stage starts from an up-to-date `main`.
  - Never merge another agent's unmerged branch into yours. If you need it, wait until it lands in `main`.
- Merge per stage, not at the end:
  1. The design PRs of the stage land in `main`.
  2. The app agent updates from `main` and connects the stage.
  3. The app PRs of the stage land in `main`.
  4. The next stage starts.
- Keep stacks short: at most about 6 open PRs per agent. Past that, merge what is ready before stacking more.
- Before opening or updating a PR, update from `main` and run the checks (`php artisan test`, `vendor/bin/pint --test`, `npm run typecheck`, `npm test`, `npm run build`). A PR that conflicts with `main` is fixed the same day.

## Commands
- `php artisan test` — test suite.
- `vendor/bin/pint` — code style.
- Deploy to cPanel (GitHub Actions, first install, cron, backups): `docs/deploy-cpanel.md`.

## Work tracking
Feature documents live in `odd/tasks/`.
