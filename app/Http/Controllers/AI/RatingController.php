<?php

namespace App\Http\Controllers\AI;

use App\Actions\Ai\RateRequest;
use App\Enums\Ai\RequestStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Ai\StoreHoursSavedRequest;
use App\Http\Requests\Ai\StoreRatingRequest;
use App\Models\AiRequest;
use App\Models\User;
use App\Notifications\AiRatingRequested;
use App\Support\Ai\Presenters\RequestPresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "Calificar la entrega" (`Ai/Ratings/Create`) and "30 días después"
 * (`Ai/Ratings/HoursSaved`). Only the requester, once each.
 */
class RatingController extends Controller
{
    public function create(AiRequest $request): Response
    {
        Gate::authorize('rate', $request);

        return Inertia::render('Ai/Ratings/Create', ['request' => $this->rated($request)]);
    }

    public function store(StoreRatingRequest $form, AiRequest $request, RateRequest $rate): RedirectResponse
    {
        /** @var User $user */
        $user = $form->user();
        $rate->rate($request, $user, (int) $form->validated('need_score'), (int) $form->validated('ease_score'), $form->validated('comment'));

        return redirect()->route('ai.requests.mine')->with('success', '¡Gracias por calificar!');
    }

    /**
     * "Recordar a …" of the value dashboard empty state (`ai.manage`, route
     * middleware): asks the requester again to rate a delivery in
     * production that is not rated yet (`AiRatingRequested` reminder).
     */
    public function remind(AiRequest $request): RedirectResponse
    {
        Gate::authorize('transition', $request);
        $requester = $request->requester;

        if ($request->status !== RequestStatus::InProduction || $requester === null
            || $request->ratings()->where('user_id', $requester->id)->exists()) {
            return back(fallback: route('ai.value.index'))->with('error', 'Esa entrega ya no espera calificación.');
        }

        $request->forceFill(['rating_reminded_at' => now()])->save();
        $requester->notify(AiRatingRequested::for($request, true));

        return back(fallback: route('ai.value.index'))->with('success', "Le recordamos a {$requester->name} que califique la entrega.");
    }

    public function createHours(AiRequest $request): Response
    {
        Gate::authorize('reportHoursSaved', $request);

        return Inertia::render('Ai/Ratings/HoursSaved', [
            'request' => $this->rated($request),
            'estimated_hours_per_week' => RequestPresenter::number($request->hours_per_week) ?? 0,
        ]);
    }

    public function storeHours(StoreHoursSavedRequest $form, AiRequest $request, RateRequest $rate): RedirectResponse
    {
        /** @var User $user */
        $user = $form->user();
        $rate->reportHoursSaved($request, $user, (float) $form->validated('hours_per_week'));

        return redirect()->route('ai.requests.mine')->with('success', '¡Gracias! Así medimos cuánto tiempo ahorra.');
    }

    /**
     * `RatedRequest`.
     *
     * @return array{key: string, title: string, product_name: string, in_production_since: string}
     */
    private function rated(AiRequest $request): array
    {
        $since = $request->inProductionSince() ?? $request->updated_at;

        return [
            'key' => $request->key,
            'title' => $request->title,
            'product_name' => (string) ($request->product?->name ?? $request->title),
            'in_production_since' => $since->copy()->timezone('America/Bogota')->toDateString(),
        ];
    }
}
