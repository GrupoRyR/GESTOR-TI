<?php

namespace App\Models;

use App\Enums\Ai\AcceptanceResult;
use Database\Factories\AcceptanceCriterionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * "Cómo sabría que quedó bien": validated by the requester while the request
 * is "En pruebas" (App\Actions\Ai\ValidateAcceptance).
 *
 * @property int $id
 * @property int $ai_request_id
 * @property int $position
 * @property string $text
 * @property ?string $note
 * @property ?AcceptanceResult $result
 * @property ?string $result_comment
 * @property ?int $attachment_id
 * @property ?int $validated_by_id
 * @property ?Carbon $validated_at
 */
#[Fillable([
    'ai_request_id', 'position', 'text', 'note', 'result', 'result_comment', 'attachment_id',
    'validated_by_id', 'validated_at',
])]
class AcceptanceCriterion extends Model
{
    /** @use HasFactory<AcceptanceCriterionFactory> */
    use HasFactory;

    protected $table = 'acceptance_criteria';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'result' => AcceptanceResult::class,
            'validated_at' => 'datetime',
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
     * Photo of a failed criterion.
     *
     * @return BelongsTo<AiRequestAttachment, $this>
     */
    public function attachment(): BelongsTo
    {
        return $this->belongsTo(AiRequestAttachment::class, 'attachment_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function validatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'validated_by_id');
    }
}
