<?php

namespace App\Domain\Assistant\Tools;

use App\Enums\Ai\RequestStatus;
use App\Models\AiRequest;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * `buscar_solicitudes_gestor_ia`: Gestor IA requests the person may view
 * (AiRequestPolicy::view, company scope). Public facts only: key, title,
 * type, status, product and date; never internal notes, money or people's
 * data beyond the product.
 */
final class SearchRequestsTool implements AssistantTool
{
    private const LIMIT = 8;

    private const CANDIDATES = 40;

    public function name(): string
    {
        return 'buscar_solicitudes_gestor_ia';
    }

    public function description(): string
    {
        return 'Busca solicitudes del Gestor IA (mejoras, errores y apps nuevas) por clave (GIA-12) o por palabras del título, y dice en qué estado van.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'texto' => $schema->string()->description('Clave (GIA-12) o palabras del título. Vacío para las más recientes.')->max(100),
            'estado' => $schema->string()->description('Filtra por estado, si la pregunta lo pide.')->enum(array_map(fn (RequestStatus $status) => $status->value, RequestStatus::cases())),
        ];
    }

    public function handle(User $user, array $arguments, ToolContext $context): ToolResult
    {
        $text = Str::squish((string) ($arguments['texto'] ?? ''));
        $key = preg_match('/^gia-?(\d+)$/i', $text, $match) === 1 ? 'GIA-'.$match[1] : null;

        $requests = AiRequest::query()
            ->with('product:id,name')
            ->when($key !== null, fn ($query) => $query->where('key', $key))
            ->when($key === null && $text !== '', fn ($query) => $query->where(function ($query) use ($text): void {
                foreach (array_filter(explode(' ', $text), fn (string $word) => mb_strlen($word) >= 3) as $word) {
                    $query->orWhere('title', 'like', '%'.$word.'%');
                }
            }))
            ->when(isset($arguments['estado']), fn ($query) => $query->where('status', $arguments['estado']))
            ->latest('id')
            ->limit(self::CANDIDATES)
            ->get()
            ->filter(fn (AiRequest $request) => Gate::forUser($user)->allows('view', $request))
            ->take(self::LIMIT);

        $rows = $requests->map(fn (AiRequest $request) => [
            'clave' => $request->key,
            'titulo' => $request->title,
            'tipo' => $request->type->label(),
            'estado' => $request->status->label(),
            'producto' => $request->product?->name,
            'creada' => $request->created_at?->toDateString(),
        ])->values()->all();

        return ToolResult::lookup($rows === [] ? ['solicitudes' => [], 'nota' => 'Ninguna solicitud visible coincide.'] : ['solicitudes' => $rows], count($rows));
    }
}
