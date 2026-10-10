<?php

namespace App\Support\Assistant\Presenters;

use App\Domain\Assistant\KnowledgeTypes;
use App\Models\AiProduct;
use App\Models\Area;
use App\Models\KnowledgeItem;
use App\Models\User;
use App\Support\Ai\Presenters\RequestPresenter;
use Illuminate\Database\Eloquent\Collection;
use Spatie\Activitylog\Models\Activity;

/**
 * Knowledge cards as the shapes of types/assistant.ts: `KnowledgeCardRef`,
 * `KnowledgeCardSummary`, `KnowledgeCard`, `KnowledgeArea`.
 */
final class KnowledgePresenter
{
    /** Relations every summary needs. */
    public const RELATIONS = ['companies:id', 'area', 'owner.employee'];

    /** "Cambios recientes" shown on the card. */
    private const RECENT_CHANGES = 5;

    /**
     * @return array{key: string, type: string, title: string, visibility: string}
     */
    public static function ref(KnowledgeItem $item): array
    {
        return [
            'key' => $item->key,
            'type' => $item->type,
            'title' => $item->title,
            'visibility' => $item->visibility,
        ];
    }

    /**
     * @return array{id: int, name: string, company_id: int}|null
     */
    public static function area(?Area $area): ?array
    {
        return $area === null ? null : ['id' => $area->id, 'name' => $area->name, 'company_id' => (int) $area->company_id];
    }

    /**
     * @return array<string, mixed>
     */
    public static function summary(KnowledgeItem $item, string $today): array
    {
        return [
            ...self::ref($item),
            'id' => $item->id,
            'company_ids' => $item->companies->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all(),
            'area' => self::area($item->area),
            'owner' => $item->owner !== null ? RequestPresenter::person($item->owner) : null,
            'review_at' => $item->review_at->toDateString(),
            'review_status' => $item->reviewStatus($today),
            'updated_at' => $item->updated_at?->toIso8601String(),
        ];
    }

    /**
     * Full card for a viewer: related cards they may see, related products
     * of their company context, and (managers only) usage and changes.
     *
     * @return array<string, mixed>
     */
    public static function card(KnowledgeItem $item, User $viewer, string $today): array
    {
        $manage = KnowledgeItem::canManage($viewer);
        /** @var Collection<int, KnowledgeItem> $related */
        $related = $item->relatedItems()->visibleTo($viewer)->get();
        /** @var Collection<int, AiProduct> $products */
        $products = $item->relatedProducts()->visibleTo($viewer)->get();

        return [
            ...self::summary($item, $today),
            'fields' => KnowledgeTypes::normalize($item->type, $item->fields ?? []),
            'description' => $item->description,
            'related_cards' => $related->map(fn (KnowledgeItem $card) => self::ref($card))->values()->all(),
            'related_products' => $products->map(fn (AiProduct $product) => RequestPresenter::productRef($product))->values()->all(),
            'last_reviewed_at' => $item->last_reviewed_at?->toIso8601String(),
            'last_reviewed_by' => $item->lastReviewedBy !== null ? RequestPresenter::person($item->lastReviewedBy) : null,
            // Citations arrive with the assistant engine (C3).
            'usage_count_month' => 0,
            'last_cited_at' => null,
            'last_cited_by' => null,
            'recent_changes' => $manage ? self::changes($item) : [],
        ];
    }

    /**
     * `KnowledgeChange[]`, newest first.
     *
     * @return list<array{id: int, who: array<string, mixed>, what: string, at: string}>
     */
    public static function changes(KnowledgeItem $item): array
    {
        return Activity::query()
            ->where('log_name', 'knowledge')
            ->whereMorphedTo('subject', $item)
            ->whereNotNull('causer_id')
            ->with('causer')
            ->latest('id')
            ->limit(self::RECENT_CHANGES)
            ->get()
            ->filter(fn (Activity $activity) => $activity->causer instanceof User)
            ->map(fn (Activity $activity) => [
                'id' => $activity->id,
                'who' => RequestPresenter::person($activity->causer),
                'what' => (string) $activity->description,
                'at' => $activity->created_at?->toIso8601String() ?? '',
            ])
            ->values()
            ->all();
    }
}
