<?php

namespace App\Models;

use Database\Factories\AiRequestVoteFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * "+1 Es lo mismo": one per person and request; raises the impact.
 *
 * @property int $id
 * @property int $ai_request_id
 * @property int $user_id
 * @property ?Carbon $created_at
 */
#[Fillable(['ai_request_id', 'user_id'])]
class AiRequestVote extends Model
{
    /** @use HasFactory<AiRequestVoteFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

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
