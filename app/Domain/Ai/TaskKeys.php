<?php

namespace App\Domain\Ai;

/**
 * Task keys `T{n}`, unique inside their project and never reused (a
 * deleted T2 leaves a gap): one `sequences` row per project
 * (`ai_tasks:{project id}`).
 */
final class TaskKeys
{
    public const PREFIX = 'T';

    public function next(int $projectId): int
    {
        return Sequence::next('ai_tasks:'.$projectId);
    }
}
