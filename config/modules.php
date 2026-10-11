<?php

/*
|--------------------------------------------------------------------------
| Platform modules
|--------------------------------------------------------------------------
|
| Ordered list of modules, keyed by module key. The order here is the menu
| order shared with the frontend through the `modules` Inertia prop
| (`ModuleItem` in resources/js/types/shared.ts).
|
| - name:       visible label (Spanish).
| - icon:       icon token resolved by the frontend (`Components/ui/Icon`);
|               by convention it equals the module key.
| - route:      route name of the module entry page (resolved with Ziggy).
|               Must exist for every active module (ModulesTest).
| - active:     whether the module is enabled. Inactive modules are shared
|               with `active: false` (the menu hides them) and their routes
|               answer 404 (`module:` middleware).
| - permission: permission required to see an active module, or null for
|               any authenticated user.
|
*/

return [

    'home' => [
        'name' => 'Inicio',
        'icon' => 'home',
        'route' => 'home',
        'active' => true,
        'permission' => null,
    ],

    'ai' => [
        'name' => 'Gestor IA',
        'icon' => 'ai',
        'route' => 'ai.index',
        'active' => (bool) env('MODULE_AI_ENABLED', true),
        'permission' => 'ai.requests.create',
    ],

    'assistant' => [
        'name' => 'Asistente',
        'icon' => 'assistant',
        'route' => 'assistant.index',
        'active' => (bool) env('MODULE_ASSISTANT_ENABLED', true),
        'permission' => 'assistant.use',
    ],

    'employees' => [
        'name' => 'Colaboradores',
        'icon' => 'employees',
        'route' => 'employees.index',
        'active' => (bool) env('MODULE_EMPLOYEES_ENABLED', true),
        'permission' => 'employees.view',
    ],

    'admin' => [
        'name' => 'Administración',
        'icon' => 'admin',
        'route' => 'admin.companies.index',
        'active' => (bool) env('MODULE_ADMIN_ENABLED', true),
        'permission' => 'admin.access',
    ],

    // Reserved modules (open doors, no routes yet).

    'monitoring' => [
        'name' => 'Monitoreo',
        'icon' => 'monitoring',
        'route' => 'monitoring.index',
        'active' => (bool) env('MODULE_MONITORING_ENABLED', false),
        'permission' => 'monitoring.view',
    ],

    'tickets' => [
        'name' => 'Tickets',
        'icon' => 'tickets',
        'route' => 'tickets.index',
        'active' => (bool) env('MODULE_TICKETS_ENABLED', false),
        'permission' => 'tickets.view',
    ],

    'inventory' => [
        'name' => 'Inventario',
        'icon' => 'inventory',
        'route' => 'inventory.index',
        'active' => (bool) env('MODULE_INVENTORY_ENABLED', false),
        'permission' => 'inventory.view',
    ],

    'operations' => [
        'name' => 'Operación',
        'icon' => 'operations',
        'route' => 'operations.index',
        'active' => (bool) env('MODULE_OPERATIONS_ENABLED', false),
        'permission' => 'operations.view',
    ],

];
