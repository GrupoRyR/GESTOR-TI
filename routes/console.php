<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Employees from Microsoft Graph (A6): queues one job per active company
// with a tenant; the database queue worker runs them (A9).
Schedule::command('gestor:sync-employees')
    ->dailyAt('05:00')
    ->timezone('America/Bogota')
    ->withoutOverlapping();
