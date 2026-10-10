<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A question the assistant could not answer ("No lo tengo documentado"),
 * grouped by normalized text while open (App\Domain\Assistant\Unanswered).
 *
 * @property int $id
 * @property string $question
 * @property string $hash
 * @property int $times_asked
 * @property CarbonImmutable $first_asked_at
 * @property CarbonImmutable $last_asked_at
 * @property string $status
 * @property ?int $knowledge_item_id
 * @property ?int $resolved_by_id
 * @property ?CarbonImmutable $resolved_at
 */
#[Fillable([
    'question', 'hash', 'times_asked', 'first_asked_at', 'last_asked_at', 'status', 'knowledge_item_id',
    'resolved_by_id', 'resolved_at',
])]
class UnansweredQuestion extends Model
{
    protected $table = 'assistant_unanswered';

    protected $attributes = [
        'status' => 'open',
        'times_asked' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'times_asked' => 'integer',
            'first_asked_at' => 'immutable_datetime',
            'last_asked_at' => 'immutable_datetime',
            'resolved_at' => 'immutable_datetime',
        ];
    }

    /**
     * @param  Builder<UnansweredQuestion>  $query
     */
    public function scopeOpen(Builder $query): void
    {
        $query->where('status', 'open');
    }

    public function isOpen(): bool
    {
        return $this->status === 'open';
    }

    /**
     * @return HasMany<UnansweredAsk, $this>
     */
    public function asks(): HasMany
    {
        return $this->hasMany(UnansweredAsk::class, 'assistant_unanswered_id');
    }

    /**
     * @return BelongsTo<KnowledgeItem, $this>
     */
    public function card(): BelongsTo
    {
        return $this->belongsTo(KnowledgeItem::class, 'knowledge_item_id');
    }
}
