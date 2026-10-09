<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Internal cost per hour of a person (COP), in force from `effective_from`
 * (PROMPT 6.4.11). Read through App\Domain\Ai\CostRates; only holders of
 * `ai.costs.view` ever receive a rate. Administration arrives in B6.
 *
 * @property int $id
 * @property int $user_id
 * @property int $rate
 * @property Carbon $effective_from
 * @property ?int $changed_by_id
 */
#[Fillable(['user_id', 'rate', 'effective_from', 'changed_by_id'])]
class AiCostRate extends Model
{
    use LogsActivity;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'rate' => 'integer',
            'effective_from' => 'date',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('ai')
            ->logOnly(['user_id', 'effective_from'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
