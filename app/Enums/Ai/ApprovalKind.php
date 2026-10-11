<?php

namespace App\Enums\Ai;

/**
 * Area approval (visto bueno before TI) or project approval
 * (`ai.projects.approve`, with the estimate in view).
 */
enum ApprovalKind: string
{
    case Area = 'area';
    case Project = 'project';
}
