<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Estimate before approval (PROMPT 6.4.11, design `Estimate`): hours per
 * person or role, external costs and due date. Created by
 * App\Actions\Ai\SendToApproval (one per approval round, never edited);
 * the approval rows keep a JSON snapshot of it. B5 links it to the project
 * (`ai_project_id`) and B6 compares it with the real hours and costs.
 *
 * `total_cost` = Σ hours × rate in force + external costs; null while a
 * person has no rate.
 *
 * @property int $id
 * @property ?int $ai_request_id
 * @property ?int $ai_project_id
 * @property string $hours_total
 * @property int $external_costs
 * @property ?int $total_cost
 * @property Carbon $due_date
 * @property ?int $created_by_id
 * @property Carbon $created_at
 */
#[Fillable(['ai_request_id', 'ai_project_id', 'hours_total', 'external_costs', 'total_cost', 'due_date', 'created_by_id'])]
class AiEstimate extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'hours_total' => 'decimal:1',
            'external_costs' => 'integer',
            'total_cost' => 'integer',
            'due_date' => 'date',
        ];
    }

    /**
     * @return BelongsTo<AiRequest, $this>
     */
    public function request(): BelongsTo
    {
        return $this->belongsTo(AiRequest::class, 'ai_request_id');
    }

    /**
     * @return HasMany<AiEstimateLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(AiEstimateLine::class)->orderBy('id');
    }

    /**
     * JSON snapshot kept on each approval row (`ai_request_approvals.estimate`):
     * what the approver saw, even if rates or people change later.
     *
     * @return array<string, mixed>
     */
    public function toSnapshot(): array
    {
        $this->loadMissing('lines.user');

        $number = fn (mixed $value): int|float => floor((float) $value) === (float) $value ? (int) $value : (float) $value;

        return [
            'estimate_id' => $this->id,
            'hours_by_person' => $this->lines->map(fn (AiEstimateLine $line) => [
                'user_id' => $line->user_id,
                'name' => $line->user?->name,
                'role' => $line->role,
                'hours' => $number($line->hours),
                'hourly_rate' => $line->hourly_rate,
            ])->values()->all(),
            'hours_total' => $number($this->hours_total),
            'external_costs' => $this->external_costs,
            'due_date' => $this->due_date->toDateString(),
            'total_cost' => $this->total_cost,
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }
}
