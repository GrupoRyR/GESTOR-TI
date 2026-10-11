<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * A cost of a project entered by hand (PROMPT 6.4.11, design `ProjectCost`):
 * type license|hosting|external_provider|other, recurrence once|monthly,
 * amount in COP, date and an optional receipt on the private disk. Team
 * hours and AI usage are computed rows (App\Domain\Ai\ProjectCosts).
 * Only holders of `ai.costs.view` ever see them.
 *
 * @property int $id
 * @property int $ai_project_id
 * @property string $type
 * @property string $concept
 * @property string $recurrence
 * @property int $amount
 * @property Carbon $date
 * @property ?string $attachment_disk
 * @property ?string $attachment_path
 * @property ?string $attachment_name
 * @property ?string $attachment_mime
 * @property ?int $attachment_size
 * @property ?int $created_by_id
 */
#[Fillable([
    'ai_project_id', 'type', 'concept', 'recurrence', 'amount', 'date', 'attachment_disk', 'attachment_path',
    'attachment_name', 'attachment_mime', 'attachment_size', 'created_by_id',
])]
class AiProjectCost extends Model
{
    use LogsActivity;

    /** Types entered by hand (`team` and `ai_usage` are computed). */
    public const TYPES = ['license', 'hosting', 'external_provider', 'other'];

    public const RECURRENCES = ['once', 'monthly'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'date' => 'date',
            'attachment_size' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<AiProject, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(AiProject::class, 'ai_project_id')->withoutGlobalScopes();
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('ai')
            ->logOnly(['ai_project_id', 'type', 'concept', 'recurrence', 'date'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
