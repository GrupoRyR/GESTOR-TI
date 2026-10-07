<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One call to an AI provider (PROMPT §6.8.3): tokens with and without
 * cache, cost and duration. Written by the AI client (Stage B/C); the
 * monthly cap and the product costs are computed from this table.
 *
 * Not company-scoped: `company_id` is optional (calls without company
 * context). Reports must filter by company explicitly.
 *
 * @property int $id
 * @property ?int $company_id
 * @property ?int $user_id
 * @property string $provider
 * @property string $model
 * @property string $feature
 * @property ?string $source_type
 * @property ?int $source_id
 * @property int $input_tokens
 * @property int $cached_input_tokens
 * @property int $output_tokens
 * @property string $cost
 * @property string $currency
 * @property ?int $duration_ms
 * @property string $status
 * @property ?string $error
 */
#[Fillable([
    'company_id', 'user_id', 'provider', 'model', 'feature', 'source_type', 'source_id',
    'input_tokens', 'cached_input_tokens', 'output_tokens', 'cost', 'currency', 'duration_ms',
    'status', 'error',
])]
class AiInteraction extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'input_tokens' => 'integer',
            'cached_input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'cost' => 'decimal:6',
            'duration_ms' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function subject(): MorphTo
    {
        return $this->morphTo('source');
    }
}
