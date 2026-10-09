<?php

namespace App\Enums\Ai;

/**
 * Risk the request reduces (evaluation impact). Mirrors the design's
 * `RiskLevel` (resources/js/types/pages/ai-triage.ts).
 */
enum RiskLevel: string
{
    case None = 'none';
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
}
