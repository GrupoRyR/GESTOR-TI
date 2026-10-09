<?php

namespace App\Domain\Ai;

use App\Enums\Ai\RequestStatus as S;

/**
 * Allowed moves of the request lifecycle (PROMPT 6.4.1):
 *
 *   Recibida → En evaluación → (Necesita información) → Pendiente de aprobación
 *   → Aprobada / En backlog / Rechazada → Planificada → En desarrollo
 *   → En pruebas → En producción → Cerrada
 *
 * Only an approval decision (App\Actions\Ai\RecordApprovalDecision) moves a
 * request to `approved`; TI may withdraw it from approval, park it in the
 * backlog or reject it. A rejected request can be reopened for evaluation.
 */
final class RequestStatusMachine
{
    /**
     * @var array<string, list<S>>
     */
    private array $map;

    public function __construct()
    {
        $this->map = [
            S::Received->value => [S::Evaluating, S::NeedsInfo, S::PendingApproval, S::Backlog, S::Rejected],
            S::Evaluating->value => [S::NeedsInfo, S::PendingApproval, S::Backlog, S::Rejected],
            S::NeedsInfo->value => [S::Evaluating, S::Rejected],
            S::PendingApproval->value => [S::Approved, S::Rejected, S::Backlog, S::Evaluating],
            S::Approved->value => [S::Planned, S::Backlog],
            S::Backlog->value => [S::Evaluating, S::PendingApproval, S::Rejected],
            S::Rejected->value => [S::Evaluating],
            S::Planned->value => [S::InDevelopment, S::Backlog],
            S::InDevelopment->value => [S::InTesting, S::Planned],
            S::InTesting->value => [S::InProduction, S::InDevelopment],
            S::InProduction->value => [S::Closed, S::InTesting],
            S::Closed->value => [],
        ];
    }

    /**
     * @return list<S>
     */
    public function next(S $from): array
    {
        return $this->map[$from->value];
    }

    public function allows(S $from, S $to): bool
    {
        return in_array($to, $this->next($from), true);
    }

    /**
     * Statuses only an approval decision reaches (never a manual move).
     */
    public function requiresApprovalDecision(S $to): bool
    {
        return $to === S::Approved;
    }
}
