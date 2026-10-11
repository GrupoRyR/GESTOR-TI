<?php

namespace App\Enums\Ai;

/**
 * Lifecycle of a Gestor IA product (PROMPT 6.4.2). Values mirror the
 * design's `ProductState` (resources/js/types/ai.ts); the column is
 * `ai_products.status`.
 */
enum ProductStatus: string
{
    case Development = 'development';
    case Pilot = 'pilot';
    case Production = 'production';
    case Retired = 'retired';

    public function label(): string
    {
        return match ($this) {
            self::Development => 'En desarrollo',
            self::Pilot => 'Piloto',
            self::Production => 'En producción',
            self::Retired => 'Retirado',
        };
    }
}
