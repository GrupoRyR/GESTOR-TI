<?php

namespace App\Domain\Assistant\Tools;

use App\Domain\Assistant\KnowledgeTypes;
use App\Models\AiProduct;
use App\Models\KnowledgeItem;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;

/**
 * `ver_ficha`: one knowledge card in full (fields with their labels,
 * description, owner, related cards and products the person may see).
 * Placeholders such as "[RUTA EN SAP]" go as "sin documentar": the
 * assistant never fills them in.
 */
final class ShowCardTool implements AssistantTool
{
    private const UNDOCUMENTED = 'sin documentar';

    public function name(): string
    {
        return 'ver_ficha';
    }

    public function description(): string
    {
        return 'Abre una ficha de la base de conocimiento por su clave (por ejemplo K-031) y devuelve todos sus campos.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'clave' => $schema->string()->description('Clave de la ficha, por ejemplo K-031.')->pattern('^[Kk]-?[0-9]{1,6}$')->required(),
        ];
    }

    public function handle(User $user, array $arguments, ToolContext $context): ToolResult
    {
        preg_match('/(\d+)/', (string) $arguments['clave'], $match);
        $key = KnowledgeItem::formatKey((int) ($match[1] ?? 0));

        $card = KnowledgeItem::query()->visibleTo($user)->with(['companies', 'area', 'owner'])->where('key', $key)->first();

        if ($card === null) {
            return ToolResult::lookup(['error' => 'No hay una ficha visible con esa clave.'], 0);
        }

        $fields = [];
        foreach (KnowledgeTypes::normalize($card->type, $card->fields ?? []) as $field => $value) {
            if ($value === null || $value === []) {
                continue;
            }
            $fields[KnowledgeTypes::label($card->type, $field)] = is_array($value)
                ? array_map(fn (string $step) => KnowledgeTypes::isPlaceholder($step) ? self::UNDOCUMENTED : $step, $value)
                : (KnowledgeTypes::isPlaceholder($value) ? self::UNDOCUMENTED : $value);
        }

        return ToolResult::lookup([
            'clave' => $card->key,
            'tipo' => $card->type,
            'titulo' => $card->title,
            'descripcion' => $card->description,
            'campos' => $fields,
            'empresas' => $card->companies->map(fn ($company) => $company->short_name ?: $company->name)->values()->all(),
            'area' => $card->area?->name,
            'responsable' => $card->owner?->name,
            'vigente' => (bool) $card->is_current,
            'proxima_revision' => $card->review_at->toDateString(),
            'fichas_relacionadas' => $card->relatedItems()->visibleTo($user)->get()
                ->map(fn (KnowledgeItem $item) => ['clave' => $item->key, 'titulo' => $item->title])->values()->all(),
            'productos_relacionados' => $card->relatedProducts()->visibleTo($user)->get()
                ->map(fn (AiProduct $product) => ['slug' => $product->slug, 'nombre' => $product->name])->values()->all(),
        ], 1, [$card->key], [$card->key]);
    }
}
