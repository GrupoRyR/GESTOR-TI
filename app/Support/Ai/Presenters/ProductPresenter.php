<?php

namespace App\Support\Ai\Presenters;

use App\Models\AiProduct;
use App\Models\AiProductVersion;
use App\Models\AiRequest;
use App\Models\Area;
use App\Models\User;

/**
 * `Product` and `ProductDetail` of resources/js/types/ai.ts. Money
 * (`monthly_cost`) is null without `ai.costs.view`; requests and docs
 * come already filtered by the caller.
 */
final class ProductPresenter
{
    /** Placeholder while the owner is unknown (design convention). */
    public const OWNER_PLACEHOLDER = '[DUEÑO]';

    /** Relations `product()` reads. */
    public const RELATIONS = ['owner', 'areas', 'companies', 'monitoredApp'];

    /**
     * `Product`.
     *
     * @return array<string, mixed>
     */
    public static function product(AiProduct $product, User $viewer): array
    {
        $average = $product->averageRating();
        $primary = $product->companies->sortByDesc(fn ($company) => (int) $company->pivot?->getAttribute('is_primary'))->first();

        return [
            'slug' => $product->slug,
            'name' => $product->name,
            'company_id' => (int) $primary?->id,
            'description' => (string) $product->description,
            'state' => $product->status->value,
            'version' => (string) $product->current_version,
            'stack' => (string) $product->stack,
            'host' => (string) $product->hosting,
            'areas' => $product->areas
                ->sortByDesc(fn (Area $area) => (int) $area->pivot?->getAttribute('is_primary'))
                ->pluck('name')
                ->values()
                ->all(),
            'owner' => $product->ownerLabel() ?? self::OWNER_PLACEHOLDER,
            'url' => $product->url,
            'repository' => $product->repository,
            'ai_model' => $product->ai_model,
            'rating' => $average === null ? null : ['average' => RequestPresenter::number($average), 'count' => $product->ratingsCount()],
            'hours_saved_per_month' => RequestPresenter::number($product->hoursSaved()),
            'monthly_cost' => self::monthlyCost($product, $viewer),
            'open_requests' => $product->openRequestsCount(),
            'monitoring_active' => $product->health()['monitoring_active'],
        ];
    }

    /**
     * `ProductDetail`: the product plus its versions, open requests, docs
     * and the requests that came from the "Pedir mejora" button.
     *
     * @param  list<AiRequest>  $requests  open requests the viewer may see
     * @param  list<array<string, mixed>>  $docs  `DocSummary` rows
     * @return array<string, mixed>
     */
    public static function detail(AiProduct $product, User $viewer, array $requests, array $docs): array
    {
        return [
            ...self::product($product, $viewer),
            'versions' => $product->versions()->with('requests')->get()
                ->map(fn (AiProductVersion $version) => [
                    'tag' => $version->version,
                    'date' => ($version->released_at ?? $version->created_at)?->toDateString() ?? '',
                    'summary' => (string) $version->summary,
                    'request_keys' => $version->requests->sortBy('id')
                        ->map(fn (AiRequest $request) => ['key' => $request->key, 'title' => $request->title])
                        ->values()
                        ->all(),
                    'current' => $version->version === $product->current_version,
                ])
                ->values()
                ->all(),
            // No planned version is stored yet (Stage D builds versions from GitHub releases).
            'next_version' => null,
            'requests' => array_map(fn (AiRequest $request) => RequestPresenter::summary($request), $requests),
            'docs' => $docs,
            // The Kit RYR button does not record its origin yet.
            'requests_from_button_this_month' => 0,
        ];
    }

    public static function monthlyCost(AiProduct $product, User $viewer): ?int
    {
        if (! $product->costVisibleTo($viewer) || $product->monthly_cost === null) {
            return null;
        }

        return (int) round((float) $product->monthly_cost);
    }
}
