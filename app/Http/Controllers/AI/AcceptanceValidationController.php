<?php

namespace App\Http\Controllers\AI;

use App\Actions\Ai\ValidateAcceptance;
use App\Enums\Ai\RequestStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Ai\UpdateAcceptanceValidationRequest;
use App\Models\AcceptanceCriterion;
use App\Models\AiRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "Validar" (`Ai/Requests/Validate`, RequestsValidateProps): the requester
 * marks each acceptance criterion while the request is "En pruebas". A
 * failed criterion sends it back to "En desarrollo" (ValidateAcceptance).
 */
class AcceptanceValidationController extends Controller
{
    public function edit(AiRequest $request): Response
    {
        Gate::authorize('validate', $request);

        $product = $request->product;

        return Inertia::render('Ai/Requests/Validate', [
            'request' => [
                'key' => $request->key,
                'title' => $request->title,
                'product' => $product !== null && filled($product->url) ? ['name' => $product->name, 'url' => $product->url] : null,
            ],
            'criteria' => $request->criteria->map(fn (AcceptanceCriterion $criterion) => [
                'id' => $criterion->id,
                'text' => $criterion->text,
                'result' => $criterion->result?->value,
                'comment' => $criterion->result_comment,
            ])->values()->all(),
        ]);
    }

    public function update(UpdateAcceptanceValidationRequest $form, AiRequest $request, ValidateAcceptance $validate): RedirectResponse
    {
        /** @var User $user */
        $user = $form->user();
        $validate->handle($request, $user, $form->results());

        $message = $request->status === RequestStatus::InDevelopment
            ? 'Gracias. TI corrige lo que falló y te avisa para probar de nuevo.'
            : 'Gracias. TI la publica y te avisa cuando esté en producción.';

        return redirect()->route('ai.requests.show', $request)->with('success', $message);
    }
}
