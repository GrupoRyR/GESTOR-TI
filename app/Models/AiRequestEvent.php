<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * IT timeline entry that is not a status change (design `TimelineEvent`
 * kinds `estimate`, `approval`, `notification`, `merge`…): who evaluated it,
 * who sent it to approval, which notices went out. Status changes stay in
 * `ai_request_status_changes`; App\Domain\Ai\Timeline merges both. Only the
 * IT view and the approval history show them (they may name approvers and
 * internal steps, never costs).
 *
 * @property int $id
 * @property int $ai_request_id
 * @property string $kind
 * @property string $family
 * @property string $title
 * @property ?string $quote
 * @property ?string $detail
 * @property ?int $user_id
 * @property ?Carbon $created_at
 */
#[Fillable(['ai_request_id', 'kind', 'family', 'title', 'quote', 'detail', 'user_id'])]
class AiRequestEvent extends Model
{
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
