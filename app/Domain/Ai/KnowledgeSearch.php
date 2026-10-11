<?php

namespace App\Domain\Ai;

use App\Models\AiDocument;
use App\Models\AiProduct;
use App\Models\AiRequest;
use App\Models\Area;
use App\Models\User;
use App\Support\Ai\Presenters\DocumentPresenter;
use App\Support\Ai\Presenters\RequestPresenter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * "Buscar" of the Gestor IA (PROMPT 6.4.4, `Ai/Search/Index`): documents,
 * requests and products matching any keyword of the query, best first.
 *
 * - Keywords: App\Domain\Ai\DuplicateFinder::keywords (no accents, no
 *   Spanish stopwords, 3+ letters). Highlights: App\Domain\Ai\Highlighter.
 * - MySQL: FULLTEXT `MATCH … AGAINST` (docs on title + body, requests on
 *   title + problem; verified on the server). Other drivers (SQLite in
 *   tests): the newest FALLBACK_CANDIDATES rows scored in PHP by how many
 *   keywords they contain. Products are few: always scored in PHP.
 * - Visibility: requests through CompanyScope (the viewer's context; module
 *   users see their company's requests, as the roadmap), products through
 *   AiProduct::visibleTo, documents only for `ai.manage` (they open IT
 *   pages) and only those whose owner is in the context.
 *
 * Every hit carries `company_id` (owner company) and `area_ids` so the
 * caller can count per company and filter without querying again.
 */
final class KnowledgeSearch
{
    private const FALLBACK_CANDIDATES = 500;

    /** Rows kept per type before the caller's company filter. */
    private const MAX_HITS = 100;

    /**
     * @return array{
     *     docs: list<array{company_id: ?int, hit: array<string, mixed>}>,
     *     requests: list<array{company_id: ?int, hit: array<string, mixed>}>,
     *     products: list<array{company_id: ?int, hit: array<string, mixed>}>,
     * }
     */
    public function search(User $user, string $query, ?int $areaId): array
    {
        $keywords = DuplicateFinder::keywords($query);

        if ($keywords === []) {
            return ['docs' => [], 'requests' => [], 'products' => []];
        }

        return [
            'docs' => $user->can('ai.manage') ? $this->docs($user, $keywords, $areaId) : [],
            'requests' => $this->requests($keywords, $areaId),
            'products' => $this->products($user, $keywords, $areaId),
        ];
    }

    /**
     * @param  list<string>  $keywords
     * @return list<array{company_id: ?int, hit: array<string, mixed>}>
     */
    private function docs(User $user, array $keywords, ?int $areaId): array
    {
        $query = AiDocument::query()->visibleTo($user)->with([...DocumentPresenter::RELATIONS, 'product.areas', 'product.companies']);
        $documents = $this->rank($query, ['title', 'body'], $keywords, fn (AiDocument $document) => $document->title.' '.$document->body);

        return $documents
            ->filter(fn (AiDocument $document) => $areaId === null || in_array($areaId, $this->documentAreas($document), true))
            ->map(fn (AiDocument $document) => [
                'company_id' => $this->documentCompany($document),
                'hit' => [
                    'doc' => DocumentPresenter::summary($document),
                    'snippet' => Highlighter::snippet($document->body !== '' ? $document->body : $document->title, $keywords),
                ],
            ])
            ->values()
            ->all();
    }

    /**
     * @param  list<string>  $keywords
     * @return list<array{company_id: ?int, hit: array<string, mixed>}>
     */
    private function requests(array $keywords, ?int $areaId): array
    {
        $query = AiRequest::query()
            ->when($areaId !== null, fn (Builder $query) => $query->where('area_id', $areaId))
            ->with('area');
        $requests = $this->rank($query, ['title', 'problem'], $keywords, fn (AiRequest $request) => $request->title.' '.$request->problem);

        return $requests
            ->map(fn (AiRequest $request) => [
                'company_id' => (int) $request->company_id,
                'hit' => [
                    'request' => [
                        'key' => $request->key,
                        'status' => $request->status->value,
                        'company_id' => (int) $request->company_id,
                        'area' => RequestPresenter::area($request->area),
                    ],
                    'title' => Highlighter::highlight($request->title, $keywords),
                ],
            ])
            ->values()
            ->all();
    }

    /**
     * @param  list<string>  $keywords
     * @return list<array{company_id: ?int, hit: array<string, mixed>}>
     */
    private function products(User $user, array $keywords, ?int $areaId): array
    {
        $products = AiProduct::query()->visibleTo($user)->with(['areas', 'companies', 'owner'])->latest('id')->get()
            ->filter(fn (AiProduct $product) => $areaId === null || $product->areas->contains('id', $areaId));

        return $this->score($products, $keywords, fn (AiProduct $product) => implode(' ', [
            $product->name, $product->description, $product->stack, $product->current_version,
            $product->ownerLabel(), $product->areas->pluck('name')->implode(' '),
        ]))
            ->map(function (AiProduct $product) use ($keywords) {
                $company = $this->primaryCompany($product);
                $snippet = implode(' · ', array_values(array_filter([
                    (string) $product->current_version,
                    $product->areas->pluck('name')->implode(' y '),
                    (string) $product->description,
                ], fn (string $part) => $part !== '')));

                return [
                    'company_id' => $company,
                    'hit' => [
                        'product' => [
                            'slug' => $product->slug,
                            'name' => $product->name,
                            'company_id' => (int) $company,
                            'state' => $product->status->value,
                            'version' => (string) $product->current_version,
                        ],
                        'name' => Highlighter::highlight($product->name, $keywords),
                        'snippet' => Highlighter::highlight($snippet, $keywords),
                    ],
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Matching rows, best first.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @param  list<string>  $columns  FULLTEXT columns (MySQL)
     * @param  list<string>  $keywords
     * @param  callable(TModel): string  $text  text scored by the fallback
     * @return Collection<int, TModel>
     */
    private function rank(Builder $query, array $columns, array $keywords, callable $text): Collection
    {
        if ($query->getConnection()->getDriverName() === 'mysql') {
            $terms = implode(' ', $keywords);
            $match = 'MATCH ('.implode(', ', $columns).') AGAINST (? IN NATURAL LANGUAGE MODE)';

            return $query->whereFullText($columns, $terms)
                ->orderByRaw("{$match} DESC", [$terms])
                ->limit(self::MAX_HITS)
                ->get();
        }

        return $this->score($query->latest('id')->limit(self::FALLBACK_CANDIDATES)->get(), $keywords, $text);
    }

    /**
     * Rows containing at least one keyword, most keywords first (input
     * order on ties: newest first).
     *
     * @template TModel
     *
     * @param  iterable<TModel>  $rows
     * @param  list<string>  $keywords
     * @param  callable(TModel): string  $text
     * @return Collection<int, TModel>
     */
    private function score(iterable $rows, array $keywords, callable $text): Collection
    {
        $scored = [];

        foreach ($rows as $row) {
            $haystack = Str::lower(Str::ascii($text($row)));
            $score = count(array_filter($keywords, fn (string $keyword) => str_contains($haystack, $keyword)));

            if ($score > 0) {
                $scored[] = [$row, $score];
            }
        }

        usort($scored, fn (array $a, array $b) => $b[1] <=> $a[1]);

        return collect(array_map(fn (array $pair) => $pair[0], array_slice($scored, 0, self::MAX_HITS)));
    }

    private function documentCompany(AiDocument $document): ?int
    {
        if ($document->ai_project_id !== null) {
            return $document->project !== null ? (int) $document->project->company_id : null;
        }

        return $document->product !== null ? $this->primaryCompany($document->product) : null;
    }

    /**
     * @return list<int>
     */
    private function documentAreas(AiDocument $document): array
    {
        if ($document->ai_project_id !== null) {
            return $document->project?->area_id !== null ? [(int) $document->project->area_id] : [];
        }

        return $document->product?->areas->map(fn (Area $area) => (int) $area->id)->all() ?? [];
    }

    private function primaryCompany(AiProduct $product): ?int
    {
        $company = $product->companies->sortByDesc(fn ($company) => (int) $company->pivot?->getAttribute('is_primary'))->first();

        return $company !== null ? (int) $company->id : null;
    }
}
