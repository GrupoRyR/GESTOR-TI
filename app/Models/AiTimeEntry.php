<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Hours a person logged on a project (PROMPT 6.4.11, design `TimeEntry`),
 * `origin` timer|manual|telegram, written by App\Actions\Ai\LogTime (the
 * timer, the "Agregar horas" form and Telegram `/horas`).
 *
 * @property int $id
 * @property int $user_id
 * @property int $ai_project_id
 * @property ?int $ai_task_id
 * @property Carbon $date
 * @property string $hours
 * @property string $origin
 * @property ?string $note
 * @property ?Carbon $started_at
 * @property ?Carbon $ended_at
 */
#[Fillable(['user_id', 'ai_project_id', 'ai_task_id', 'date', 'hours', 'origin', 'note', 'started_at', 'ended_at'])]
class AiTimeEntry extends Model
{
    public const ORIGINS = ['timer', 'manual', 'telegram'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'hours' => 'decimal:2',
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
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
