<?php

namespace App\Http\Controllers\AI;

use App\Domain\Ai\Week;
use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "Mi semana" (`Ai/Week/Index`, WeekIndexProps). Items come from
 * App\Domain\Ai\Week (requests, tasks and requirements since B5). The
 * running timer arrives with the timer (B6); the weekly review
 * (`ai.reviews.store`) has no storage yet, so both are null for now.
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
            // TODO: weekly review agenda ("Revisión semanal con Leonardo"), not designed as storage yet.
            'review' => null,
        ]);
    }
}
