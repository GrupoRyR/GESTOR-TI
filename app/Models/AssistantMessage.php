<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One chat message (types/assistant.ts `ChatMessage`). `payload` carries
 * what the kind needs (answer sources, person options, the access id of a
 * credential notice, the vault item of a link) and NEVER a secret value:
 * a credential value only exists in the `revealed_credential` prop of the
 * reveal response.
 *
 * @property int $id
 * @property int $conversation_id
 * @property string $role
 * @property string $kind
 * @property ?string $body
 * @property ?array<string, mixed> $payload
 * @property string $status
 * @property ?int $ai_interaction_id
 * @property CarbonImmutable $created_at
 */
#[Fillable(['conversation_id', 'role', 'kind', 'body', 'payload', 'status', 'ai_interaction_id'])]
class AssistantMessage extends Model
{
    public const ROLES = ['user', 'assistant', 'system'];

    public const KINDS = ['text', 'answer', 'no_answer', 'person_choice', 'credential_notice', 'vault_link', 'refused', 'error'];

    /** Kinds that stay in the 30-minute context sent to the provider. */
    public const CONTEXT_KINDS = ['text', 'answer', 'no_answer', 'refused'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<AssistantConversation, $this>
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(AssistantConversation::class, 'conversation_id');
    }

    /**
     * @return BelongsTo<AiInteraction, $this>
     */
    public function interaction(): BelongsTo
    {
        return $this->belongsTo(AiInteraction::class, 'ai_interaction_id');
    }

    public function data(string $key, mixed $default = null): mixed
    {
        return data_get($this->payload ?? [], $key, $default);
    }
}
