<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An incident of a product (PROMPT 6.4.2 "incidentes"), listed on its
 * page. While monitoring is off they are registered by hand from the
 * "Registrar incidente" drawer (`origin` manual); monitoring will add its
 * own (`origin` monitoring).
 *
 * @property int $id
 * @property int $ai_product_id
 * @property string $title
 * @property ?string $impact low | medium | high | critical
 * @property ?string $description
 * @property Carbon $started_at
 * @property ?Carbon $resolved_at
 * @property ?string $resolution
 * @property string $origin
 * @property int $reported_by_id
 * @property ?list<int> $notified_user_ids
 */
#[Fillable([
    'ai_product_id', 'title', 'impact', 'description', 'started_at', 'resolved_at', 'resolution', 'origin',
    'reported_by_id', 'notified_user_ids',
])]
class AiProductIncident extends Model
{
    /** Design `IncidentImpact`. */
    public const IMPACTS = ['low', 'medium', 'high', 'critical'];

    public const IMPACT_LABELS = ['low' => 'baja', 'medium' => 'media', 'high' => 'alta', 'critical' => 'crítica'];

    protected $attributes = [
        'origin' => 'manual',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'resolved_at' => 'datetime',
            'notified_user_ids' => 'array',
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
