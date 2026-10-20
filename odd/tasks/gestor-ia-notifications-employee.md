# Gestor TI · Notifications page and employee detail

Locator: `odd/tasks/gestor-ia-notifications-employee.md` · Engram mirror: `odd/gestor-ia-notifications-employee/tasks`

## Objective
Build the two pages the server already renders but that have no React page in `main`. Both are linked from the UI, so production (ti.segurex.co) opens a broken page today.
- `Notifications/Index`: route `notifications.index`, opened from the bell.
- `Admin/Employees/Show`: route `employees.show`, opened from each row of Colaboradores.

## Source
- **Mockups:** design canvas https://claude.ai/artifact/VpJ8rdFwC3jeCVBiSjfn2m, page "Etapa A · Base", row "Pendientes de la etapa A". Boards `A-Notifications`, `A-NotificationsPhone`, `A-NotificationsEmpty`, `A-EmployeeDetail`, `A-EmployeeDetailPhone`.
- **Approval:** Jhon approved them on 2026-10-20.
- **"Ver contraseña":** shown only to viewers with `credentials.view` (`can_reveal`). It uses the existing `employees.accesses.reveal` endpoint: POST, JSON `{value, expires_in: 30}`, throttled to 10 per minute, logged as `credential_revealed`.

## Backend today (origin/main)
**Notifications**
- `notifications` is paginated, 20 per page: `{data[{id, type, data, read_at, created_at}], links, meta}`.
- Each item's `data` is `{event, title, body, url|null, company_id}`. `AlertRaised` sends `{alert_id, level, source, title, body, company_id}` instead.
- Routes, all `auth` only: `notifications.read` (POST `{id}`) and `notifications.read_all` (POST).
- Event catalog: `config/notifications.php`.

**Employee detail**
- `employee` with `area`, `manager`, `status`, `source`, `last_synced_at` and `area_locked`.
- `accesses[]` with `username` and `notes` as `string | null`. They are null without `credentials.view` once PR #54 lands.
- `can_reveal`, `can_manage`, `areas[]` and `access_systems[]`.
- `employees.update` (PUT, `area_id` or `status`) needs `employees.manage`.

**Gaps.** The pages handle them gracefully today; the contract asks the app to add them:
- unread filter param;
- top-level `event` / `module` on notifications;
- `company_id`, Gestor roles, manager `job_title` and direct reports on the employee.

## Scope (design-owned)
- `resources/js/types/pages/`
- `resources/js/Pages/Notifications/`
- `resources/js/Pages/Admin/Employees/`
- `resources/js/mocks/`
- `resources/js/lib/` and `resources/js/Components/**`, for helpers and components
- `docs/design/`

## Constraints
- Page contract: `PageProps<X>`, layout via `shared`, no `usePage`.
- **No Inertia fetch,** except the reveal call. It is the one place the page calls the server directly. The backend answers JSON with no-store headers, so the password never enters Inertia history.
- The password value lives only in component state, clears after `expires_in`, and is never logged.
- Spanish, sentence case, es-CO formats, 44 px targets.
- Test first for pure helpers: group by day, notification module/icon resolution, countdown.

## Delivery strategy
`ask-on-risk`; forecast about 900 authored lines. Branch `claude/diseno-notificaciones-ficha` from `main` (`2382f9a`), one PR.

## Tasks
- [x] T1 Notifications page.
  - Types, helpers (tests first), page, mocks: list, unread, empty, alert without url.
  - **Route:** delegated (writer trigger, several non-trivial files).
- [x] T2 Employee detail page.
  - Types, reveal hook or component (tests first for the countdown), page, mocks: with permission, without permission, no area, no user.
  - **Route:** delegated.
- [x] T3 Contract `docs/design/stage-a-pending-contract.md`, docs index, handoff, PR.
  - **Route:** inline.

## Acceptance criteria
- `npm run typecheck`, `npm test` and `npm run build` pass.
- Both pages appear in the `ui-preview` gallery with their variants.
- No password value is reachable from props, mocks or logs. The reveal button renders only when `can_reveal` is true.

## Progress
- 2026-10-20: branch created, document written.
- 2026-10-20: T1 done (delegated writer). RED 21/21 helper tests failing on a stub, then GREEN; typecheck, `npm test` (619 passed), `build` and `build:preview` pass. Page checked in ui-preview at phone, tablet and desktop. Commit 6460655.
- 2026-10-20: T2 done (delegated writer). RED 23/24 `accessReveal` tests failing on a stub, then GREEN 24/24; typecheck, `npm test` (643 passed), `build` and `build:preview` pass. Reveal (preview shim placeholder), hide, 30 s auto-hide and focus return checked in ui-preview at 375, 768 and 1280 px; no horizontal overflow. Commit f51e867.
- 2026-10-20: T3 inline: `docs/design/stage-a-pending-contract.md`, docs index; full checks before the PR.

## Next step
Merge the PR; the app agent then adds the requested optional props.
