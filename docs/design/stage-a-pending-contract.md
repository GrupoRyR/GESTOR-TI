# Stage A pending pages · Frontend contract for the app agent (Notificaciones, ficha del colaborador)

Builds on [stage-a-contract.md](stage-a-contract.md): same shared props, layouts and conventions.

The server already rendered these two pages, but `main` had no React page for them. Jhon approved the mockups on 2026-10-20. They are on the design canvas, page "Etapa A · Base", row "Pendientes de la etapa A".

**The source of truth is the types files.** Each one starts with every route name, method, params, body and permission it uses.

| Types file | Page (Inertia component) | Mocks (gallery variants) |
|---|---|---|
| `types/pages/notifications.ts` | `Notifications/Index` | `mocks/notifications.ts`: `unread`, `allRead`, `secondPage`, `requester`, `empty`, `serverUnread` |
| `types/pages/admin-employees.ts` | `Admin/Employees/Show` | `mocks/admin-employees.ts`: `withReveal`, `noPermission`, `noArea`, `locked` |

To see each page with its props, run `npm run dev:preview` and use "Ver props".

## Notificaciones (`notifications.index`)
- **Props today:**
  - `notifications` is the Laravel paginator, `{data, links, meta}`.
  - Each item is `{id, type, data, read_at, created_at}`.
  - `data` is the `GestorNotification` payload `{event, title, body, url, company_id}` or the `AlertRaised` payload `{alert_id, level, source, title, body, company_id}`.
  - The page needs nothing else.
- **Module and icon** come from `data.event` (catalog in `config/notifications.php`). For alerts they come from `data.level`. Unknown events fall back by prefix (`ai.`, `vault.`, `assistant.`, `alert.`).
- **Opening an item:**
  - If it is unread and has a `url`, the page POSTs `notifications.read {id}` and visits the url when that succeeds.
  - Read items are plain links.
  - Items without `url` only get a "Marcar como leída" button.
- **"Marcar todas como leídas"** POSTs `notifications.read_all`. Both POSTs should redirect back, as today.
- **"Sin leer"** filters the current page on the client until the server sends `filters`.
- **Days** (Hoy, Ayer, Esta semana, then one group per date) use the Bogotá calendar and the device clock.

## Ficha del colaborador (`employees.show`)
- **Props today:**
  - `employee`
  - `accesses[]`, where `username` and `notes` are `string | null`
  - `can_reveal`, `can_manage`, `areas`, `access_systems`
- **Usernames, notes and "Ver contraseña"** appear only when `can_reveal` (`credentials.view`) is true.
  - The server must send `username` and `notes` as `null` without that permission. That fix is in PR #54.
  - Passwords never travel in props.
- **Revealing a password:**
  - It is the **only direct server call** in the pages: `fetch` POST to `employees.accesses.reveal {employee, access}`.
    - It sends credentials `same-origin`, CSRF from the `XSRF-TOKEN` cookie (falling back to `meta[name=csrf-token]`), and `cache: no-store`.
    - It expects JSON `{value, expires_in}`.
  - The value lives only in component state. It clears at `expires_in` (at most 120 s), on "Ocultar" and on unmount, and is never logged.
  - **Errors:** a 403 shows the server's Spanish `message`; a 429 shows "Demasiados intentos. Espera un minuto."; a 419 asks to reload. Keep the no-store headers and the `credential_revealed` audit.
- **Area:**
  - With `can_manage`, a select PUTs `employees.update {area_id}`. A number fixes the area (`area_locked`); `null` goes back to the Microsoft department mapping.
  - Without `can_manage`, the section is read-only.
- **"Marcar como inactivo"** asks for confirmation, then PUTs `employees.update {status: 'inactive'}`. It requires `can_manage`.
- **No access forms:** the page has no forms to add, edit or delete accesses; they were not in the approved mockups.

## Requested from the app
The pages already render without these fields. Add them when you can; the types mark them as optional.

**Notificaciones**
1. An `unread=1` query param on `notifications.index`, and send `filters: {unread: boolean}` back. The toggle then reloads from the server.
2. `now` (server time, ISO 8601), so Hoy and Ayer match the server and not the device.

**Ficha del colaborador**
1. Company, for the company badge: `employee.company_id`, or `{id, name, short}`.
2. `employee.user_roles` (role names) for "En el Gestor".
3. `manager.job_title`.
4. `reports[]` (`{id, name, job_title}`) for "Le reportan". The section is hidden while this is missing.

## Open points
- **Status labels:** the backend has no gender field, so they read "Activo" and "Inactivo".
- **Gallery:** in `ui-preview`, a shim (`ui-preview/shims/revealFetch.ts`) answers the reveal call with `[CONTRASEÑA]`. Production code has no preview branch.
