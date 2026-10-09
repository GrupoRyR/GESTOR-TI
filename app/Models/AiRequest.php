<?php

namespace App\Models;

use App\Domain\Ai\PriorityCalculator;
use App\Domain\Ai\RequestKeys;
use App\Enums\Ai\AreaApprovalStatus;
use App\Enums\Ai\EffortSize;
use App\Enums\Ai\RejectReason;
use App\Enums\Ai\RequestStatus;
use App\Enums\Ai\RequestType;
use App\Enums\Ai\RiskLevel;
use App\Enums\Ai\Urgency;
use App\Models\Concerns\BelongsToCompany;
use Database\Factories\AiRequestFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * A Gestor IA request ("solicitud", key GIA-n). Company data
 * (`BelongsToCompany`); every child table is reached through it.
 *
 * Lifecycle changes go through App\Actions\Ai\TransitionRequest (history,
 * notification, activity); priority through `recomputePriority()`.
 *
 * @property int $id
 * @property string $key
 * @property int $company_id
 * @property ?int $area_id
 * @property int $requester_id
 * @property int $created_by_id
 * @property RequestType $type
 * @property string $title
 * @property ?int $product_id
 * @property ?string $product_version
 * @property string $problem
 * @property ?string $who_suffers
 * @property ?string $current_process
 * @property ?string $hours_per_week
 * @property ?int $people_count
 * @property ?string $expected_result
 * @property ?string $success_criteria
 * @property ?list<string> $systems
 * @property ?string $systems_notes
 * @property ?Urgency $urgency
 * @property ?Carbon $deadline
 * @property ?string $deadline_reason
 * @property RequestStatus $status
 * @property ?AreaApprovalStatus $area_approval_status
 * @property ?EffortSize $size
 * @property ?RiskLevel $risk
 * @property ?int $impact_money_per_month
 * @property int $votes_count
 * @property ?int $priority_score
 * @property ?int $final_priority
 * @property bool $priority_overridden
 * @property ?int $evaluated_by_id
 * @property ?Carbon $evaluated_at
 * @property ?Carbon $first_response_due_at
 * @property ?Carbon $first_responded_at
 * @property ?RejectReason $rejection_reason
 * @property ?string $rejection_message
 * @property ?Carbon $closed_at
 * @property Carbon $created_at
 */
#[Fillable([
    'key', 'company_id', 'area_id', 'requester_id', 'created_by_id', 'type', 'title', 'product_id', 'product_version',
    'problem', 'who_suffers', 'current_process', 'hours_per_week', 'people_count', 'expected_result',
    'success_criteria', 'systems', 'systems_notes', 'urgency', 'deadline', 'deadline_reason', 'status',
    'area_approval_status', 'size', 'risk', 'impact_money_per_month', 'votes_count', 'priority_score',
    'final_priority', 'priority_overridden', 'evaluated_by_id', 'evaluated_at', 'first_response_due_at',
    'first_responded_at', 'rejection_reason', 'rejection_message', 'closed_at',
])]
class AiRequest extends Model
{
    /** @use HasFactory<AiRequestFactory> */
    use BelongsToCompany, HasFactory, LogsActivity, SoftDeletes;

    protected $attributes = [
        'status' => 'received',
        'votes_count' => 0,
        'priority_overridden' => false,
    ];

    protected static function booted(): void
    {
        static::creating(function (AiRequest $request): void {
            $keys = app(RequestKeys::class);

            if (blank($request->key)) {
                $request->key = $keys->next();
            } elseif (preg_match('/^'.RequestKeys::PREFIX.'(\d+)$/', (string) $request->key, $match) === 1) {
                // Imported/explicit keys push the sequence past them.
                $keys->ensureAtLeast((int) $match[1]);
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => RequestType::class,
            'status' => RequestStatus::class,
            'area_approval_status' => AreaApprovalStatus::class,
            'urgency' => Urgency::class,
            'size' => EffortSize::class,
            'risk' => RiskLevel::class,
            'rejection_reason' => RejectReason::class,
            'systems' => 'array',
            'hours_per_week' => 'decimal:1',
            'people_count' => 'integer',
            'votes_count' => 'integer',
            'priority_score' => 'integer',
            'final_priority' => 'integer',
            'impact_money_per_month' => 'integer',
            'priority_overridden' => 'boolean',
            'deadline' => 'date',
            'evaluated_at' => 'datetime',
            'first_response_due_at' => 'datetime',
            'first_responded_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'key';
    }

    /**
     * Recomputes the suggested priority from the current hours, people,
     * votes, size and urgency (no save). The final priority follows the
     * suggestion until TI overrides it.
     */
    public function recomputePriority(): void
    {
        $this->priority_score = PriorityCalculator::suggested(
            $this->hours_per_week === null ? null : (float) $this->hours_per_week,
            $this->people_count === null ? null : (float) $this->people_count,
            $this->votes_count,
            $this->size,
            $this->urgency,
        );

        if (! $this->priority_overridden) {
            $this->final_priority = $this->priority_score;
        }
    }

    /**
     * Requester's hours per week × people (null while incomplete).
     */
    public function teamHours(): ?float
    {
        if ($this->hours_per_week === null || $this->people_count === null) {
            return null;
        }

        return (float) $this->hours_per_week * $this->people_count;
    }

    /**
     * @return BelongsTo<Area, $this>
     */
    public function area(): BelongsTo
    {
        return $this->belongsTo(Area::class)->withoutGlobalScopes();
    }

    /**
     * Primary area plus benefited areas (`is_primary`, `note` on the pivot).
     *
     * @return BelongsToMany<Area, $this>
     */
    public function benefitedAreas(): BelongsToMany
    {
        return $this->belongsToMany(Area::class, 'ai_request_area')
            ->withoutGlobalScopes()
            ->withPivot(['is_primary', 'note'])
            ->withTimestamps();
    }

    /**
     * Product the request is about (soft-deleted products still resolve).
     *
     * @return BelongsTo<AiProduct, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(AiProduct::class, 'product_id')->withTrashed();
    }

    /**
     * Product versions that delivered this request.
     *
     * @return BelongsToMany<AiProductVersion, $this>
     */
    public function productVersions(): BelongsToMany
    {
        return $this->belongsToMany(AiProductVersion::class, 'ai_product_version_request')->withTimestamps();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function evaluatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'evaluated_by_id');
    }

    /**
     * @return HasMany<AiRequestVote, $this>
     */
    public function votes(): HasMany
    {
        return $this->hasMany(AiRequestVote::class);
    }

    /**
     * Every comment, internal notes included: filter with
     * `AiRequestComment::visibleTo()` before showing them.
     *
     * @return HasMany<AiRequestComment, $this>
     */
    public function comments(): HasMany
    {
        return $this->hasMany(AiRequestComment::class);
    }

    /**
     * @return HasMany<AiRequestAttachment, $this>
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(AiRequestAttachment::class);
    }

    /**
     * @return HasMany<AcceptanceCriterion, $this>
     */
    public function criteria(): HasMany
    {
        return $this->hasMany(AcceptanceCriterion::class)->orderBy('position')->orderBy('id');
    }

    /**
     * @return HasMany<AiRequestApproval, $this>
     */
    public function approvals(): HasMany
    {
        return $this->hasMany(AiRequestApproval::class);
    }

    /**
     * @return HasMany<AiRequestStatusChange, $this>
     */
    public function statusChanges(): HasMany
    {
        return $this->hasMany(AiRequestStatusChange::class)->orderBy('id');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('ai')
            ->logOnly([
                'key', 'company_id', 'area_id', 'requester_id', 'type', 'title', 'product_id', 'status',
                'area_approval_status', 'urgency', 'size', 'risk', 'priority_score', 'final_priority',
                'rejection_reason',
            ])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
