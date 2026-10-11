<?php

/*
|--------------------------------------------------------------------------
| Permissions and roles (single source of truth)
|--------------------------------------------------------------------------
|
| Seeded by `Database\Seeders\RolesAndPermissionsSeeder` and served to the
| role × permission matrix (`admin.roles.index`).
|
| permissions: name => definition, grouped by module prefix. The order here is
| the matrix order.
|   - label:     visible text of the matrix (Spanish).
|   - module:    module key of config/modules.php the permission belongs to
|                (platform base permissions belong to `admin`).
|   - exclusive: never granted through a role. It can only be granted directly
|                to a user (`App\Actions\Users\GrantVaultAccess`). Models and
|                the matrix endpoint reject attaching it to a role.
|   - explicit:  granted through roles, but never by the '*' wildcard: a role
|                gets it only by listing it (`ai.projects.approve`).
|
| Every permission referenced by config/modules.php or by a route `can:`
| middleware must be listed here (covered by RolesAndPermissionsSeederTest).
|
| roles: Spanish role name => list of permissions, or '*' for every
| non-exclusive, non-explicit permission. Choices (PROMPT_GESTOR_IA_APP.md §3
| and §6.4.10, user decision 2026-10-09):
|   - Súper Admin: everything except the vault (`ai.vault.access`) and
|     project approval (`ai.projects.approve`).
|   - Líder IA: the whole Gestor IA and Asistente TI (except the vault,
|     `ai.projects.approve` and `assistant.credentials`) plus
|     `companies.view_all` (global scope) and `employees.view` (Colaboradores;
|     create/sync come with `ai.manage`). No `admin.access`: administration
|     stays with Súper Admin.
|   - `ai.projects.approve` belongs only to Aprobador de proyectos (holders
|     come from GESTOR_PROJECT_APPROVER_EMAILS).
|   - `credentials.view` and `assistant.credentials` (asking for and viewing
|     passwords) are only in Súper Admin (Jhon and Leonardo).
|   - Aprobador de área: company/area scope, no `companies.view_all`.
|   - Aprobador de proyectos: global, sees costs.
|   - Usuario final: creates and follows requests. Assigned on first login.
| The matrix can change these assignments afterwards; re-running the seeder
| resets them to these definitions.
|
*/

return [

    'permissions' => [

        // Platform base (Administración).
        'admin.access' => ['label' => 'Acceder a Administración', 'module' => 'admin'],
        'companies.view_all' => ['label' => 'Ver todas las empresas', 'module' => 'admin'],
        'companies.manage' => ['label' => 'Administrar empresas', 'module' => 'admin'],
        'companies.manage_tenant' => ['label' => 'Crear empresas y cambiar su inquilino', 'module' => 'admin'],
        'areas.manage' => ['label' => 'Administrar áreas', 'module' => 'admin'],
        'employees.view' => ['label' => 'Ver colaboradores', 'module' => 'employees'],
        'employees.manage' => ['label' => 'Administrar colaboradores y sus accesos', 'module' => 'employees'],
        'credentials.view' => ['label' => 'Ver contraseñas de los accesos', 'module' => 'employees'],
        'roles.manage' => ['label' => 'Administrar roles y permisos', 'module' => 'admin'],
        'users.manage' => ['label' => 'Administrar usuarios y sus roles', 'module' => 'admin'],
        'audit.view' => ['label' => 'Ver auditoría', 'module' => 'admin'],
        'settings.manage' => ['label' => 'Administrar configuración', 'module' => 'admin'],

        // Gestor IA.
        'ai.manage' => ['label' => 'Administrar el Gestor IA', 'module' => 'ai'],
        'ai.requests.create' => ['label' => 'Crear y seguir solicitudes', 'module' => 'ai'],
        'ai.requests.approve_area' => ['label' => 'Aprobar solicitudes del área', 'module' => 'ai'],
        'ai.projects.approve' => ['label' => 'Aprobar proyectos', 'module' => 'ai', 'explicit' => true],
        'ai.costs.view' => ['label' => 'Ver costos', 'module' => 'ai'],
        'ai.costs.manage' => ['label' => 'Administrar costos', 'module' => 'ai'],
        'ai.area.view' => ['label' => 'Ver lo del área', 'module' => 'ai'],
        'ai.docs.edit' => ['label' => 'Editar documentación', 'module' => 'ai'],
        'ai.github.manage' => ['label' => 'Administrar conexión con GitHub', 'module' => 'ai'],
        'ai.mcp.use' => ['label' => 'Usar el conector MCP', 'module' => 'ai'],
        'ai.vault.access' => ['label' => 'Acceder a la bóveda de credenciales', 'module' => 'ai', 'exclusive' => true],

        // Asistente TI.
        'assistant.use' => ['label' => 'Usar el Asistente TI', 'module' => 'assistant'],
        'assistant.knowledge.manage' => ['label' => 'Administrar la base de conocimiento', 'module' => 'assistant'],
        'assistant.credentials' => ['label' => 'Pedir credenciales al Asistente TI', 'module' => 'assistant'],

        // Reserved modules (seeded, not used yet).
        'monitoring.view' => ['label' => 'Ver monitoreo', 'module' => 'monitoring'],
        'tickets.view' => ['label' => 'Ver tickets', 'module' => 'tickets'],
        'inventory.view' => ['label' => 'Ver inventario', 'module' => 'inventory'],
        'operations.view' => ['label' => 'Ver operación', 'module' => 'operations'],

    ],

    'roles' => [

        'Súper Admin' => '*',

        'Líder IA' => [
            'companies.view_all',
            'employees.view',
            'ai.manage',
            'ai.requests.create',
            'ai.requests.approve_area',
            'ai.costs.view',
            'ai.costs.manage',
            'ai.area.view',
            'ai.docs.edit',
            'ai.github.manage',
            'ai.mcp.use',
            'assistant.use',
            'assistant.knowledge.manage',
        ],

        'Aprobador de área' => [
            'ai.requests.create',
            'ai.requests.approve_area',
            'ai.area.view',
        ],

        'Aprobador de proyectos' => [
            'companies.view_all',
            'ai.requests.create',
            'ai.projects.approve',
            'ai.costs.view',
        ],

        'Usuario final' => [
            'ai.requests.create',
        ],

    ],

    /*
    | Metadata of the built-in roles above (Administración › Roles y permisos
    | and › Usuarios). RolesAndPermissionsSeeder writes it to the `roles`
    | table with `is_system = true`; custom roles created from the page keep
    | theirs there too (`is_system = false`).
    |   - scope: global | company | company_area (labels in `scopes`). It
    |     describes the role; data scoping itself comes from the permissions
    |     (`companies.view_all`, `ai.area.view`).
    |   - requires_area: the Usuarios page asks for the area the person
    |     approves (`areas.approver_user_id`).
    */
    'role_metadata' => [
        'Súper Admin' => ['label' => 'Súper Admin', 'scope' => 'global', 'description' => 'Administra empresas, usuarios e integraciones'],
        'Líder IA' => ['label' => 'Líder IA', 'scope' => 'global', 'description' => 'Evalúa, planea y entrega los desarrollos'],
        'Aprobador de área' => ['label' => 'Aprobador de área', 'scope' => 'company_area', 'description' => 'Da el visto bueno a las solicitudes de su área', 'requires_area' => true],
        'Aprobador de proyectos' => ['label' => 'Aprobador de proyectos', 'scope' => 'global', 'description' => 'Ve costos y aprueba qué se vuelve proyecto'],
        'Usuario final' => ['label' => 'Usuario final', 'scope' => 'company', 'description' => 'Pide desarrollos y ve los suyos'],
    ],

    // Scope key => visible label (`AdminRole.scope`, "Agregar rol" options).
    'scopes' => [
        'global' => 'Global',
        'company' => 'Empresa',
        'company_area' => 'Empresa y área',
    ],

    /*
    | Role × permission matrix of Administración › Roles y permisos: groups,
    | labels and hints exactly as the design. Only these permissions can be
    | changed from the page; every other permission (admin.access,
    | companies.manage, areas.manage, users.manage, roles.manage, audit.view,
    | settings.manage, companies.manage_tenant, employees.*, the reserved
    | modules) stays managed by this file and RolesAndPermissionsSeeder, and
    | a matrix save never touches it. `ai.vault.access` is never a role
    | permission and is never listed. `ai.projects.approve` may only be
    | checked for `project_approval_role`.
    */
    'matrix' => [
        'ai' => [
            'label' => 'Gestor IA',
            'permissions' => [
                'ai.manage' => 'Gestionar todo el Gestor IA',
                'ai.requests.create' => 'Crear solicitudes',
                'ai.requests.approve_area' => 'Dar visto bueno del área',
                'ai.projects.approve' => 'Aprobar proyectos',
                'ai.costs.view' => 'Ver costos',
                'ai.costs.manage' => 'Gestionar costos y tarifas',
                'ai.area.view' => 'Ver lo de su área',
                'ai.docs.edit' => 'Editar documentación',
                'ai.github.manage' => 'Conectar GitHub',
                'ai.mcp.use' => 'Usar el conector de Claude',
            ],
        ],
        'assistant' => [
            'label' => 'Asistente TI',
            'permissions' => [
                'assistant.use' => 'Usar el Asistente',
                'assistant.knowledge.manage' => 'Administrar la base de conocimiento',
                'assistant.credentials' => 'Pedir contraseñas al Asistente',
            ],
        ],
        'base' => [
            'label' => 'Base',
            'permissions' => [
                'companies.view_all' => 'Ver todas las empresas',
                'credentials.view' => 'Ver contraseñas de colaboradores',
            ],
        ],
    ],

    // The only role that may hold `ai.projects.approve` (user decision 2026-10-09).
    'project_approval_role' => 'Aprobador de proyectos',

];
