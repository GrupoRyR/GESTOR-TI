<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * Generic alert raised by `App\Services\Alerts\AlertService`.
 *
 * Not company-scoped on purpose: `company_id` null is a group-wide alert,
 * which `CompanyScope` would hide. Listings must filter by company
 * explicitly (Stage B/monitoring).
 *
 * @property int $id
 * @property ?int $company_id
 * @property string $level
 * @property string $source
 * @property ?string $source_type
 * @property ?int $source_id
 * @property ?string $group_key
 * @property string $title
 * @property ?string $body
 * @property int $occurrences
 * @property Carbon $first_seen_at
 * @property Carbon $last_seen_at
 * @property ?Carbon $last_notified_at
 * @property ?Carbon $resolved_at
 */
#[Fillable([
    'company_id', 'level', 'source', 'source_type', 'source_id', 'group_key', 'title', 'body',
    'occurrences', 'first_seen_at', 'last_seen_at', 'last_notified_at', 'resolved_at',
])]
class Alert extends Model
{
    public const LEVEL_CRITICAL = 'critical';

    public const LEVEL_WARNING = 'warning';

    public const LEVEL_INFO = 'info';

    /**
     * Levels from most to least severe.
     */
    public const LEVELS = [self::LEVEL_CRITICAL, self::LEVEL_WARNING, self::LEVEL_INFO];

    /**
     * Visible names of the levels.
     */
    public const LEVEL_LABELS = [
        self::LEVEL_CRITICAL => 'Crítica',
        self::LEVEL_WARNING => 'Atención',
        self::LEVEL_INFO => 'Informativa',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'occurrences' => 'integer',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'last_notified_at' => 'datetime',
            'resolved_at' => 'datetime',
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
     * @return MorphTo<Model, $this>
     */
    public function subject(): MorphTo
    {
        return $this->morphTo('source');
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeUnresolved(Builder $query): void
    {
        $query->whereNull('resolved_at');
    }

    /**
     * Lower is more severe.
     */
    public static function severity(string $level): int
    {
        $index = array_search($level, self::LEVELS, true);

        return $index === false ? PHP_INT_MAX : $index;
    }

    public function levelLabel(): string
    {
        return self::LEVEL_LABELS[$this->level] ?? $this->level;
    }
}
