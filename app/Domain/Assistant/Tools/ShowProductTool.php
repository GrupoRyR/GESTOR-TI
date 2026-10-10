<?php

namespace App\Domain\Assistant\Tools;

use App\Models\AiProduct;
use App\Models\Area;
use App\Models\Company;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;

/**
 * `ver_producto`: one Gestor IA product of the person's company context.
 * Technical facts only (stack, hosting, repository, who uses it); costs and
 * AI spend never leave the app.
 */
final class ShowProductTool implements AssistantTool
{
    public function name(): string
    {
        return 'ver_producto';
    }

    public function description(): string
    {
        return 'Abre una aplicación del catálogo del Gestor IA por su slug y devuelve qué es, estado, versión, dónde corre, quién la usa y cuántas solicitudes abiertas tiene.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'slug' => $schema->string()->description('Slug de la aplicación, tal como lo devuelve buscar_productos.')->max(80)->required(),
        ];
    }

    public function handle(User $user, array $arguments, ToolContext $context): ToolResult
    {
        $product = AiProduct::query()->visibleTo($user)->with(['owner', 'companies', 'areas'])->where('slug', (string) $arguments['slug'])->first();

        if ($product === null) {
            return ToolResult::lookup(['error' => 'No hay una aplicación visible con ese slug.'], 0);
        }

        return ToolResult::lookup([
            ...SearchProductsTool::row($product),
            'stack' => $product->stack,
            'hosting' => $product->hosting,
            'repositorio' => $product->repository,
            'empresas' => $product->companies->map(fn (Company $company) => $company->short_name ?: $company->name)->values()->all(),
            'areas' => $product->areas->map(fn (Area $area) => $area->name)->values()->all(),
            'solicitudes_abiertas' => $product->openRequestsCount(),
        ], 1);
    }
}
