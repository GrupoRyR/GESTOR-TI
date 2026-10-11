<?php

namespace App\Domain\Assistant\Tools;

use App\Domain\Assistant\CompanyNames;
use App\Domain\Assistant\KnowledgeFilters;
use App\Domain\Assistant\KnowledgeSearch;
use App\Domain\Assistant\KnowledgeTypes;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;

/**
 * `buscar_conocimiento`: searches the knowledge base with the C1 search
 * (FULLTEXT + glossary synonyms), only among the cards the person may see.
 */
final class SearchKnowledgeTool implements AssistantTool
{
    private const LIMIT = 5;

    public function __construct(
        private readonly KnowledgeSearch $search,
        private readonly CompanyNames $companies,
    ) {}

    public function name(): string
    {
        return 'buscar_conocimiento';
    }

    public function description(): string
    {
        return 'Busca en la base de conocimiento del área de TI (sistemas, informes, flujos de datos, procedimientos y glosario). '
            .'Devuelve las fichas más parecidas con su clave (K-031) y un resumen. Úsala antes de responder cualquier pregunta.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'texto' => $schema->string()->description('Palabras clave de la pregunta, por ejemplo "informe ventas diario" o "K-031".')->max(200)->required(),
            'tipo' => $schema->string()->description('Tipo de ficha, si la pregunta lo deja claro: system (sistema), report (informe), data_flow (flujo de datos), procedure (procedimiento) o glossary (glosario).')->enum(KnowledgeTypes::TYPES),
            'empresa' => $schema->string()->description('Empresa nombrada en la pregunta, si alguna.')->max(60),
        ];
    }

    public function handle(User $user, array $arguments, ToolContext $context): ToolResult
    {
        $companyId = isset($arguments['empresa']) ? ($this->companies->mentioned((string) $arguments['empresa'])[0] ?? null) : null;
        $filters = new KnowledgeFilters(type: $arguments['tipo'] ?? null, companyId: $companyId);

        $hits = $this->search->search($user, (string) $arguments['texto'], $filters, self::LIMIT);

        $cards = array_map(fn (array $hit) => [
            'clave' => $hit['item']->key,
            'tipo' => $hit['item']->type,
            'titulo' => $hit['item']->title,
            'empresas' => $hit['item']->companies->map(fn ($company) => $company->short_name ?: $company->name)->values()->all(),
            'area' => $hit['item']->area?->name,
            'vigente' => (bool) $hit['item']->is_current,
            'resumen' => $hit['snippet']['text'],
        ], $hits);

        return ToolResult::lookup(
            $cards === [] ? ['fichas' => [], 'nota' => 'Ninguna ficha visible coincide.'] : ['fichas' => $cards],
            count($cards),
            array_column($cards, 'clave'),
        );
    }
}
