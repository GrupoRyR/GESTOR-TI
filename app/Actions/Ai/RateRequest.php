<?php

namespace App\Actions\Ai;

use App\Models\AiHoursSavedReport;
use App\Models\AiRating;
use App\Models\AiRequest;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * "Calificar la entrega" and, 30 days later, "¿cuántas horas te ahorra por
 * semana?" (PROMPT 6.4.5). Only the requester, once each (policy `rate` and
 * `reportHoursSaved`); input ranges are checked by the form requests.
 */
class RateRequest
{
    public function rate(AiRequest $request, User $requester, int $solvedNeed, int $easeOfUse, ?string $comment): AiRating
    {
        Gate::forUser($requester)->authorize('rate', $request);

        $comment = $comment === null ? '' : trim($comment);

        $rating = $request->ratings()->create([
            'user_id' => $requester->id,
            'solved_need' => $solvedNeed,
            'ease_of_use' => $easeOfUse,
            'comment' => $comment === '' ? null : $comment,
            'rated_at' => now(),
        ]);

        activity('ai')
            ->performedOn($request)
            ->causedBy($requester)
            ->event('ai_request_rated')
            ->withProperties(['key' => $request->key, 'solved_need' => $solvedNeed, 'ease_of_use' => $easeOfUse])
            ->log("{$request->key}: calificada");

        return $rating;
    }

    public function reportHoursSaved(AiRequest $request, User $requester, float $hoursPerWeek): AiHoursSavedReport
    {
        Gate::forUser($requester)->authorize('reportHoursSaved', $request);

        return $request->hoursSavedReports()->create([
            'user_id' => $requester->id,
            'hours_per_week' => $hoursPerWeek,
            'reported_at' => now(),
        ]);
    }
}
