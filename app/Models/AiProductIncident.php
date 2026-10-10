<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An incident of a product (PROMPT 6.4.2 "incidentes"), listed on its
 * page. There is no form to register one yet (not designed): monitoring
 * or a later form fills it.
 *
 * @property int $id
 * @property int $ai_product_id
 * @property string $title
 * @property Carbon $started_at
 * @property ?Carbon $resolved_at
 * @property int $reported_by_id
 */
#[Fillable(['ai_product_id', 'title', 'started_at', 'resolved_at', 'reported_by_id'])]
class AiProductIncident extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<AiProduct, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(AiProduct::class, 'ai_product_id')->withTrashed();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reportedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by_id');
    }
}
