<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * "¿Cuántas horas te ahorra por semana?" (PROMPT 6.4.5), asked 30 days after
 * release: the real saving to compare with the requester's estimate.
 *
 * @property int $id
 * @property int $ai_request_id
 * @property int $user_id
 * @property string $hours_per_week
 * @property Carbon $reported_at
 */
#[Fillable(['ai_request_id', 'user_id', 'hours_per_week', 'reported_at'])]
class AiHoursSavedReport extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'hours_per_week' => 'decimal:1',
            'reported_at' => 'datetime',
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
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
