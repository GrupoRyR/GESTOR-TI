<?php

namespace App\Console\Commands;

use App\Actions\Ai\TransitionRequest;
use App\Enums\Ai\RequestStatus;
use App\Models\AiRequest;
use App\Models\Scopes\CompanyScope;
use App\Notifications\AiHoursSavedQuestion;
use App\Notifications\AiRatingRequested;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

/**
 * Rating lifecycle of delivered requests (PROMPT 6.4.5), daily:
 *
 * - 3 days in production without the requester's rating: one reminder
 *   (`AiRatingRequested`, `rating_reminded_at`).
 * - 7 days without rating: closed without rating ("Cerrada", public note;
 *   the requester gets the status notice).
 * - 30 days after reaching production (in production or closed): one
 *   "¿cuántas horas te ahorra por semana?" (`AiHoursSavedQuestion`,
 *   `hours_saved_asked_at`) unless the requester already answered; deliveries
 *   older than 60 days are not asked.
 *
 * The start is the latest move to "En producción". Stamps make reruns safe.
 */
class RatingsLifecycleCommand extends Command
{
    public const CLOSE_NOTE = 'Se cerró sin calificación: pasaron 7 días desde que llegó a producción.';

    private const REMIND_AFTER_DAYS = 3;

    private const CLOSE_AFTER_DAYS = 7;

    private const HOURS_AFTER_DAYS = 30;

    /** Older deliveries (before this lifecycle existed) are not asked. */
    private const HOURS_WINDOW_DAYS = 60;

    protected $signature = 'ai:ratings-lifecycle';

    protected $description = 'Recuerda calificar, cierra sin calificación a los 7 días y pregunta las horas ahorradas a los 30';

    public function handle(TransitionRequest $transition): int
    {
        $reminded = 0;
        $closed = 0;
        $asked = 0;

        $delivered = AiRequest::query()
            ->withoutGlobalScope(CompanyScope::class)
            ->whereIn('status', [RequestStatus::InProduction->value, RequestStatus::Closed->value])
            ->whereHas('statusChanges', fn (Builder $query) => $query->where('to_status', RequestStatus::InProduction->value))
            ->with('requester')
            ->orderBy('id')
            ->get();

        foreach ($delivered as $request) {
            $since = $request->inProductionSince();
            $requester = $request->requester;

            if ($since === null || $requester === null) {
                continue;
            }

            if ($request->status === RequestStatus::InProduction && ! $request->ratings()->where('user_id', $requester->id)->exists()) {
                if ($since->lte(now()->subDays(self::CLOSE_AFTER_DAYS))) {
                    $transition->apply($request, RequestStatus::Closed, null, self::CLOSE_NOTE);
                    $closed++;
                } elseif ($since->lte(now()->subDays(self::REMIND_AFTER_DAYS)) && $request->rating_reminded_at === null) {
                    $request->forceFill(['rating_reminded_at' => now()])->save();
                    $requester->notify(AiRatingRequested::for($request, true));
                    $reminded++;
                }
            }

            if ($since->lte(now()->subDays(self::HOURS_AFTER_DAYS))
                && $since->gt(now()->subDays(self::HOURS_WINDOW_DAYS))
                && $request->hours_saved_asked_at === null
                && ! $request->hoursSavedReports()->where('user_id', $requester->id)->exists()) {
                $request->forceFill(['hours_saved_asked_at' => now()])->save();
                $requester->notify(AiHoursSavedQuestion::for($request));
                $asked++;
            }
        }

        $this->info("Recordatorios: {$reminded}. Cerradas sin calificación: {$closed}. Preguntas de horas: {$asked}.");

        return self::SUCCESS;
    }
}
