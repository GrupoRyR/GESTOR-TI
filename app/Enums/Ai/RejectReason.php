<?php

namespace App\Enums\Ai;

/**
 * Why a request was rejected. Mirrors the design's `RejectReason`
 * (resources/js/types/pages/ai-triage.ts).
 */
enum RejectReason: string
{
    case Exists = 'exists';
    case NotIt = 'not_it';
    case Cost = 'cost';
    case Duplicate = 'duplicate';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Exists => 'Ya existe una solución',
            self::NotIt => 'No le corresponde a TI',
            self::Cost => 'El costo no se justifica',
            self::Duplicate => 'Está duplicada',
            self::Other => 'Otro motivo',
        };
    }
}
