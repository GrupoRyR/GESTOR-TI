<?php

namespace App\Models;

use App\Domain\Ai\TaskKeys;
use Database\Factories\AiTaskFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A task of a project board (design `Task`, key T-n unique inside its
 * project, never reused). Company data through its project. A task is
 * blocked while an open requirement points at it (`blocks_task_id`); the
 * block clears when the requirement is answered. Moves go through
 * App\Actions\Ai\MoveTask (positions, project status).
 *
 * @property int $id
 * @property int $ai_project_id
 * @property int $ai_project_column_id
 * @property int $number
 * @property string $key
 * @property int $position
 * @property string $title
 * @property ?string $description
 * @property ?list<string> $labels
 * @property ?Carbon $due_date
 * @property ?string $estimate_hours
 * @property ?int $github_issue_number
 * @property ?string $github_issue_url
 * @property ?string $github_issue_state
 * @property ?int $github_pr_number
 * @property ?string $github_pr_url
 * @property ?string $github_pr_state
 * @property ?string $github_ci
 * @property ?Carbon $started_at
 * @property ?Carbon $completed_at
 * @property ?int $created_by_id
 * @property Carbon $created_at
 */
#[Fillable([
    'ai_project_id', 'ai_project_column_id', 'number', 'key', 'position', 'title', 'description', 'labels',
    'due_date', 'estimate_hours', 'github_issue_number', 'github_issue_url', 'github_issue_state',
    'github_pr_number', 'github_pr_url', 'github_pr_state', 'github_ci', 'started_at', 'completed_at',
    'created_by_id',
])]
class AiTask extends Model
{
    /** @use HasFactory<AiTaskFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::creating(function (AiTask $task): void {
            if (blank($task->key)) {
                $task->number = app(TaskKeys::class)->next((int) $task->ai_project_id);
                $task->key = TaskKeys::PREFIX.$task->number;
            }

            if ($task->position === null) {
                $task->position = (int) self::query()->where('ai_project_column_id', $task->ai_project_column_id)->max('position') + 1;
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'number' => 'integer',
            'position' => 'integer',
            'labels' => 'array',
            'due_date' => 'date',
            'estimate_hours' => 'decimal:1',
            'github_issue_number' => 'integer',
            'github_pr_number' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'key';
    }

    /**
     * @return BelongsTo<AiProject, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(AiProject::class, 'ai_project_id')->withoutGlobalScopes();
    }

    /**
     * @return BelongsTo<AiProjectColumn, $this>
     */
    public function column(): BelongsTo
    {
        return $this->belongsTo(AiProjectColumn::class, 'ai_project_column_id');
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function assignees(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'ai_task_assignees')->withTimestamps()->orderBy('ai_task_assignees.id');
    }

    /**
     * @return HasMany<AiSubtask, $this>
     */
    public function subtasks(): HasMany
    {
        return $this->hasMany(AiSubtask::class)->orderBy('position')->orderBy('id');
    }

    /**
     * @return HasMany<AiTaskComment, $this>
     */
    public function comments(): HasMany
    {
        return $this->hasMany(AiTaskComment::class)->orderBy('created_at')->orderBy('id');
    }

    /**
     * @return HasMany<AiTaskAttachment, $this>
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(AiTaskAttachment::class)->orderBy('id');
    }

    /**
     * Open requirements this task waits on, soonest due first.
     *
     * @return HasMany<AiRequirement, $this>
     */
    public function blockingRequirements(): HasMany
    {
        return $this->hasMany(AiRequirement::class, 'blocks_task_id')
            ->withoutGlobalScopes()
            ->where('status', AiRequirement::PENDING)
            ->orderBy('due_date')
            ->orderBy('id');
    }

    /**
     * @return HasMany<AiTimeEntry, $this>
     */
    public function timeEntries(): HasMany
    {
        return $this->hasMany(AiTimeEntry::class);
    }

    /**
     * @return list<string>
     */
    public function labelList(): array
    {
        return array_values(array_map('strval', (array) $this->labels));
    }
}
