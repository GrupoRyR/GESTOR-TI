<?php

/*
|--------------------------------------------------------------------------
| Notification event types (catalog)
|--------------------------------------------------------------------------
|
| Every `App\Notifications\GestorNotification` declares one of these event
| types. Users choose the channels per event in Perfil (`Profile/Edit`)
| (`notification_preferences`); without a choice the default channels below
| apply. `telegram` is dropped for users without a linked chat.
|
| events: key => [label (Spanish, visible), hint (Spanish, short line under
|         the label), channels (default),
|         required_channels (optional: always added, whatever the user chose)].
| The order here is the order of the preferences page.
|
*/

return [

    'channels' => ['mail', 'telegram', 'database'],

    'events' => [

        // Alerts (App\Services\Alerts\AlertService): `alert.{level}`.
        'alert.critical' => [
            'label' => 'Alertas críticas',
            'hint' => 'Fallas que necesitan atención inmediata; siempre llegan a la campana',
            'channels' => ['mail', 'telegram', 'database'],
            // Always delivered to the bell, even when the user unchecks it.
            'required_channels' => ['database'],
        ],
        'alert.warning' => [
            'label' => 'Alertas de atención',
            'hint' => 'Algo se está saliendo de lo normal, por ejemplo un plazo por vencer',
            'channels' => ['telegram', 'database'],
        ],
        'alert.info' => [
            'label' => 'Alertas informativas',
            'hint' => 'Novedades que no requieren acción',
            'channels' => ['database'],
        ],

        // Gestor IA (Stage B).
        'ai.request.status_changed' => [
            'label' => 'Cambio de estado de mis solicitudes',
            'hint' => 'Las que tú pediste',
            'channels' => ['mail', 'database'],
        ],
        'ai.rating.requested' => [
            'label' => 'Calificar lo que me entregaron',
            'hint' => 'Cuando algo que pediste llega a producción, y un recordatorio a los 3 días',
            'channels' => ['mail', 'database'],
        ],
        'ai.rating.hours' => [
            'label' => 'Horas que me ahorra',
            'hint' => 'Una pregunta corta un mes después de la entrega',
            'channels' => ['mail', 'database'],
        ],
        'ai.approval.requested' => [
            'label' => 'Aprobaciones pendientes',
            'hint' => 'Una solicitud espera tu aprobación',
            'channels' => ['mail', 'telegram', 'database'],
        ],

        // Gestor IA projects (B5/B6).
        'ai.requirement.requested' => [
            'label' => 'Requerimientos que TI me pide',
            'hint' => 'Un dato, un acceso o una validación para un proyecto, y sus recordatorios',
            'channels' => ['mail', 'telegram', 'database'],
        ],
        'ai.requirement.answered' => [
            'label' => 'Requerimientos respondidos',
            'hint' => 'Alguien respondió lo que TI pidió para un proyecto',
            'channels' => ['database'],
        ],
        'ai.task.unblocked' => [
            'label' => 'Tareas desbloqueadas',
            'hint' => 'Una tarea tuya ya puede seguir porque llegó lo que esperaba',
            'channels' => ['telegram', 'database'],
        ],
        'ai.project.budget' => [
            'label' => 'Avisos de presupuesto de proyectos',
            'hint' => 'Un proyecto llegó al 80 % o pasó el 100 % de lo aprobado',
            'channels' => ['mail', 'telegram', 'database'],
        ],
        'ai.project.reapproval' => [
            'label' => 'Nuevas aprobaciones de proyectos',
            'hint' => 'Un proyecto pasó lo aprobado y espera que lo apruebes otra vez',
            'channels' => ['mail', 'telegram', 'database'],
        ],
        'ai.hours.reminder' => [
            'label' => 'Recordatorio de horas',
            'hint' => 'El viernes, si no cargaste horas en la semana',
            'channels' => ['telegram', 'database'],
        ],

        // Bóveda (Stage B).
        'vault.accessed' => [
            'label' => 'Acceso a la bóveda de credenciales',
            'hint' => 'Cada vez que alguien abre la bóveda',
            'channels' => ['mail', 'telegram', 'database'],
        ],

    ],

];
