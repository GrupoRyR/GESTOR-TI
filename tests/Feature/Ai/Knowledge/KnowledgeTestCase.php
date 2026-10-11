<?php

namespace Tests\Feature\Ai\Knowledge;

use App\Actions\Ai\Documents;
use App\Enums\Ai\ProductStatus;
use App\Models\AiDocument;
use App\Models\AiProduct;
use App\Models\AiProject;
use App\Models\Company;
use App\Models\User;
use Tests\Feature\Ai\Projects\ProjectsTestCase;

/**
 * Products, documentation and search (types/pages/ai-knowledge.ts).
 */
abstract class KnowledgeTestCase extends ProjectsTestCase
{
    /**
     * A product of the given company (primary) used by Ventas.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function product(array $attributes = [], ?Company $company = null): AiProduct
    {
        $company ??= $this->segurex;

        $product = AiProduct::factory()->create(array_replace([
            'status' => ProductStatus::Production,
            'current_version' => 'v3.1.0',
            'stack' => 'Next.js',
            'hosting' => 'Vercel',
        ], $attributes));
        $product->companies()->attach($company->id, ['is_primary' => true]);

        $area = $company->is($this->segurex) ? $this->ventas : ($company->is($this->derivados) ? $this->derivadosVentas : null);
        if ($area !== null) {
            $product->areas()->attach($area->id, ['is_primary' => true]);
        }

        return $product;
    }

    protected function doc(AiProduct|AiProject $owner, string $template = 'user_manual', ?string $title = null, ?string $markdown = null, ?User $author = null): AiDocument
    {
        $doc = app(Documents::class)->create($owner, $template, $title ?? 'Manual de usuario', $author ?? $this->jhon);

        if ($markdown !== null) {
            $doc->forceFill(['body' => $markdown])->save();
        }

        return $doc->fresh();
    }
}
