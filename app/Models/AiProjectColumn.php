<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Board column of a project. `key` is one of the design's
 * `KanbanColumnKey` (App\Domain\Ai\Board::KEYS); the name can change per
 * project; `is_done` marks "Hecho"; `wip_limit` is shown on the board
 * (Stage B only limits "En progreso", without blocking).
 *
 * @property int $id
 * @property int $ai_project_id
 * @property string $key
 * @property string $name
 * @property int $position
 * @property bool $is_done
 * @property ?int $wip_limit
 */
#[Fillable(['ai_project_id', 'key', 'name', 'position', 'is_done', 'wip_limit'])]
class AiProjectColumn extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'is_done' => 'boolean',
            'wip_limit' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<AiProject, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(AiProject::class, 'ai_project_id')->withoutGlobalScopes();
    }

    /**
     * @return HasMany<AiTask, $this>
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(AiTask::class);
    }
}
