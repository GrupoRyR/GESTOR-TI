<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Wizard autosave ("Se guarda solo"): one draft per person, the wizard
 * fields without files (`RequestDraftPayload`). Deleted when the request
 * is sent.
 *
 * @property int $id
 * @property int $user_id
 * @property array<string, mixed> $data
 * @property Carbon $saved_at
 */
#[Fillable(['user_id', 'data', 'saved_at'])]
class AiRequestDraft extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'data' => 'array',
            'saved_at' => 'datetime',
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
