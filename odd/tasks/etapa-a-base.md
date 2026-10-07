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
- Branch: `claude/gestor-ia-app-prompt-d14b73`. Strategy: `ask-on-risk` → chain `stacked-to-main` (user, 2026-10-07). Slices: one PR per task group, each based on the previous.
- Forecast: ~3,500 authored lines (well over the 400-line budget) → chained PRs.
- RDD: off (global). Ordinary checks only.

## Tasks
- [x] A0 · Laravel 13.35 skeleton (generated; composer.phar verified by sha384). Route: inline (mechanical).
- [x] A1 · Inertia + React + TS wiring, packages (socialite, microsoft provider, spatie permission/activitylog/backup), project `CLAUDE.md`, `.env.example`, phpunit on SQLite. Route: delegated (writer trigger: 2+ non-trivial files).
- [x] A2 · Modules by configuration (`config/modules.php`) + shared Inertia props (`HandleInertiaRequests`). Route: delegated (writer trigger: 2+ non-trivial files).
- [x] A3 · Companies (configurable, sender mailbox, tenant), areas, `BelongsToCompany` global scope + company selector (`companies.view_all`). Route: delegated (writer trigger: 2+ non-trivial files).
- [x] A4 · Roles and permissions seeder (module prefixes, `ai.vault.access` exclusive), role × permission matrix endpoints. Route: delegated (writer trigger: 2+ non-trivial files).
  - Note (from A3): seed `admin.access`, `companies.manage`, `companies.manage_tenant`, `areas.manage`, and reserved `<module>.view` permissions used by config/modules.php (plus `companies.view_all`; `companies.manage_tenant` only for Súper Admin).
- [ ] A5 · Microsoft multitenant login (only active companies' tenants, company from `tid`) + emergency local login (lockout after 5).
- [ ] A6 · Employees + daily Graph sync per company (skip guests, department→area mapping), access systems, employee accesses (encrypted, reveal with audit).
- [ ] A7 · Notifications: preferences, Telegram link + webhook (secret in URL), `AlertService` + `alerts`, `monitored_apps`, `settings`.
- [ ] A8 · Audit log listing with filters.
- [ ] A9 · Scheduler (`queue:work --stop-when-empty`), backups, GitHub Actions deploy to cPanel over SSH.

## Acceptance
Each task: feature tests green (`php artisan test`), Pint clean, commit on the branch.

## Progress / evidence
- A0: skeleton created with `composer create-project laravel/laravel:^13.0`.
- A0 commit: e8a0a12.
- A1 packages: inertiajs/inertia-laravel 3.x (^3.5), tightenco/ziggy ^2.6 (2.6.4), laravel/socialite ^5.31, socialiteproviders/microsoft ^4.10, spatie/laravel-permission ^8.3 (8.3.0), spatie/laravel-activitylog ^4.12, spatie/laravel-backup ^10.3 (10.3.3); npm @inertiajs/react ^3.8, react/react-dom ^19.3, typescript ^7.0, @vitejs/plugin-react ^6.1.
- A1 RED: `php artisan test` → 3 errors "Route [dashboard] not defined." (InertiaSetupTest).
- A1 GREEN: `php artisan test` → passed, 4 tests, 28 assertions.
- A1 `vendor/bin/pint` → fixed 4 published vendor files; `vendor/bin/pint --test` → passed.
- A1 `npm run build` → built OK (no pages yet; glob empty); `npx tsc --noEmit` → exit 0.
- A1 `php artisan route:list --except-vendor` → `/` redirect (dashboard is `Route::inertia`, listed without --except-vendor: GET dashboard).
- A1 migrations run on SQLite in-memory (users, cache, jobs, permission tables, activity_log x3).
- A1 commit: f064fed.
- A1 notes: config/inertia.php → SSR off by default (cPanel), pages path `js/Pages`, `testing.ensure_pages_exist` env-driven default false; tests call `withoutVite()` in TestCase; `npm audit` reports 2 critical in skeleton dev dep `concurrently`→`shell-quote` (pre-existing, not fixed).
- A2 RED: `php artisan test` → 15 tests, 4 passed, 1 failure (`auth.user` missing), 10 errors (ModuleRegistry missing, `assignRole` undefined, no `notifications` table, `ai.index` undefined).
- A2 first GREEN attempt: 14/15; guest on disabled `ai` got 302 because `auth` (priority list) ran before `module:`. Fix: `prependToPriorityList(before: AuthenticatesRequests, prepend: EnsureModuleIsActive)`.
- A2 GREEN: `php artisan test` → passed, 15 tests, 176 assertions.
- A2 `vendor/bin/pint` → passed; `vendor/bin/pint --test` → passed.
- A2 `php artisan route:list --path=ai` → GET ai `ai.index`; `--path=admin` → GET admin `admin.index`; `--path=login` → GET login `login` (temporary, A5).
- A2 commit: b9e525a (10 files, +503/-2).
- A2 notes: `config/modules.php` (ai, assistant, monitoring, tickets, inventory, automations "Operación", admin; `MODULE_*_ENABLED` env flags); `App\Support\Modules\ModuleRegistry` (`isActive`, `visibleTo`); `module:{key}` alias → `EnsureModuleIsActive` (404); `User` uses `HasRoles`; `notifications` table; shared props per §7.1 (`companies` [] and `current_company_id` null until A3; Ziggy via `@routes`, not shared). Module gating permissions: `ai.requests.create`, `assistant.use`, `admin.access` (A4 must seed `admin.access` and the reserved `*.view`).
- A3 RED: `php artisan test` → 56 tests, 15 passed, 41 errors (`App\Models\Company` not found, `Database\Seeders\CompanySeeder` does not exist).
- A3 GREEN: `php artisan test` → passed, 56 tests, 445 assertions.
- A3 `vendor/bin/pint` → passed; `vendor/bin/pint --test` → passed.
- A3 `migrate:fresh --seed` on a temp SQLite file → OK (2 companies, 18 areas); `migrate:rollback --step=3` → OK. MySQL 8 not run locally (verify on server).
- A3 commit: 8c6cced (33 files, +2065/-19, of which ~750 lines are tests). Over the ~400-line heuristic: one coherent slice (tables, scope, selector, admin endpoints and their tests).
- A3 notes:
  - `companies` (name, short_name, slug unique, tenant_id unique nullable, domains json, logo_path/logo_dark_path → `logo_url`/`logo_dark_url` via `public` disk, colors json, sender_email/sender_name, is_active) + `CompanySeeder` (segurex, derivados; `updateOrCreate` by slug). Activity log: all attributes except timestamps, only dirty.
  - `areas` (company_id FK cascade, name, slug unique per company, is_active) + `AreaSeeder` (9 default areas for every company, `firstOrCreate`, does not overwrite edits).
  - `users`: company_id FK (null on delete), avatar_url, azure_id unique, tenant_id, is_global, last_login_at. Only `avatar_url` added to fillable; identity/privilege columns need `forceFill()` (A5).
  - `App\Support\Tenancy\CurrentCompany` (singleton, resolved per call, never cached): `id()`, `isAll()`, `set()`, `forUser()`, `runAs()`, `canViewAll()`. No user → no filter. Restricted user without company → sees nothing (`1 = 0`).
  - `App\Models\Concerns\BelongsToCompany` + `App\Models\Scopes\CompanyScope`; applied to `Area` (route binding honours it → 404 across companies).
  - `POST /company/switch` (`company.switch`): 403 without `companies.view_all`; `company_id` must be present, null = Todas, else an active company (422/redirect with errors).
  - Shared props: `companies` (active; all with `companies.view_all`, else own; ordered by name), `current_company_id` from `CurrentCompany::forUser`.
  - Admin: `admin.companies.{index,store,update}` (`can:companies.manage`); store and tenant change need `companies.manage_tenant` → **403** (decision: authorization failure, not validation). Sending the unchanged tenant or omitting it is allowed. `admin.areas.{index,store,update}` (`can:areas.manage`); `Admin/Areas/Index` props `{areas: list (not paginated: few rows per company), filters: {search}}`; global user in "Todas" must send `company_id`; restricted user always creates in own company.
  - Pending: logo upload (only paths for now); design must add `Admin/Companies/Index` and `Admin/Areas/Index` page types (props listed above).

- A4 RED: `php artisan test` → 87 tests, 56 passed, 31 errors (`RolesAndPermissionsSeeder` missing, `config('permissions.permissions')` null).
- A4 regression found while seeding a temp SQLite file: `migrate:fresh --seed` → `PermissionDoesNotExist admin.access` (DatabaseSeeder's `WithoutModelEvents` mutes the cache-refresh events). Test `test_database_seeder_seeds_roles_with_model_events_muted` observed RED, fixed with an explicit `forgetCachedPermissions()` after creating permissions → GREEN.
- A4 GREEN: `php artisan test` → passed, 88 tests, 672 assertions.
- A4 `vendor/bin/pint` → passed; `vendor/bin/pint --test` → passed.
- A4 `migrate:fresh --seed` on a temp SQLite file (with `GESTOR_SUPER_ADMIN_EMAILS`/`GESTOR_VAULT_OWNER_EMAIL=test@example.com`) → 5 roles, 30 permissions, test user `Súper Admin` + vault. Re-running plain `db:seed` fails on the pre-existing skeleton `Test User` factory (unique email), not on A4 seeders.
- A4 `php artisan route:list --path=admin` → `admin.roles.index` GET, `admin.roles.update` PUT, `admin.users.roles.update` PUT.
- A4 commit: 9ec8028 (21 files, +1350/-1, ~600 lines are tests). Over the ~400-line heuristic: one coherent slice (catalog, guards, seeders, actions, endpoints and tests).
- A4 notes:
  - `config/permissions.php` is the single source of truth: 30 permissions (`label` Spanish, `module` key of config/modules.php, `exclusive`), 5 roles (`Súper Admin` = `'*'` = every non-exclusive permission; Líder IA = ai.* + assistant.* except vault and `assistant.credentials`, plus `companies.view_all`, no `admin.access`; `credentials.view`/`assistant.credentials` only in Súper Admin). Tests assert every `can:` route middleware and every module permission is seeded.
  - `App\Models\Role` / `App\Models\Permission` extend spatie's and are registered in `AppServiceProvider::register()` via `config(['permission.models.*'])` (config/permission.php untouched). `ai.vault.access` (`Permission::VAULT`, always exclusive even if the config changes) throws `ExclusivePermissionException` when attached to a role (`givePermissionTo`/`syncPermissions` on Role, `assignRole`/`syncRoles` on Permission) or granted to a user outside `Permission::withExclusiveGrant()` (User overrides `givePermissionTo`/`syncPermissions`). No `Gate::before`: Súper Admin does not bypass the vault.
  - `App\Actions\Users\GrantVaultAccess::handle($actor, $target)`: actor must hold `ai.vault.access` (else `AuthorizationException`), grant is direct, activity `vault_access_granted`. No UI.
  - `App\Actions\Users\AssignInitialRoles::handle($user)` (for A5 first login; additive, idempotent): emails from `config/gestor.php` (`GESTOR_SUPER_ADMIN_EMAILS`, `GESTOR_PROJECT_APPROVER_EMAILS`, `GESTOR_VAULT_OWNER_EMAIL`, comma separated, lower-cased) → `Súper Admin` + `is_global` (forceFill), `Aprobador de proyectos`, direct vault permission (logged); others without roles → `Usuario final`. `InitialUsersSeeder` applies it to existing users; registered in DatabaseSeeder after `RolesAndPermissionsSeeder`.
  - Matrix: `admin.roles.index` (`can:roles.manage`) → `Admin/Roles/Index` props `{roles: [{id, name, users_count, permissions: string[]}], permissions: [{name, label, module, exclusive}], modules: [{key, name}]}`; `admin.roles.update` body `permissions: string[]` (exists, distinct, exclusive → 422 "es exclusivo y no se puede asignar a un rol"), activity `role_permissions_updated` with old/attributes, flash "Permisos del rol actualizados."; `admin.users.roles.update` (`can:users.manage`) body `roles: string[]`, activity `user_roles_updated`, flash "Roles del usuario actualizados.".
  - Re-running `RolesAndPermissionsSeeder` resets roles to the config (syncPermissions), overwriting matrix edits. Decide before deploy automation runs seeders.
  - Pending: `.env.example` keys not added (file is blocked by a read deny rule for the writer): `GESTOR_SUPER_ADMIN_EMAILS=jhon.castro@segurex.com`, `GESTOR_PROJECT_APPROVER_EMAILS=jhon.castro@segurex.com`, `GESTOR_VAULT_OWNER_EMAIL=jhon.castro@segurex.com`, with a comment that Leonardo's and Edward Ramanan's emails are pending. Leonardo (Súper Admin) and Edward Ramanan (Aprobador de proyectos) emails pending from Jhon. Design must add the `Admin/Roles/Index` page type (props above).

## Next step
A5.
