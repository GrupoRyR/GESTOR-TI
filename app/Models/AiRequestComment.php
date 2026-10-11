<?php

namespace App\Models;

use App\Enums\Ai\CommentVisibility;
use Database\Factories\AiRequestCommentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Public conversation message or internal note (only `ai.manage`).
 *
 * @property int $id
 * @property int $ai_request_id
 * @property ?int $user_id
 * @property string $body
 * @property CommentVisibility $visibility
 * @property Carbon $created_at
 */
#[Fillable(['ai_request_id', 'user_id', 'body', 'visibility'])]
class AiRequestComment extends Model
{
    /** @use HasFactory<AiRequestCommentFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'visibility' => CommentVisibility::class,
        ];
    }

    /**
     * Comments the user may read: internal notes only with `ai.manage`.
     *
     * @param  Builder<self>  $query
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        if (! $user->can('ai.manage')) {
            $query->where('visibility', CommentVisibility::Public->value);
        }

        $query->orderBy('created_at')->orderBy('id');
    }

    public function isInternal(): bool
    {
        return $this->visibility === CommentVisibility::Internal;
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
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * @return HasMany<AiRequestAttachment, $this>
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(AiRequestAttachment::class);
    }
}
