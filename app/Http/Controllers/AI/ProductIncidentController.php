<?php

namespace App\Http\Controllers\AI;

use App\Actions\Ai\RegisterProductIncident;
use App\Http\Controllers\Controller;
use App\Http\Requests\Ai\ProductIncidentRequest;
use App\Models\AiProduct;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Response;

/**
 * Incidents registered by hand (C-IncidentForm), `ai.manage`:
 *
 * - create (`ai.products.incidents.create`): deep link; renders
 *   `Ai/Products/Show` with `incident_form.open = true`. A failed store
 *   lands here too (ProductIncidentRequest), with `errors` and the old input.
 * - store (`ai.products.incidents.store`): registers it and goes back to
 *   the product.
 */
class ProductIncidentController extends Controller
{
    public function __construct(private readonly RegisterProductIncident $register) {}

    public function create(Request $http, AiProduct $product, ProductController $sheet): Response
    {
        return $sheet->sheet($http, $product, true);
    }

    public function store(ProductIncidentRequest $request, AiProduct $product): RedirectResponse
    {
        Gate::authorize('update', $product);

        /** @var User $user */
        $user = $request->user();

        $this->register->handle(
            $user,
            $product,
            (string) $request->validated('title'),
            (string) $request->validated('impact'),
            (string) $request->validated('description'),
            $request->startedAt(),
            $request->endedAt(),
            $request->validated('resolution'),
            $request->notifyIds(),
            (bool) $request->validated('notify_owner'),
        );

        return redirect()->route('ai.products.show', $product)->with('success', 'Incidente registrado.');
    }
}
