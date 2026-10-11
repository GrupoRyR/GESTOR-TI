<?php

namespace App\Http\Controllers\AI;

use App\Actions\Ai\Timers;
use App\Domain\Ai\ProjectCosts;
use App\Http\Controllers\Controller;
use App\Models\AiProject;
use App\Models\AiTask;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Task timer (types/pages/ai-projects.ts and Mi semana):
 *
 * - `ai.timer.start {project, task}` "Iniciar": AiProjectPolicy::logTime;
 *   a running timer on another task is stopped and logged first.
 * - `ai.timer.stop` "Parar": the viewer's running timer becomes an entry.
 *
 * Both go back with a notice (App\Actions\Ai\Timers).
 */
class TimerController extends Controller
{
    public function __construct(private readonly Timers $timers) {}

    public function start(Request $http, AiProject $project, AiTask $task): RedirectResponse
    {
        $result = $this->timers->start($this->user($http), $project, $task);
        $stopped = $result['stopped'];

        return back()->with('success', $stopped === null
            ? "Cronómetro corriendo en {$task->key}."
            : 'Se guardaron '.ProjectCosts::hours((float) $stopped->hours)." de la tarea anterior; ahora corre en {$task->key}.");
    }

    public function stop(Request $http): RedirectResponse
    {
        $entry = $this->timers->stop($this->user($http), $hadTimer);

        if (! $hadTimer) {
            return back()->with('error', 'No tienes un cronómetro corriendo.');
        }

        if ($entry === null) {
            return back()->with('error', 'Corrió menos de un minuto: no se guardaron horas.');
        }

        return back()->with('success', 'Se guardaron '.ProjectCosts::hours((float) $entry->hours).'.');
    }

    private function user(Request $http): User
    {
        /** @var User $user */
        $user = $http->user();

        return $user;
    }
}
