<?php

namespace App\Domain\Ai;

use App\Enums\Ai\RequestStatus;
use DomainException;

/**
 * A status move the lifecycle does not allow (controllers answer 422).
 */
class InvalidTransition extends DomainException
{
    public static function between(string $key, RequestStatus $from, RequestStatus $to): self
    {
        return new self("La solicitud {$key} no puede pasar de «{$from->label()}» a «{$to->label()}».");
    }

    public static function awaitingAreaApproval(string $key): self
    {
        return new self("La solicitud {$key} espera el visto bueno del área.");
    }
}
