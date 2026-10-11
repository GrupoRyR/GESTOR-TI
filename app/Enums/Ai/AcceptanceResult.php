<?php

namespace App\Enums\Ai;

/**
 * Requester's validation of an acceptance criterion. Mirrors the design's
 * `ValidationCriterion.result` (resources/js/types/pages/ai-requester.ts).
 */
enum AcceptanceResult: string
{
    case Pass = 'pass';
    case Fail = 'fail';
}
