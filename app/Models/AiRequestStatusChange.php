<?php

namespace App\Models;

use App\Enums\Ai\RequestStatus;
use Database\Factories\AiRequestStatusChangeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Status history of a request (the timeline of the design). `from_status`
 * null = created.
 *
 * @property int $id
 * @property int $ai_request_id
 * @property ?RequestStatus $from_status
 * @property RequestStatus $to_status
 * @property ?int $user_id
 * @property ?string $note
 * @property ?Carbon $created_at
 */
#[Fillable(['ai_request_id', 'from_status', 'to_status', 'user_id', 'note'])]
class AiRequestStatusChange extends Model
{
    /** @use HasFactory<AiRequestStatusChangeFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'from_status' => RequestStatus::class,
            'to_status' => RequestStatus::class,
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
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
