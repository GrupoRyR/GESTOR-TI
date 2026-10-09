<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Open door for the monitoring module (PROMPT §2): web or local app whose
 * health will be tracked. No endpoints or logic yet; heartbeats will arrive
 * through the reserved `/api/local-apps/v1` (routes/api.php).
 *
 * Not company-scoped: `company_id` is optional (group-wide apps).
 * `ai_product_id` points to `ai_products` (foreign key, null on delete).
 *
 * @property int $id
 * @property ?int $company_id
 * @property string $slug
 * @property string $name
 * @property string $type
 * @property ?string $url
 * @property string $criticality
 * @property ?string $last_status
 * @property ?Carbon $last_heartbeat_at
 * @property int $heartbeat_interval_s
 * @property ?int $ai_product_id
 */
#[Fillable([
    'company_id', 'slug', 'name', 'type', 'url', 'criticality', 'last_status',
    'last_heartbeat_at', 'heartbeat_interval_s', 'ai_product_id',
])]
class MonitoredApp extends Model
{
    public const TYPE_WEB = 'web';

    public const TYPE_LOCAL = 'local';

    public const TYPES = [self::TYPE_WEB, self::TYPE_LOCAL];

    public const CRITICALITIES = ['high', 'medium', 'low'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_heartbeat_at' => 'datetime',
            'heartbeat_interval_s' => 'integer',
            'ai_product_id' => 'integer',
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
     * @return BelongsTo<AiProduct, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(AiProduct::class, 'ai_product_id')->withTrashed();
    }
}
