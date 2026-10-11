<?php

namespace App\Enums\Ai;

/**
 * TI effort size. Mirrors the design's `EffortSize` (resources/js/lib/priority.ts).
 */
enum EffortSize: string
{
    case S = 'S';
    case M = 'M';
    case L = 'L';
    case XL = 'XL';
}
