<?php

namespace App\Models;

use App\Domain\Ai\BusinessDays;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Scopes\CompanyScope;
use Carbon\CarbonInterface;
use Database\Factories\AiRequirementFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Something IT needs from a person to move a project (PROMPT 6.4.3,
 * design `Requirement`): data, an access, a validation. Company data (the
 * project's company). Stored status is `pending` or `answered`; `overdue`
 * is a pending one past its due date (Bogotá calendar), computed by
 * `displayStatus()`. The recipient is a user, or null while IT does not
 * know who answers (placeholder label "[RESPONSABLE VENTAS]").
 *
 * Route binding ignores the company context: a recipient of another
 * company may answer; AiRequirementPolicy decides access.
 *
 * @property int $id
 * @property int $company_id
 * @property int $ai_project_id
 * @property string $title
 * @property ?string $detail
 * @property ?int $recipient_id
 * @property string $recipient_label
 * @property ?int $area_id
 * @property string $area_name
 * @property Carbon $due_date
 * @property string $status
 * @property int $reminders_sent
 * @property ?Carbon $last_reminded_at
 * @property ?int $blocks_task_id
 * @property ?int $requested_by_id
 * @property ?string $answer
 * @property ?Carbon $answered_at
 * @property ?int $answered_by_id
 * @property ?string $answer_disk
 * @property ?string $answer_path
 * @property ?string $answer_name
 * @property ?int $answer_size
 * @property Carbon $created_at
 */
#[Fillable([
    'company_id', 'ai_project_id', 'title', 'detail', 'recipient_id', 'recipient_label', 'area_id', 'area_name',
    'due_date', 'status', 'reminders_sent', 'last_reminded_at', 'blocks_task_id', 'requested_by_id', 'answer',
    'answered_at', 'answered_by_id', 'answer_disk', 'answer_path', 'answer_name', 'answer_size', 'created_at',
])]
class AiRequirement extends Model
{
    /** @use HasFactory<AiRequirementFactory> */
    use BelongsToCompany, HasFactory;

    public const PENDING = 'pending';

    public const ANSWERED = 'answered';

    public const OVERDUE = 'overdue';

    protected $attributes = [
        'status' => self::PENDING,
        'reminders_sent' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'due_date' => 'date',
            'reminders_sent' => 'integer',
            'last_reminded_at' => 'datetime',
            'answered_at' => 'datetime',
            'answer_size' => 'integer',
        ];
    }

    public function resolveRouteBinding($value, $field = null): ?Model
    {
        return $this->newQuery()
            ->withoutGlobalScope(CompanyScope::class)
            ->where($field ?? $this->getRouteKeyName(), $value)
            ->first();
    }

    public function isOpen(): bool
    {
        return $this->status === self::PENDING;
    }

    /**
     * Design status: pending, overdue (pending past its due date) or answered.
     */
    public function displayStatus(?CarbonInterface $today = null): string
    {
        if (! $this->isOpen()) {
            return self::ANSWERED;
        }

        $today ??= Carbon::now(BusinessDays::TIMEZONE)->startOfDay();

        return $this->due_date->toDateString() < $today->toDateString() ? self::OVERDUE : self::PENDING;
    }

    /**
     * @return BelongsTo<AiProject, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(AiProject::class, 'ai_project_id')->withoutGlobalScopes();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_id');
    }

    /**
     * @return BelongsTo<AiTask, $this>
     */
    public function blocksTask(): BelongsTo
    {
        return $this->belongsTo(AiTask::class, 'blocks_task_id');
    }

    /**
     * @return BelongsTo<Area, $this>
     */
    public function area(): BelongsTo
    {
        return $this->belongsTo(Area::class)->withoutGlobalScopes();
    }
}
