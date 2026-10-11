<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One time somebody asked an unanswered question: who, the channel and the
 * chat ids the assistant engine (C3) and the bot (C4) pass along.
 *
 * @property int $id
 * @property int $assistant_unanswered_id
 * @property ?int $user_id
 * @property string $channel
 * @property ?int $conversation_id
 * @property ?int $message_id
 * @property CarbonImmutable $asked_at
 */
#[Fillable(['assistant_unanswered_id', 'user_id', 'channel', 'conversation_id', 'message_id', 'asked_at'])]
class UnansweredAsk extends Model
{
    public const CHANNELS = ['app', 'telegram'];

    public $timestamps = false;

    protected $table = 'assistant_unanswered_asks';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'conversation_id' => 'integer',
            'message_id' => 'integer',
            'asked_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
