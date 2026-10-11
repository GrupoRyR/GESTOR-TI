<?php

namespace App\Domain\Ai;

use App\Models\AiProject;
use App\Support\Settings;

/**
 * Kanban columns of a project (PROMPT 6.4.3): the design's five
 * `KanbanColumnKey` with their default Spanish names. Every project gets
 * them when created; names can change per project, keys never do. Only
 * "En progreso" carries a WIP limit (Settings `ai.board.in_progress_limit`,
 * shown on the board, not enforced).
 */
final class Board
{
    public const BACKLOG = 'backlog';

    public const TODO = 'todo';

    public const IN_PROGRESS = 'in_progress';

    public const REVIEW = 'review';

    public const DONE = 'done';

    /** Key => default name, in board order. */
    public const COLUMNS = [
        self::BACKLOG => 'Backlog',
        self::TODO => 'Por hacer',
        self::IN_PROGRESS => 'En progreso',
        self::REVIEW => 'En revisión',
        self::DONE => 'Hecho',
    ];

    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_keys(self::COLUMNS);
    }

    public static function createColumns(AiProject $project): void
    {
        $limit = max(1, (int) app(Settings::class)->get('ai.board.in_progress_limit', 3));
        $position = 0;

        foreach (self::COLUMNS as $key => $name) {
            $project->columns()->firstOrCreate(['key' => $key], [
                'name' => $name,
                'position' => ++$position,
                'is_done' => $key === self::DONE,
                'wip_limit' => $key === self::IN_PROGRESS ? $limit : null,
            ]);
        }
    }

    /**
     * Design `tasks_by_column`: every key, zero included.
     *
     * @param  array<string, int>  $counts
     * @return array<string, int>
     */
    public static function counts(array $counts): array
    {
        $all = [];

        foreach (self::keys() as $key) {
            $all[$key] = (int) ($counts[$key] ?? 0);
        }

        return $all;
    }
}
