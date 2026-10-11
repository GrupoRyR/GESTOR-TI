<?php

namespace App\Domain\Ai;

/**
 * Project keys `P-{n}` from the `sequences` row `ai_projects` (locked
 * increment, never reused), shared by every company.
 */
final class ProjectKeys
{
    public const PREFIX = 'P-';

    private const SEQUENCE = 'ai_projects';

    public function next(): string
    {
        return self::PREFIX.Sequence::next(self::SEQUENCE);
    }
}
