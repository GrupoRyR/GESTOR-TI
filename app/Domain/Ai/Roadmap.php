<?php

namespace App\Domain\Ai;

use App\Enums\Ai\RequestStatus;
use App\Models\AiRequest;
use App\Models\Scopes\CompanyScope;
use Illuminate\Database\Eloquent\Builder;

/**
 * "Hoja de ruta" (PROMPT 6.4.1 transparency): which requests appear, in
 * which column, and their place in their area's queue.
 *
 * Columns (design `RoadmapColumn`):
 * - `now`   "En construcción": En desarrollo, En pruebas.
 * - `next`  "Lo que sigue": Aprobada, Planificada.
 * - `later` "Más adelante": Pendiente de aprobación, En backlog.
 * Received/evaluating/needs info, production, closed and rejected requests
 * are not on the roadmap.
 *
 * Rank rule (`roadmap_position`, "Puesto N en Compras"): only `later`
 * requests are ranked, per area (an area belongs to one company), ordered
 * by final priority (highest first; not evaluated yet = last), then by +1
 * votes (most first), then oldest first, then key number. The design
 * fixtures rank pending-approval requests too (GIA-146 "Puesto 3"), so
 * approved/planned requests are not ranked: they already left the queue.
 * Every column uses the same order.
 */
final class Roadmap
{
    public const NOW = [RequestStatus::InDevelopment, RequestStatus::InTesting];

    public const NEXT = [RequestStatus::Approved, RequestStatus::Planned];

    public const LATER = [RequestStatus::PendingApproval, RequestStatus::Backlog];

    /**
     * @return list<string>
     */
    public static function statusValues(): array
    {
        return array_map(fn (RequestStatus $status) => $status->value, [...self::NOW, ...self::NEXT, ...self::LATER]);
    }

    /**
     * @return 'now'|'next'|'later'|null
     */
    public static function column(RequestStatus $status): ?string
    {
        return match (true) {
            in_array($status, self::NOW, true) => 'now',
            in_array($status, self::NEXT, true) => 'next',
            in_array($status, self::LATER, true) => 'later',
            default => null,
        };
    }

    public static function isRanked(RequestStatus $status): bool
    {
        return in_array($status, self::LATER, true);
    }

    /**
     * Applies the roadmap order (see the class rule).
     *
     * @template TBuilder of Builder<AiRequest>
     *
     * @param  TBuilder  $query
     * @return TBuilder
     */
    public static function order(Builder $query): Builder
    {
        return $query
            ->orderByRaw('final_priority IS NULL')
            ->orderByDesc('final_priority')
            ->orderByDesc('votes_count')
            ->orderBy('created_at')
            ->orderBy('id');
    }

    /**
     * Rank of one request in its area, or null when it is not queued.
     */
    public function rank(AiRequest $request): ?int
    {
        return $this->ranks([$request])[$request->id] ?? null;
    }

    /**
     * Ranks of several requests at once: request id => rank (queued ones only).
     *
     * @param  iterable<AiRequest>  $requests
     * @return array<int, int>
     */
    public function ranks(iterable $requests): array
    {
        $areaIds = [];

        foreach ($requests as $request) {
            if ($request->area_id !== null && self::isRanked($request->status)) {
                $areaIds[(int) $request->area_id] = true;
            }
        }

        if ($areaIds === []) {
            return [];
        }

        $queued = self::order(
            AiRequest::query()
                ->withoutGlobalScope(CompanyScope::class)
                ->whereIn('area_id', array_keys($areaIds))
                ->whereIn('status', array_map(fn (RequestStatus $status) => $status->value, self::LATER))
        )->get(['id', 'area_id']);

        $ranks = [];
        $positions = [];

        foreach ($queued as $row) {
            $area = (int) $row->area_id;
            $positions[$area] = ($positions[$area] ?? 0) + 1;
            $ranks[$row->id] = $positions[$area];
        }

        return $ranks;
    }
}
