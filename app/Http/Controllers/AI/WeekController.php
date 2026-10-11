<?php

namespace App\Http\Controllers\AI;

use App\Domain\Ai\Week;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Ai\Presenters\TimerPresenter;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "Mi semana" (`Ai/Week/Index`, WeekIndexProps). Items come from
 * App\Domain\Ai\Week (requests, tasks and requirements since B5);
 * `running_timer` is the viewer's running timer (B6). The weekly review
 * (`ai.reviews.store`) has no storage yet, so `review` is null.
 */
class WeekController extends Controller
{
    public function __invoke(Request $http, Week $week): Response
    {
        /** @var User $user */
        $user = $http->user();

        return Inertia::render('Ai/Week/Index', [
            'today' => Week::today()->toDateString(),
            'now' => now()->toIso8601String(),
            'week_ends_on' => Week::endsOn()->toDateString(),
            'items' => $week->items(),
            'running_timer' => TimerPresenter::running(TimerPresenter::forUser($user)),
            // TODO: weekly review agenda ("Revisión semanal con Leonardo"), not designed as storage yet.
            'review' => null,
        ]);
    }
}
