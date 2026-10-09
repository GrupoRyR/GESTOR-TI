<?php

namespace App\Enums\Ai;

/**
 * Mirrors the design's `ApprovalDecision` (resources/js/types/ai.ts).
 */
enum ApprovalDecision: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
}
