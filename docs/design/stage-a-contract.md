# Stage A · Frontend contract for the app agent

Design-owned frontend for Stage A (Base) of Gestor TI RYR. Approved mockups: design canvas, page "Etapa A · Base".
**The source of truth is the code**: each `resources/js/types/pages/{module}.ts` starts with the exact route names (method, params, body) and permissions its pages use. This document is the map.

## How to see it

```bash
npm install
npm run dev:preview      # gallery on http://localhost:5174
npm test                 # helper tests (Vitest)
npm run typecheck
```

The gallery lists every page × mock variant, renders it at phone (390), tablet (834) or desktop (1440) width, in light or dark, with any company brand, and shows the variant's props as JSON ("Ver props"). Those props are what the controller must return.

## What the app agent wires

| Inertia component | Types | Main routes (see types file for all) |
|---|---|---|
| `Auth/Login` | `types/pages/auth.ts` | `login`, `auth.microsoft.redirect`, `login.emergency` |
| `Auth/Emergency` | `auth.ts` | `login.emergency.store` (POST) |
| `Auth/CompanyNotEnabled` | `auth.ts` | `auth.microsoft.redirect` (`prompt=select_account`) |
| `Home` (`view: 'it' \| 'requester'`) | `home.ts` | `home`, `ai.requests.*`, `ai.approvals.show`, `ai.ratings.create`, `ai.vault.index` |
| `Employees/Index` | `employees.ts` | `employees.index`, `employees.show`, `employees.create`, `employees.sync` (POST) |
| `Profile/Edit` | `profile.ts` | `profile.edit`, `profile.notifications.update` (PUT), `profile.telegram.destroy` (DELETE) |
| `Profile/TelegramLink` | `profile.ts` | `profile.telegram.create`, `profile.telegram.store` (POST) |
| `Errors/Error` (403/404/419/500/503) | `errors.ts` | `home` |
| `Admin/Companies/Index` · `Admin/Companies/Create` | `admin.ts` | `admin.companies.{index,edit,update,create,store}` |
| `Admin/Areas/Index` | `admin.ts` | `admin.areas.{index,store,update,mappings.update,approval.update}` |
| `Admin/Users/Index` | `admin.ts` | `admin.users.{index,show,roles.update,deactivate}` |
| `Admin/Roles/Index` | `admin.ts` | `admin.roles.{index,update,store}` |
| `Admin/Integrations/Index` | `admin.ts` | `admin.integrations.{index,microsoft.test,mail.test,telegram.test,github.update,ai.update}` |
| `Admin/Audit/Index` | `admin.ts` | `admin.audit.index`, `admin.audit.export` |

Layout routes used on every signed-in page: `company.switch` (POST `{ company_id: number|null }`), `search` (GET `q`), `notifications.index`, `profile.edit`, `logout` (POST), `home`, and each `modules[].route`.

## Shared props

Exactly as `resources/js/types/shared.ts`: `auth.user`, `companies`, `current_company_id` (`null` = "Todas"), `flash`, `notifications_unread`, `modules`. Guest pages receive `GuestSharedProps` (`auth.user = null`, `companies`, `flash`).

- The menu is built **only** from `modules[]` with `active: true`. Stage A keys and icons: `home` (home), `ai` (ai), `assistant` (assistant), `employees` (employees), `admin` (admin); reserved and inactive: `monitoring`, `tickets`, `inventory`, `operations`. `config/modules.php` needs `home`, `employees` and `admin` besides `ai` and `assistant`.
- The company selector shows only with `companies.view_all`.
- Company colors come from `companies[].colors`; the frontend derives accessible button/tint/ink colors for light and dark (`lib/brand.ts`). Logos: `logo_url` and `logo_dark_url` (the SVGs in `resources/brand/` are the current files, already fixed with a `viewBox`).

## Decisions to respect

- `ai.projects.approve` belongs **only** to the "Aprobador de proyectos" role (today Jhon Castro and Edward Ramanan). Not Súper Admin, not Líder IA.
- `ai.vault.access` is never a role permission; only Jhon.
- Admin companies list is sent as `admin_companies` (a page prop named `companies` would overwrite the shared one).
- Pages never call `usePage` or fetch; everything comes through props. Forms use Inertia `useForm`; validation errors are Laravel's `Record<string, string>`.
- Dates arrive as ISO 8601 and are shown in `es-CO`, `America/Bogota`.

## Still to do on the app side

- Inertia entry (`resources/js/app.tsx`) and the Laravel Vite config are not in this PR. Resolve pages with `import.meta.glob('./Pages/**/*.tsx')` and import `resources/css/app.css`. Theme: do not set a `dark` class; dark mode follows the system, and `data-theme="light|dark"` on `<html>` forces one.
- Each controller returns the props of its type, with an `assertInertia` test checking the keys.
- Placeholders in mocks that need real data: Edward Ramanan's job title, the Telegram bot name, the monthly AI spend cap, the GitHub organization, the IT support email.
