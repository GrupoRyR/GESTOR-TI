<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Entry of the project's "Actividad" feed (design `ProjectActivity`):
 * `kind` task|github|requirement|budget|comment and the sentence without
 * the actor ("movió T3 Endpoint de zonas a En revisión"); the actor is
 * null for system events.
 *
 * @property int $id
 * @property int $ai_project_id
 * @property string $kind
 * @property ?int $user_id
 * @property string $text
 * @property ?Carbon $created_at
 */
#[Fillable(['ai_project_id', 'kind', 'user_id', 'text'])]
class AiProjectActivity extends Model
{
    public const UPDATED_AT = null;

    /**
     * @return BelongsTo<AiProject, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(AiProject::class, 'ai_project_id')->withoutGlobalScopes();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
