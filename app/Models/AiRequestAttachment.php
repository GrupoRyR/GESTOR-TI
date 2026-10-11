<?php

namespace App\Models;

use Database\Factories\AiRequestAttachmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * File of a request (wizard, conversation or failed-criterion photo) on the
 * private disk. Stored and validated by App\Domain\Ai\RequestAttachments.
 *
 * @property int $id
 * @property int $ai_request_id
 * @property ?int $ai_request_comment_id
 * @property ?int $user_id
 * @property string $disk
 * @property string $path
 * @property string $original_name
 * @property string $mime
 * @property int $size
 */
#[Fillable(['ai_request_id', 'ai_request_comment_id', 'user_id', 'disk', 'path', 'original_name', 'mime', 'size'])]
class AiRequestAttachment extends Model
{
    /** @use HasFactory<AiRequestAttachmentFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'size' => 'integer',
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
     * @return BelongsTo<AiRequestComment, $this>
     */
    public function comment(): BelongsTo
    {
        return $this->belongsTo(AiRequestComment::class, 'ai_request_comment_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
