<?php

/*
|--------------------------------------------------------------------------
| Platform modules
|--------------------------------------------------------------------------
|
| Ordered list of modules, keyed by module key. The order here is the menu
| order shared with the frontend through the `modules` Inertia prop.
|
| - name:       visible label (Spanish).
| - icon:       icon token resolved by the frontend (lucide icon name).
| - route:      route name of the module entry page (resolved with Ziggy).
| - active:     whether the module is enabled. Inactive modules are hidden
|               from the menu and their routes answer 404 (`module:` middleware).
| - permission: permission required to see the module, or null for any
|               authenticated user.
|
*/

return [

    'ai' => [
        'name' => 'Gestor IA',
        'icon' => 'sparkles',
        'route' => 'ai.index',
        'active' => (bool) env('MODULE_AI_ENABLED', true),
        'permission' => 'ai.requests.create',
    ],

    'assistant' => [
        'name' => 'Asistente TI',
        'icon' => 'bot',
        'route' => 'assistant.index',
        'active' => (bool) env('MODULE_ASSISTANT_ENABLED', true),
        'permission' => 'assistant.use',
    ],

    'monitoring' => [
        'name' => 'Monitoreo',
        'icon' => 'activity',
        'route' => 'monitoring.index',
        'active' => (bool) env('MODULE_MONITORING_ENABLED', false),
        'permission' => 'monitoring.view',
    ],

    'tickets' => [
        'name' => 'Tickets',
        'icon' => 'ticket',
        'route' => 'tickets.index',
        'active' => (bool) env('MODULE_TICKETS_ENABLED', false),
        'permission' => 'tickets.view',
    ],

    'inventory' => [
        'name' => 'Inventario',
        'icon' => 'package',
        'route' => 'inventory.index',
        'active' => (bool) env('MODULE_INVENTORY_ENABLED', false),
        'permission' => 'inventory.view',
    ],

    'automations' => [
        'name' => 'Operación',
        'icon' => 'workflow',
        'route' => 'automations.index',
        'active' => (bool) env('MODULE_AUTOMATIONS_ENABLED', false),
        'permission' => 'automations.view',
    ],

    'admin' => [
        'name' => 'Administración',
        'icon' => 'settings',
        'route' => 'admin.index',
        'active' => (bool) env('MODULE_ADMIN_ENABLED', true),
        'permission' => 'admin.access',
    ],

];
