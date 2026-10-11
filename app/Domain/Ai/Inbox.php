<?php

namespace App\Domain\Ai;

use App\Enums\Ai\RequestStatus;
use App\Models\AiRequest;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Database\Eloquent\Builder;

/**
 * "Bandeja de solicitudes" (InboxIndexProps): requests TI still has to
 * triage — Recibida, En evaluación, Necesita información and Pendiente de
 * aprobación — in the current company context (CompanyScope).
 *
 * - Quick chips: `unanswered` (no first response yet), `needs_info`,
 *   `pending_approval`, `all`.
 * - Filters: `area_id`; `company_id` only in "Todas".
 * - Order: unanswered first by first-response deadline (soonest first),
 *   then the rest newest first.
 */
final class Inbox
{
    public const STATUSES = [RequestStatus::Received, RequestStatus::Evaluating, RequestStatus::NeedsInfo, RequestStatus::PendingApproval];

    public const QUICK = ['unanswered', 'needs_info', 'pending_approval', 'all'];

    public function __construct(private readonly CurrentCompany $companies) {}

    /**
     * @return list<string>
     */
    public static function statusValues(): array
    {
        return array_map(fn (RequestStatus $status) => $status->value, self::STATUSES);
    }

    /**
     * Inbox requests with the area/company filters (company only in "Todas").
     *
     * @return Builder<AiRequest>
     */
    public function query(?int $areaId = null, ?int $companyId = null): Builder
    {
        return AiRequest::query()
            ->whereIn('status', self::statusValues())
            ->when($areaId !== null, fn (Builder $query) => $query->where('area_id', $areaId))
            ->when($companyId !== null && $this->companies->isAll(), fn (Builder $query) => $query->where('company_id', $companyId));
    }

    /**
     * @param  Builder<AiRequest>  $query
     * @return Builder<AiRequest>
     */
    public static function quick(Builder $query, string $quick): Builder
    {
        return match ($quick) {
            'unanswered' => $query->whereNull('first_responded_at'),
            'needs_info' => $query->where('status', RequestStatus::NeedsInfo->value),
            'pending_approval' => $query->where('status', RequestStatus::PendingApproval->value),
            default => $query,
        };
    }

    /**
     * @param  Builder<AiRequest>  $query
     * @return Builder<AiRequest>
     */
    public static function order(Builder $query): Builder
    {
        return $query
            ->orderByRaw('CASE WHEN first_responded_at IS NULL THEN 0 ELSE 1 END')
            ->orderByRaw('CASE WHEN first_responded_at IS NULL THEN first_response_due_at END')
            ->orderByDesc('created_at')
            ->orderByDesc('id');
    }

    /**
     * @return array{unanswered: int, needs_info: int, pending_approval: int, all: int}
     */
    public function counts(?int $areaId = null, ?int $companyId = null): array
    {
        $counts = [];

        foreach (self::QUICK as $quick) {
            $counts[$quick] = self::quick($this->query($areaId, $companyId), $quick)->count();
        }

        /** @var array{unanswered: int, needs_info: int, pending_approval: int, all: int} $counts */
        return $counts;
    }
}
