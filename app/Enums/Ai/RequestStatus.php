<?php

namespace App\Enums\Ai;

/**
 * Lifecycle of a Gestor IA request. Keys mirror the design's
 * `RequestStatusKey` (resources/js/lib/status.ts) exactly; allowed moves
 * live in App\Domain\Ai\RequestStatusMachine.
 */
enum RequestStatus: string
{
    case Received = 'received';
    case Evaluating = 'evaluating';
    case NeedsInfo = 'needs_info';
    case PendingApproval = 'pending_approval';
    case Approved = 'approved';
    case Backlog = 'backlog';
    case Rejected = 'rejected';
    case Planned = 'planned';
    case InDevelopment = 'in_development';
    case InTesting = 'in_testing';
    case InProduction = 'in_production';
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::Received => 'Recibida',
            self::Evaluating => 'En evaluación',
            self::NeedsInfo => 'Necesita información',
            self::PendingApproval => 'Pendiente de aprobación',
            self::Approved => 'Aprobada',
            self::Backlog => 'En backlog',
            self::Rejected => 'Rechazada',
            self::Planned => 'Planificada',
            self::InDevelopment => 'En desarrollo',
            self::InTesting => 'En pruebas',
            self::InProduction => 'En producción',
            self::Closed => 'Cerrada',
        };
    }

    /**
     * Final states: no votes, no public conversation, no duplicates offered.
     */
    public function isFinished(): bool
    {
        return $this === self::Rejected || $this === self::Closed;
    }

    /**
     * @return list<string>
     */
    public static function finishedValues(): array
    {
        return [self::Rejected->value, self::Closed->value];
    }
}
