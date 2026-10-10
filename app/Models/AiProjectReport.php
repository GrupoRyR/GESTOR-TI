<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Report settings of a project (design `ReportSchedule`, `ReportPayload`):
 * recipients `[{user_id, include_costs}]`, sections, version and the
 * weekly schedule (`next_send_at`, read by `ai:project-reports`).
 *
 * @property int $id
 * @property int $ai_project_id
 * @property string $periodicity
 * @property int $weekday
 * @property string $time
 * @property list<string> $sections
 * @property string $version
 * @property list<array{user_id: int, include_costs: bool}> $recipients
 * @property ?Carbon $last_sent_at
 * @property ?Carbon $next_send_at
 * @property ?int $updated_by_id
 */
#[Fillable([
    'ai_project_id', 'periodicity', 'weekday', 'time', 'sections', 'version', 'recipients', 'last_sent_at',
    'next_send_at', 'updated_by_id',
])]
class AiProjectReport extends Model
{
    public const PERIODICITIES = ['manual', 'weekly'];

    public const SECTIONS = ['status', 'done', 'next', 'blockers', 'budget'];

    public const VERSIONS = ['internal', 'requester'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'weekday' => 'integer',
            'sections' => 'array',
            'recipients' => 'array',
            'last_sent_at' => 'datetime',
            'next_send_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<AiProject, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(AiProject::class, 'ai_project_id')->withoutGlobalScopes();
    }
}
