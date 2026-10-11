<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A person of the project team with the role shown on the summary
 * ("Líder del proyecto", "Desarrollo").
 *
 * @property int $id
 * @property int $ai_project_id
 * @property int $user_id
 * @property string $role
 * @property int $position
 */
#[Fillable(['ai_project_id', 'user_id', 'role', 'position'])]
class AiProjectMember extends Model
{
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
