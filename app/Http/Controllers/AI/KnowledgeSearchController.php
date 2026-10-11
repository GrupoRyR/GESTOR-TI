<?php

namespace App\Http\Controllers\AI;

use App\Domain\Ai\KnowledgeSearch;
use App\Http\Controllers\Controller;
use App\Models\AiProduct;
use App\Models\Area;
use App\Models\Company;
use App\Models\User;
use App\Support\Ai\Presenters\TriagePresenter;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "Buscar" (`ai.search`, `Ai/Search/Index`, types/pages/ai-knowledge.ts):
 * docs, requests and products (App\Domain\Ai\KnowledgeSearch), filtered
 * server-side by what the viewer may see. Module users (AiProductPolicy::
 * viewAny) may search; documents only reach `ai.manage`.
 *
 * Query `{ q, type?: 'docs,requests', company_id? (only in "Todas"),
 * area_id? }`. `type_counts` ignore the type filter; `company_counts`
 * ignore the company filter and only go to `companies.view_all`.
 */
class KnowledgeSearchController extends Controller
{
    /** @var list<string> */
    public const TYPES = ['docs', 'requests', 'products'];

    private const PER_TYPE = 20;

    public function __construct(
        private readonly KnowledgeSearch $search,
        private readonly CurrentCompany $companies,
    ) {}

    public function __invoke(Request $http): Response
    {
        $started = microtime(true);
        /** @var User $user */
        $user = $http->user();
        Gate::authorize('viewAny', AiProduct::class);

        $q = mb_substr(trim((string) $http->query('q', '')), 0, 200);
        $types = $this->types($http->query('type'));
        $companyId = $this->companies->isAll() && is_numeric($http->query('company_id')) ? (int) $http->query('company_id') : null;
        $areaId = is_numeric($http->query('area_id')) ? (int) $http->query('area_id') : null;

        $results = null;
        $typeCounts = array_fill_keys(self::TYPES, 0);
        $companyCounts = [];

        if ($q !== '') {
            $hits = $this->search->search($user, $q, $areaId);

            if ($this->companies->canViewAll($user)) {
                $companyCounts = array_fill_keys(Company::query()->active()->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all(), 0);
                foreach ($types as $type) {
                    foreach ($hits[$type] as $row) {
                        if ($row['company_id'] !== null) {
                            $companyCounts[$row['company_id']] = ($companyCounts[$row['company_id']] ?? 0) + 1;
                        }
                    }
                }
            }

            $results = [];
            foreach (self::TYPES as $type) {
                $rows = array_values(array_filter($hits[$type], fn (array $row) => $companyId === null || $row['company_id'] === $companyId));
                $typeCounts[$type] = count($rows);
                $results[$type] = in_array($type, $types, true)
                    ? array_map(fn (array $row) => $row['hit'], array_slice($rows, 0, self::PER_TYPE))
                    : [];
            }
        }

        return Inertia::render('Ai/Search/Index', [
            'query' => ['q' => $q, 'types' => $types, 'company_id' => $companyId, 'area_id' => $areaId],
            'results' => $results,
            'type_counts' => $typeCounts,
            'company_counts' => (object) $companyCounts,
            'areas' => Area::query()->where('is_active', true)->orderBy('name')->orderBy('id')->get()
                ->map(fn (Area $area) => TriagePresenter::areaOption($area))->values()->all(),
            'took_ms' => (int) round((microtime(true) - $started) * 1000),
            'now' => now()->toIso8601String(),
        ]);
    }

    /**
     * Types of the `type` query (comma list) in a stable order; every type
     * when it is missing or names none.
     *
     * @return list<string>
     */
    private function types(mixed $param): array
    {
        if (! is_string($param) || trim($param) === '') {
            return self::TYPES;
        }

        $asked = array_map('trim', explode(',', $param));
        $types = array_values(array_filter(self::TYPES, fn (string $type) => in_array($type, $asked, true)));

        return $types === [] ? self::TYPES : $types;
    }
}
