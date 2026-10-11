<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * "Calificar la entrega" (PROMPT 6.4.5): the requester rates a request once
 * it is in production, 1–5 for "resolvió mi necesidad" and "facilidad de
 * uso". Created by App\Actions\Ai\RateRequest; B7 adds the reminders.
 *
 * @property int $id
 * @property int $ai_request_id
 * @property int $user_id
 * @property int $solved_need
 * @property int $ease_of_use
 * @property ?string $comment
 * @property Carbon $rated_at
 */
#[Fillable(['ai_request_id', 'user_id', 'solved_need', 'ease_of_use', 'comment', 'rated_at'])]
class AiRating extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'solved_need' => 'integer',
            'ease_of_use' => 'integer',
            'rated_at' => 'datetime',
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
