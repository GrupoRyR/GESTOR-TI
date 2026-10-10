<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A published version of a document ("Publicar versión", the first
 * version from its template, or a restore). Never edited or deleted.
 *
 * @property int $id
 * @property int $ai_document_id
 * @property int $version
 * @property string $body
 * @property string $summary
 * @property ?int $user_id
 * @property Carbon $created_at
 */
#[Fillable(['ai_document_id', 'version', 'body', 'summary', 'user_id', 'created_at'])]
class AiDocumentRevision extends Model
{
    public const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'version' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<AiDocument, $this>
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(AiDocument::class, 'ai_document_id')->withTrashed();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
