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
        'ai.approval.requested' => [
            'label' => 'Aprobaciones pendientes',
            'hint' => 'Una solicitud espera tu aprobación',
            'channels' => ['mail', 'telegram', 'database'],
        ],

        // Bóveda (Stage B).
        'vault.accessed' => [
            'label' => 'Acceso a la bóveda de credenciales',
            'hint' => 'Cada vez que alguien abre la bóveda',
            'channels' => ['mail', 'telegram', 'database'],
        ],

    ],

];
