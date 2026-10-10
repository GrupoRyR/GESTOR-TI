<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * File of a task on the private disk (`ai/projects/{project}/tasks/{task}`),
 * downloaded through a signed, authorized route.
 *
 * @property int $id
 * @property int $ai_task_id
 * @property ?int $user_id
 * @property string $disk
 * @property string $path
 * @property string $original_name
 * @property string $mime
 * @property int $size
 */
#[Fillable(['ai_task_id', 'user_id', 'disk', 'path', 'original_name', 'mime', 'size'])]
class AiTaskAttachment extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['size' => 'integer'];
    }

    /**
     * @return BelongsTo<AiTask, $this>
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(AiTask::class, 'ai_task_id');
    }
}
