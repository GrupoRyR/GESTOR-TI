<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Scheduler (cPanel cron: `* * * * * php artisan schedule:run`)
|--------------------------------------------------------------------------
|
| Shared hosting has no supervisor and no long-running processes, so every
| task here must finish on its own. Times are America/Bogota.
|
| No `model:prune`: no model is Prunable yet (add it when one is).
|
*/

// Employees from Microsoft Graph (A6): queues one job per active company
// with a tenant; the database queue worker below runs them.
Schedule::command('gestor:sync-employees')
    ->dailyAt('05:00')
    ->timezone('America/Bogota')
    ->withoutOverlapping();

// Gestor IA: requests past their first-response deadline alert Jhon
// (`ai.manage`); grouped per request, so reruns never duplicate alerts.
Schedule::command('ai:first-response-alerts')
    ->everyFifteenMinutes()
    ->withoutOverlapping();

// Failed jobs older than 7 days.
Schedule::command('queue:prune-failed --hours=168')
    ->dailyAt('03:30')
    ->timezone('America/Bogota');

// Activity log retention: `delete_records_older_than_days` (365) in
// config/activitylog.php.
Schedule::command('activitylog:clean --force')
    ->monthlyOn(1, '03:00')
    ->timezone('America/Bogota');

// Backups (spatie/laravel-backup, config/backup.php): database + files to the
// private `local` disk. Clean first so the new archive always fits.
Schedule::command('backup:clean')
    ->dailyAt('01:30')
    ->timezone('America/Bogota')
    ->withoutOverlapping();

Schedule::command('backup:run')
    ->dailyAt('02:00')
    ->timezone('America/Bogota')
    ->withoutOverlapping();

Schedule::command('backup:monitor')
    ->dailyAt('08:00')
    ->timezone('America/Bogota');

// Database queue worker. The cron starts `schedule:run` every minute and
// there is no supervisor, so the worker drains the queue and exits
// (`--stop-when-empty`) within the minute (`--max-time=50`). It is defined
// last so the other tasks of the minute start first; the overlap lock
// expires after 2 minutes so a killed worker never blocks the queue for long.
Schedule::command('queue:work --stop-when-empty --max-time=50 --tries=3')
    ->everyMinute()
    ->withoutOverlapping(2);
