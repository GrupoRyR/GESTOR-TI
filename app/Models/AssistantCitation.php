<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A knowledge card cited by an assistant answer: feeds the card's
 * `usage_count_month`, `last_cited_at` and `last_cited_by`.
 *
 * @property int $id
 * @property int $knowledge_item_id
 * @property int $message_id
 * @property ?int $user_id
 * @property CarbonImmutable $cited_at
 */
#[Fillable(['knowledge_item_id', 'message_id', 'user_id', 'cited_at'])]
class AssistantCitation extends Model
{
    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['cited_at' => 'immutable_datetime'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
