<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A `/aprender` draft (PROMPT 6.8.1): the raw text and the card the
 * assistant proposes. Created by App\Domain\Assistant\LearnDrafts and
 * decided by App\Actions\Assistant\DraftDecisions; nothing is published
 * until someone with `assistant.knowledge.manage` approves it.
 *
 * `proposed`: type, title, description, company_ids, area_id, owner_id,
 * visibility, review_at, fields (type fields by key) and origins (a field
 * key, `company`, `area` or `owner` => message | assumed).
 *
 * @property int $id
 * @property string $message
 * @property string $source
 * @property ?int $requested_by_id
 * @property array<string, mixed> $proposed
 * @property list<string> $missing
 * @property string $status
 * @property ?string $discard_reason
 * @property ?int $knowledge_item_id
 * @property ?int $decided_by_id
 * @property ?CarbonImmutable $decided_at
 * @property ?string $telegram_chat_id
 * @property ?int $telegram_message_id
 * @property CarbonImmutable $created_at
 */
#[Fillable([
    'message', 'source', 'requested_by_id', 'proposed', 'missing', 'status', 'discard_reason', 'knowledge_item_id',
    'decided_by_id', 'decided_at', 'telegram_chat_id', 'telegram_message_id',
])]
class KnowledgeDraft extends Model
{
    public const SOURCES = ['app', 'telegram'];

    protected $attributes = [
        'status' => 'pending',
        'source' => 'app',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'proposed' => 'array',
            'missing' => 'array',
            'decided_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'telegram_message_id' => 'integer',
        ];
    }

    /**
     * @param  Builder<KnowledgeDraft>  $query
     */
    public function scopePending(Builder $query): void
    {
        $query->where('status', 'pending');
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_id');
    }

    /**
     * @return BelongsTo<KnowledgeItem, $this>
     */
    public function card(): BelongsTo
    {
        return $this->belongsTo(KnowledgeItem::class, 'knowledge_item_id');
    }
}
