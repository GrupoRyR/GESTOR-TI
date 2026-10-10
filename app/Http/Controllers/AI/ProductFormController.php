<?php

namespace App\Http\Controllers\AI;

use App\Actions\Ai\SaveProduct;
use App\Domain\Ai\ProductForm;
use App\Http\Controllers\Controller;
use App\Http\Requests\Ai\ProductRequest;
use App\Models\AiProduct;
use App\Models\User;
use App\Support\Ai\Presenters\ProductPresenter;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Product form (C-ProductForm, types/pages/ai-knowledge.ts), `ai.manage`:
 *
 * - `Ai/Products/Create` / `ai.products.store`: blank form with the company
 *   of the context; redirects to the new product.
 * - `Ai/Products/Edit` / `ai.products.update`: the stored values; redirects
 *   to the product with its (possibly new) slug.
 *
 * Validation errors go back to the form, which renders the old input
 * (`form`) and the shared `errors`. No destroy: "Retirado" is the way out.
 */
class ProductFormController extends Controller
{
    public function __construct(
        private readonly CurrentCompany $companies,
        private readonly SaveProduct $save,
    ) {}

    public function create(Request $http): Response
    {
        Gate::authorize('create', AiProduct::class);

        $form = ProductForm::blank($this->companies->forUser($this->user($http)));

        return Inertia::render('Ai/Products/Create', [
            ...ProductForm::options(null),
            'form' => ProductForm::withOldInput($form, (array) $http->old()),
        ]);
    }

    public function store(ProductRequest $request): RedirectResponse
    {
        Gate::authorize('create', AiProduct::class);

        $product = $this->save->handle($this->user($request), null, $request->validated(), $request->companyIds(), $request->requestKeys());

        return redirect()->route('ai.products.show', $product)->with('success', 'Producto creado.');
    }

    public function edit(Request $http, AiProduct $product): Response
    {
        Gate::authorize('update', $product);
        $product->loadMissing(ProductPresenter::RELATIONS);

        $primary = $product->companies->sortByDesc(fn ($company) => (int) $company->pivot?->getAttribute('is_primary'))->first();

        return Inertia::render('Ai/Products/Edit', [
            ...ProductForm::options($product, $product->hosting),
            'product' => ['slug' => $product->slug, 'name' => $product->name, 'company_id' => (int) $primary?->id],
            'form' => ProductForm::withOldInput(ProductForm::values($product), (array) $http->old()),
        ]);
    }

    public function update(ProductRequest $request, AiProduct $product): RedirectResponse
    {
        Gate::authorize('update', $product);

        $product = $this->save->handle($this->user($request), $product, $request->validated(), $request->companyIds(), $request->requestKeys());

        return redirect()->route('ai.products.show', $product)->with('success', 'Cambios guardados.');
    }

    private function user(Request $http): User
    {
        /** @var User $user */
        $user = $http->user();

        return $user;
    }
}
