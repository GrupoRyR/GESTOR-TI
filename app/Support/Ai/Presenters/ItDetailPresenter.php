<?php

namespace App\Support\Ai\Presenters;

use App\Domain\Ai\Money;
use App\Domain\Ai\Timeline;
use App\Enums\Ai\CommentVisibility;
use App\Enums\Ai\RiskLevel;
use App\Models\AiRequest;
use App\Models\AiRequestComment;
use App\Models\User;

/**
 * The IT view of a request (`ItShowProps.request` and `evaluation`):
 * `RequestDetail` with internal notes, the full IT timeline and the full
 * estimate. Costs (`external_costs`, `total_cost`, money impact) only for
 * `ai.costs.view`.
 */
final class ItDetailPresenter
{
    /**
     * @param  ?AiRequest  $similar  first duplicate candidate (`similar_to`)
     * @return array<string, mixed>
     */
    public static function detail(AiRequest $request, User $viewer, ?int $rank, ?AiRequest $similar = null): array
    {
        return [
            ...RequesterDetailPresenter::detail($request, $rank),
            'similar_to' => $similar !== null ? TriagePresenter::similar($similar) : null,
            'timeline' => Timeline::forIt($request),
            'internal_notes' => self::internalNotes($request),
            'estimate' => self::estimate($request, $viewer->can('ai.costs.view')),
        ];
    }

    /**
     * `Evaluation`: impact from the requester's numbers, TI's size, urgency
     * and priority (final priority null until TI saves an evaluation).
     *
     * @return array<string, mixed>
     */
    public static function evaluation(AiRequest $request, User $viewer): array
    {
        $money = $viewer->can('ai.costs.view') ? Money::impactPerMonth($request) : null;

        return [
            'impact' => [
                'hours_per_week' => RequestPresenter::number($request->hours_per_week),
                'people' => $request->people_count,
                'money' => $money === null ? null : Money::cop($money).' al mes',
                'risk' => ($request->risk ?? RiskLevel::None)->value,
            ],
            'size' => $request->size?->value,
            'urgency' => $request->urgency?->value,
            'priority_suggested' => $request->priority_score,
            'priority_final' => $request->evaluated_at === null ? null : $request->final_priority,
        ];
    }

    /**
     * Latest estimate sent to approval (design `Estimate`), null before it.
     *
     * @return array<string, mixed>|null
     */
    public static function estimate(AiRequest $request, bool $withCosts): ?array
    {
        $estimate = $request->estimates()->reorder()->latest('id')->first();

        return $estimate === null ? null : TriagePresenter::estimateFromSnapshot($estimate->toSnapshot(), $withCosts);
    }

    /**
     * Internal notes, oldest first (`Comment` shape).
     *
     * @return list<array<string, mixed>>
     */
    private static function internalNotes(AiRequest $request): array
    {
        return $request->comments()
            ->where('visibility', CommentVisibility::Internal->value)
            ->with('author.employee')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->filter(fn (AiRequestComment $comment) => $comment->author !== null)
            ->map(fn (AiRequestComment $comment) => [
                'id' => $comment->id,
                'author' => RequestPresenter::person($comment->author),
                'is_it' => true,
                'body' => $comment->body,
                'created_at' => $comment->created_at->toIso8601String(),
            ])
            ->values()
            ->all();
    }
}
