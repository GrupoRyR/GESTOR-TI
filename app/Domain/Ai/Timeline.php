<?php

namespace App\Domain\Ai;

use App\Enums\Ai\RequestStatus;
use App\Models\AiRequest;
use App\Models\AiRequestEvent;
use App\Models\AiRequestStatusChange;
use App\Support\Ai\Presenters\RequesterDetailPresenter;

/**
 * IT timeline of a request (design `TimelineEvent[]`, newest first): status
 * changes (`ai_request_status_changes`) plus triage events
 * (`ai_request_events`: evaluation, sent to approval, notices, merges).
 * Never carries costs.
 *
 * Ids: status change id × 2 and event id × 2 + 1, so both tables share one
 * key space. Ties on the same second list events first (they are written
 * after the status change of the same action), then the higher id.
 */
final class Timeline
{
    /**
     * @return list<array<string, mixed>>
     */
    public static function forIt(AiRequest $request): array
    {
        $request->loadMissing(['statusChanges.user', 'events.user']);

        $rows = [];

        foreach ($request->statusChanges as $change) {
            $rows[] = [self::fromStatusChange($request, $change), ($change->created_at ?? now())->getTimestamp(), 0, $change->id];
        }

        foreach ($request->events as $event) {
            $rows[] = [self::fromEvent($event), ($event->created_at ?? now())->getTimestamp(), 1, $event->id];
        }

        usort($rows, fn (array $a, array $b) => [$b[1], $b[2], $b[3]] <=> [$a[1], $a[2], $a[3]]);

        return array_map(fn (array $row) => $row[0], $rows);
    }

    /**
     * @return array<string, mixed>
     */
    private static function fromStatusChange(AiRequest $request, AiRequestStatusChange $change): array
    {
        $actor = $change->user?->name;
        $byRequester = $change->user_id !== null && (int) $change->user_id === (int) $request->requester_id;

        [$kind, $title, $detail] = match (true) {
            $change->to_status === RequestStatus::NeedsInfo => ['question', 'TI preguntó', null],
            $change->from_status === RequestStatus::NeedsInfo && $byRequester => ['answer', ($actor ?? 'El solicitante').' respondió', null],
            $change->to_status === RequestStatus::Approved => ['approval', $actor !== null ? "Aprobada por {$actor}" : 'Aprobada', null],
            $change->to_status === RequestStatus::Rejected => [
                'rejection',
                $actor !== null ? "Rechazada por {$actor}" : 'Rechazada',
                $request->rejection_reason !== null && $request->status === RequestStatus::Rejected ? $request->rejection_reason->label() : null,
            ],
            default => ['status', $change->to_status->label(), null],
        };

        return [
            'id' => $change->id * 2,
            'kind' => $kind,
            'family' => RequesterDetailPresenter::family($change->to_status),
            'title' => $title,
            'quote' => $change->note,
            'detail' => $detail,
            'actor' => $actor,
            'at' => ($change->created_at ?? now())->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function fromEvent(AiRequestEvent $event): array
    {
        return [
            'id' => $event->id * 2 + 1,
            'kind' => $event->kind,
            'family' => $event->family,
            'title' => $event->title,
            'quote' => $event->quote,
            'detail' => $event->detail,
            'actor' => $event->user?->name,
            'at' => ($event->created_at ?? now())->toIso8601String(),
        ];
    }
}
