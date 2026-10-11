<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Comment on a task (IT conversation, design `Comment`).
 *
 * @property int $id
 * @property int $ai_task_id
 * @property ?int $user_id
 * @property string $body
 * @property Carbon $created_at
 */
#[Fillable(['ai_task_id', 'user_id', 'body'])]
class AiTaskComment extends Model
{
    /**
     * @return BelongsTo<AiTask, $this>
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(AiTask::class, 'ai_task_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
