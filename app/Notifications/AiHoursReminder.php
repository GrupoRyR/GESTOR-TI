<?php

namespace App\Notifications;

use Illuminate\Support\Facades\Route;

/**
 * Friday reminder (`ai:hours-reminder`, PROMPT 6.4.11 "recordatorio el
 * viernes a quien no cargó horas de la semana"), event `ai.hours.reminder`.
 */
class AiHoursReminder extends GestorNotification
{
    public function eventType(): string
    {
        return 'ai.hours.reminder';
    }

    public function title(): string
    {
        return 'No has cargado horas esta semana';
    }

    public function body(): string
    {
        return 'Carga lo que trabajaste en los proyectos antes de cerrar la semana. También puedes hacerlo desde Telegram con /horas GIA-123 2 qué hiciste.';
    }

    public function actionUrl(): ?string
    {
        return Route::has('ai.time-entries.create') ? route('ai.time-entries.create') : null;
    }

    public function actionText(): string
    {
        return 'Agregar horas';
    }
}
