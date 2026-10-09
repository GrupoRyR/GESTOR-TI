<?php

namespace App\Enums\Ai;

/**
 * Mirrors the design's `Urgency` (resources/js/lib/priority.ts).
 */
enum Urgency: string
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
    case Urgent = 'urgent';

    public function label(): string
    {
        return match ($this) {
            self::Low => 'Puede esperar',
            self::Medium => 'Este trimestre',
            self::High => 'Este mes',
            self::Urgent => 'Urgente',
        };
    }
}
