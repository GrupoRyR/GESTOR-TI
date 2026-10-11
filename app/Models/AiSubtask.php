<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Checklist item of a task (design `Subtask`).
 *
 * @property int $id
 * @property int $ai_task_id
 * @property string $title
 * @property bool $done
 * @property int $position
 * @property ?Carbon $done_at
 * @property ?int $done_by_id
 */
#[Fillable(['ai_task_id', 'title', 'done', 'position', 'done_at', 'done_by_id'])]
class AiSubtask extends Model
{
    protected $attributes = [
        'done' => false,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'done' => 'boolean',
            'position' => 'integer',
            'done_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<AiTask, $this>
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(AiTask::class, 'ai_task_id');
    }
}
