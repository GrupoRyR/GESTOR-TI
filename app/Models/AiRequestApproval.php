<?php

namespace App\Models;

use App\Enums\Ai\ApprovalChannel;
use App\Enums\Ai\ApprovalDecision;
use App\Enums\Ai\ApprovalKind;
use Database\Factories\AiRequestApprovalFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * One approver asked for one decision: area approval (the area approver)
 * or project approval (holders of `ai.projects.approve`, with the estimate
 * snapshot in view). Pending until decided by
 * App\Actions\Ai\RecordApprovalDecision (web or Telegram).
 *
 * @property int $id
 * @property int $ai_request_id
 * @property ApprovalKind $kind
 * @property int $user_id
 * @property ApprovalDecision $decision
 * @property ?string $comment
 * @property ?ApprovalChannel $channel
 * @property ?array<string, mixed> $estimate
 * @property bool $require_all
 * @property bool $is_reapproval
 * @property ?int $requested_by_id
 * @property ?Carbon $requested_at
 * @property ?Carbon $decided_at
 */
#[Fillable([
    'ai_request_id', 'kind', 'user_id', 'decision', 'comment', 'channel', 'estimate', 'require_all',
    'is_reapproval', 'requested_by_id', 'requested_at', 'decided_at',
])]
class AiRequestApproval extends Model
{
    /** @use HasFactory<AiRequestApprovalFactory> */
    use HasFactory, LogsActivity;

    protected $attributes = [
        'decision' => 'pending',
        'require_all' => false,
        'is_reapproval' => false,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => ApprovalKind::class,
            'decision' => ApprovalDecision::class,
            'channel' => ApprovalChannel::class,
            'estimate' => 'array',
            'require_all' => 'boolean',
            'is_reapproval' => 'boolean',
            'requested_at' => 'datetime',
            'decided_at' => 'datetime',
        ];
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopePending(Builder $query): void
    {
        $query->where('decision', ApprovalDecision::Pending->value);
    }

    /**
     * @return BelongsTo<AiRequest, $this>
     */
    public function request(): BelongsTo
    {
        return $this->belongsTo(AiRequest::class, 'ai_request_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_id');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('ai')
            ->logOnly(['ai_request_id', 'kind', 'user_id', 'decision', 'channel', 'comment'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
