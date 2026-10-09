<?php

namespace App\Http\Controllers\AI;

use App\Domain\Ai\Week;
use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "Mi semana" (`Ai/Week/Index`, WeekIndexProps). Items come from
 * App\Domain\Ai\Week; the running timer arrives with time entries (B6) and
 * the weekly review with projects (B5), so both are null for now.
 */
class WeekController extends Controller
{
    public function __invoke(Week $week): Response
    {
        return Inertia::render('Ai/Week/Index', [
            'today' => Week::today()->toDateString(),
            'now' => now()->toIso8601String(),
            'week_ends_on' => Week::endsOn()->toDateString(),
            'items' => $week->items(),
            // TODO B6: the viewer's running timer.
            'running_timer' => null,
            // TODO B5: weekly review agenda ("Revisión semanal con Leonardo").
            'review' => null,
        ]);
    }
}
