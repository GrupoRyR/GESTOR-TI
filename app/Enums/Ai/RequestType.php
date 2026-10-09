<?php

namespace App\Enums\Ai;

/**
 * Mirrors the design's `RequestType` (resources/js/lib/wizard.ts).
 */
enum RequestType: string
{
    case New = 'new';
    case Improvement = 'improvement';
    case Bug = 'bug';
    case Idea = 'idea';

    public function label(): string
    {
        return match ($this) {
            self::New => 'Nuevo desarrollo',
            self::Improvement => 'Mejora o cambio',
            self::Bug => 'Error en una app',
            self::Idea => 'Idea o consulta',
        };
    }

    /**
     * Improvements and bugs are about an app in production.
     */
    public function needsProduct(): bool
    {
        return $this === self::Improvement || $this === self::Bug;
    }
}
