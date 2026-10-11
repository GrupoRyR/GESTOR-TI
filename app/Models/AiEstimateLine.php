<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Hours of one person (or a role, when nobody is named yet) in an
 * estimate, with the hourly rate in force when it was made (null = no rate).
 *
 * @property int $id
 * @property int $ai_estimate_id
 * @property ?int $user_id
 * @property ?string $role
 * @property string $hours
 * @property ?int $hourly_rate
 */
#[Fillable(['ai_estimate_id', 'user_id', 'role', 'hours', 'hourly_rate'])]
class AiEstimateLine extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'hours' => 'decimal:1',
            'hourly_rate' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<AiEstimate, $this>
     */
    public function estimate(): BelongsTo
    {
        return $this->belongsTo(AiEstimate::class, 'ai_estimate_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
