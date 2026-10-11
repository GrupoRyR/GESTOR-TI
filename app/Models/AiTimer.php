<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The running timer of a person (one per person, PROMPT 6.4.11): started
 * from a task (`ai.timer.start`), turned into an `ai_time_entries` row of
 * origin timer when stopped (App\Actions\Ai\StopTimer).
 *
 * @property int $id
 * @property int $user_id
 * @property int $ai_project_id
 * @property ?int $ai_task_id
 * @property Carbon $started_at
 */
#[Fillable(['user_id', 'ai_project_id', 'ai_task_id', 'started_at'])]
class AiTimer extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<AiProject, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(AiProject::class, 'ai_project_id')->withoutGlobalScopes();
    }

    /**
     * @return BelongsTo<AiTask, $this>
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(AiTask::class, 'ai_task_id');
    }
}
