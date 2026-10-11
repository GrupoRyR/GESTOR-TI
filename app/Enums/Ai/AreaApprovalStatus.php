<?php

namespace App\Enums\Ai;

/**
 * Area approval step ("visto bueno de área") of a request. The design has
 * no status for it, so it is a flag beside `status`: null = not required,
 * pending = the request stays "received" and TI cannot start evaluating.
 */
enum AreaApprovalStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
}
