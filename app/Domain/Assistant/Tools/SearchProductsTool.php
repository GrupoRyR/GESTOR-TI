<?php

namespace App\Domain\Assistant\Tools;

use App\Models\AiProduct;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Str;

/**
 * `buscar_productos`: the Gestor IA product catalog (own apps), only the
 * products of the person's company context. Never costs.
 */
final class SearchProductsTool implements AssistantTool
{
    private const LIMIT = 8;

    public function name(): string
    {
        return 'buscar_productos';
    }

    public function description(): string
    {
        return 'Busca las aplicaciones propias del catálogo del Gestor IA (por nombre o descripción). Devuelve su slug, estado, versión y dueño.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'texto' => $schema->string()->description('Nombre o palabras de la aplicación, por ejemplo "visualizador de cartera".')->max(100)->required(),
        ];
    }

    public function handle(User $user, array $arguments, ToolContext $context): ToolResult
    {
        $words = array_values(array_filter(preg_split('/\s+/u', Str::squish((string) $arguments['texto'])) ?: [], fn (string $word) => mb_strlen($word) >= 3));

        $products = AiProduct::query()->visibleTo($user)
            ->with('owner')
            ->when($words !== [], fn ($query) => $query->where(function ($query) use ($words): void {
                foreach ($words as $word) {
                    $like = '%'.$word.'%';
                    $query->orWhere('name', 'like', $like)->orWhere('slug', 'like', $like)->orWhere('description', 'like', $like);
                }
            }))
            ->orderBy('name')
            ->limit(self::LIMIT)
            ->get();

        $rows = $products->map(fn (AiProduct $product) => self::row($product))->values()->all();

        return ToolResult::lookup($rows === [] ? ['productos' => [], 'nota' => 'Ninguna aplicación visible coincide.'] : ['productos' => $rows], count($rows));
    }

    /**
     * @return array<string, mixed>
     */
    public static function row(AiProduct $product): array
    {
        return [
            'slug' => $product->slug,
            'nombre' => $product->name,
            'descripcion' => $product->description === null ? null : Str::limit($product->description, 300),
            'estado' => $product->status->label(),
            'version' => $product->current_version,
            'url' => $product->url,
            'responsable' => $product->ownerLabel(),
        ];
    }
}
