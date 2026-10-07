<?php

/*
|--------------------------------------------------------------------------
| Notification event types (catalog)
|--------------------------------------------------------------------------
|
| Every `App\Notifications\GestorNotification` declares one of these event
| types. Users choose the channels per event in Perfil › Notificaciones
| (`notification_preferences`); without a choice the default channels below
| apply. `telegram` is dropped for users without a linked chat.
|
| events: key => [label (Spanish, visible), channels (default),
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
            'channels' => ['mail', 'telegram', 'database'],
            // Always delivered to the bell, even when the user unchecks it.
            'required_channels' => ['database'],
        ],
        'alert.warning' => [
            'label' => 'Alertas de atención',
            'channels' => ['telegram', 'database'],
        ],
        'alert.info' => [
            'label' => 'Alertas informativas',
            'channels' => ['database'],
        ],

        // Gestor IA (Stage B).
        'ai.request.status_changed' => [
            'label' => 'Cambio de estado de mis solicitudes',
            'channels' => ['mail', 'database'],
        ],
        'ai.approval.requested' => [
            'label' => 'Aprobaciones pendientes',
            'channels' => ['mail', 'telegram', 'database'],
        ],

        // Bóveda (Stage B).
        'vault.accessed' => [
            'label' => 'Acceso a la bóveda de credenciales',
            'channels' => ['mail', 'telegram', 'database'],
        ],

    ],

];
