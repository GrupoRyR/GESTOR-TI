<?php

namespace App\Support\Ai\Presenters;

use App\Models\AiProduct;
use App\Models\AiRequest;
use App\Models\Area;
use App\Models\User;

/**
 * Shared shapes of resources/js/types/ai.ts: `PersonRef`, area refs,
 * `ProductRef` and `RequestSummary`. Never carries costs, rates or
 * internal notes. Callers eager load `requester.employee`, `area` and
 * `product` (see `RELATIONS`).
 */
final class RequestPresenter
{
    /** Relations `summary()` reads. */
    public const RELATIONS = ['requester.employee', 'area', 'product', 'projects'];

    /**
     * Whole numbers as int, others as float (JSON `6` instead of `6.0`).
     */
    public static function number(int|float|string|null $value): int|float|null
    {
        if ($value === null || $value === '') {
            return null;
        }

        $float = (float) $value;

        return floor($float) === $float ? (int) $float : $float;
    }

    /**
     * @return array{id: int, name: string, job_title: ?string, avatar_url: ?string}
     */
    public static function person(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'job_title' => $user->employee?->job_title,
            'avatar_url' => $user->avatar_url,
        ];
    }

    /**
     * @return array{id: int, name: string}
     */
    public static function area(?Area $area): array
    {
        return ['id' => (int) $area?->id, 'name' => (string) $area?->name];
    }

    /**
     * @return array{slug: string, name: string, version: string}
     */
    public static function productRef(AiProduct $product, ?string $version = null): array
    {
        return [
            'slug' => $product->slug,
            'name' => $product->name,
            'version' => (string) ($version ?? $product->current_version ?? ''),
        ];
    }

    /**
     * `RequestSummary`. `$rank` comes from App\Domain\Ai\Roadmap.
     *
     * @return array<string, mixed>
     */
    public static function summary(AiRequest $request, ?int $rank = null): array
    {
        $due = $request->first_response_due_at ?? $request->created_at;

        return [
            'key' => $request->key,
            'title' => $request->title,
            'type' => $request->type->value,
            'status' => $request->status->value,
            'company_id' => (int) $request->company_id,
            'area' => self::area($request->area),
            'requester' => self::person($request->requester),
            'hours_per_week' => self::number($request->hours_per_week),
            'people' => $request->people_count,
            'votes' => $request->votes_count,
            'size' => $request->size?->value,
            'priority_suggested' => $request->priority_score,
            'priority_final' => $request->final_priority,
            'urgency' => $request->urgency?->value,
            'first_response' => [
                'due_at' => $due->toIso8601String(),
                'answered_at' => $request->first_responded_at?->toIso8601String(),
            ],
            'product' => $request->product !== null ? self::productRef($request->product, $request->product_version) : null,
            'project_key' => $request->projectKey(),
            'rank' => $rank,
            'similar_to' => null,
            'created_at' => $request->created_at->toIso8601String(),
        ];
    }
}
