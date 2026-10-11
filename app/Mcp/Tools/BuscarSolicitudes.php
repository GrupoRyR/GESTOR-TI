<?php

namespace App\Mcp\Tools;

use App\Enums\Ai\RequestStatus;
use App\Mcp\Tools\Concerns\InteractsWithGestor;
use App\Models\AiRequest;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * Request search in the user's company scope (CompanyScope), only the
 * requests they may open (AiRequestPolicy::view), newest change first.
 * Every word of `texto` must appear in the key, title or problem (plain
 * LIKE, so it behaves the same on MySQL and SQLite; `%` is not escaped,
 * which only widens a search that stays scoped and authorized).
 */
#[Name('buscar_solicitudes')]
#[Description('Busca solicitudes del Gestor IA (claves GIA-n) por texto en la clave, el título o el problema, con filtros opcionales de estado, producto, área y empresa. Devuelve clave, título, estado, producto, empresa, área del solicitante, prioridad, última actualización y enlace. Usa ver_solicitud para el detalle.')]
#[IsReadOnly]
#[IsIdempotent]
class BuscarSolicitudes extends Tool
{
    use InteractsWithGestor;

    public const DEFAULT_LIMIT = 10;

    public const MAX_LIMIT = 25;

    private const MAX_WORDS = 8;

    public function handle(Request $request): Response
    {
        $user = $this->actor($request);
        $data = $request->validate([
            'texto' => ['nullable', 'string', 'max:200'],
            'estado' => ['nullable', Rule::in(self::statuses())],
            'producto' => ['nullable', 'string', 'max:100'],
            'area' => ['nullable', 'string', 'max:100'],
            'empresa' => ['nullable', 'string', 'max:100'],
            'limite' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_LIMIT],
        ], [
            'texto.*' => 'El texto de búsqueda puede tener hasta 200 caracteres.',
            'estado.*' => 'Ese estado no existe. Usa uno de: '.implode(', ', self::statuses()).'.',
            'producto.*' => 'Escribe el nombre del producto (hasta 100 caracteres).',
            'area.*' => 'Escribe el nombre del área (hasta 100 caracteres).',
            'empresa.*' => 'Escribe el nombre de la empresa (hasta 100 caracteres).',
            'limite.*' => 'El límite va de 1 a '.self::MAX_LIMIT.' solicitudes.',
        ]);

        $limit = (int) ($data['limite'] ?? self::DEFAULT_LIMIT);
        $gate = Gate::forUser($user);

        $rows = $this->query($data)
            ->lazy(100)
            ->filter(fn (AiRequest $found) => $gate->allows('view', $found))
            ->take($limit)
            ->map(fn (AiRequest $found) => [
                'clave' => $found->key,
                'titulo' => $found->title,
                'estado' => $found->status->label(),
                'estado_clave' => $found->status->value,
                'producto' => $found->product?->name,
                'empresa' => (string) ($found->company?->short_name ?: $found->company?->name),
                'area' => $found->area?->name,
                'prioridad' => $found->final_priority ?? $found->priority_score,
                'actualizada' => $found->updated_at?->toIso8601String(),
                'url' => route('ai.requests.show', $found->key),
            ])
            ->values()
            ->all();

        return Response::json(['total' => count($rows), 'solicitudes' => $rows]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return Builder<AiRequest>
     */
    private function query(array $data): Builder
    {
        $query = AiRequest::query()->with(['company', 'area', 'product']);

        foreach ($this->words((string) ($data['texto'] ?? '')) as $word) {
            $like = '%'.$word.'%';
            $query->where(fn (Builder $query) => $query
                ->where('key', 'like', $like)
                ->orWhere('title', 'like', $like)
                ->orWhere('problem', 'like', $like));
        }

        return $query
            ->when($data['estado'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
            ->when($this->like($data['producto'] ?? null), fn (Builder $query, string $like) => $query
                ->whereHas('product', fn (Builder $product) => $product->where('name', 'like', $like)->orWhere('slug', 'like', $like)))
            ->when($this->like($data['area'] ?? null), fn (Builder $query, string $like) => $query
                ->whereHas('area', fn (Builder $area) => $area->where('name', 'like', $like)->orWhere('slug', 'like', $like)))
            ->when($this->like($data['empresa'] ?? null), fn (Builder $query, string $like) => $query
                ->whereHas('company', fn (Builder $company) => $company
                    ->where('name', 'like', $like)->orWhere('short_name', 'like', $like)->orWhere('slug', 'like', $like)))
            ->orderByDesc('updated_at')
            ->orderByDesc('id');
    }

    /**
     * @return list<string>
     */
    private function words(string $text): array
    {
        $words = preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_slice(array_values(array_filter($words, fn (string $word) => mb_strlen($word) >= 2)), 0, self::MAX_WORDS);
    }

    private function like(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : '%'.$value.'%';
    }

    /**
     * @return list<string>
     */
    private static function statuses(): array
    {
        return array_map(fn (RequestStatus $status) => $status->value, RequestStatus::cases());
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'texto' => $schema->string()->description('Palabras a buscar en la clave, el título o el problema (todas deben aparecer).'),
            'estado' => $schema->string()->enum(self::statuses())->description('Estado de la solicitud: received (Recibida), evaluating, needs_info, pending_approval, approved, backlog, rejected, planned, in_development, in_testing, in_production, closed.'),
            'producto' => $schema->string()->description('Nombre o parte del nombre del producto.'),
            'area' => $schema->string()->description('Área del solicitante (nombre o parte de él).'),
            'empresa' => $schema->string()->description('Empresa (nombre, nombre corto o parte de él). Por defecto, todas las que el usuario puede ver.'),
            'limite' => $schema->integer()->min(1)->max(self::MAX_LIMIT)->description('Máximo de resultados (1 a 25, por defecto 10).'),
        ];
    }
}
