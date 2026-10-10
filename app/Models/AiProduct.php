<?php

namespace App\Models;

use App\Enums\Ai\ProductStatus;
use App\Enums\Ai\RequestStatus;
use App\Models\Scopes\CompanyScope;
use App\Support\Tenancy\CurrentCompany;
use Database\Factories\AiProductFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * A Gestor IA product (PROMPT 6.4.2): an own app already in use, keyed by
 * the same `slug` as its Portal card.
 *
 * Not company-scoped by `CompanyScope`: a product may serve several
 * companies (`companies` pivot, one `is_primary`). Lists filter with
 * `visibleTo($user)`; who may open or manage it is `AiProductPolicy`.
 * `monthly_cost` is shown only when `costVisibleTo($user)`.
 *
 * @property int $id
 * @property string $slug
 * @property string $name
 * @property ?string $description
 * @property ?int $owner_user_id
 * @property ?string $owner_name
 * @property ?string $url
 * @property ?string $repository
 * @property ?string $stack
 * @property ?string $hosting
 * @property ?string $ai_provider
 * @property ?string $ai_model
 * @property ?string $monthly_cost
 * @property string $monthly_cost_currency
 * @property ProductStatus $status
 * @property ?string $current_version
 * @property ?string $notion_url
 */
#[Fillable([
    'slug', 'name', 'description', 'owner_user_id', 'owner_name', 'url', 'repository', 'stack', 'hosting',
    'ai_provider', 'ai_model', 'monthly_cost', 'monthly_cost_currency', 'status', 'current_version', 'notion_url',
])]
class AiProduct extends Model
{
    /** @use HasFactory<AiProductFactory> */
    use HasFactory, LogsActivity, SoftDeletes;

    public const HEALTH_INACTIVE_LABEL = 'Salud: monitoreo aún no activo';

    /**
     * Requests still waiting for or under work (delivered and finished
     * ones are not "open").
     *
     * @var list<RequestStatus>
     */
    private const NOT_OPEN_STATUSES = [RequestStatus::InProduction, RequestStatus::Rejected, RequestStatus::Closed];

    protected $attributes = [
        'status' => 'development',
        'monthly_cost_currency' => 'COP',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ProductStatus::class,
            'monthly_cost' => 'decimal:2',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /**
     * Products of the user's current company context (CurrentCompany):
     * everything for `companies.view_all` on "Todas", nothing for a user
     * without company.
     *
     * @param  Builder<AiProduct>  $query
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        $companies = app(CurrentCompany::class);
        $companyId = $companies->forUser($user);

        if ($companyId === null) {
            if (! $companies->canViewAll($user)) {
                $query->whereRaw('1 = 0');
            }

            return;
        }

        $query->whereHas('companies', fn (Builder $companies) => $companies->whereKey($companyId));
    }

    /**
     * @return BelongsToMany<Company, $this>
     */
    public function companies(): BelongsToMany
    {
        return $this->belongsToMany(Company::class, 'ai_product_company')
            ->withPivot('is_primary')
            ->withTimestamps();
    }

    /**
     * The company flagged `is_primary` (the design shows one `company_id`),
     * else the first one attached.
     */
    public function primaryCompany(): ?Company
    {
        return $this->companies()->orderByDesc('ai_product_company.is_primary')->orderBy('ai_product_company.id')->first();
    }

    /**
     * Areas that use the product (`is_primary` marks the main one).
     *
     * @return BelongsToMany<Area, $this>
     */
    public function areas(): BelongsToMany
    {
        return $this->belongsToMany(Area::class, 'ai_product_area')
            ->withoutGlobalScopes()
            ->withPivot('is_primary')
            ->withTimestamps();
    }

    public function primaryArea(): ?Area
    {
        return $this->areas()->orderByDesc('ai_product_area.is_primary')->orderBy('ai_product_area.id')->first();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    /**
     * Owner shown on the product card: the user's name, else the free text,
     * else null (the page shows its placeholder).
     */
    public function ownerLabel(): ?string
    {
        return $this->owner?->name ?? $this->owner_name;
    }

    /**
     * Requests about this product from every company (the company scope
     * does not apply: a product is multi-company).
     *
     * @return HasMany<AiRequest, $this>
     */
    public function requests(): HasMany
    {
        return $this->hasMany(AiRequest::class, 'product_id')->withoutGlobalScope(CompanyScope::class);
    }

    /**
     * Newest release first.
     *
     * @return HasMany<AiProductVersion, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(AiProductVersion::class)
            ->orderByDesc('released_at')
            ->orderByDesc('id');
    }

    public function currentVersionRecord(): ?AiProductVersion
    {
        if ($this->current_version === null) {
            return null;
        }

        return $this->versions()->where('version', $this->current_version)->first();
    }

    /**
     * Documentation of the product (PROMPT 6.4.4).
     *
     * @return HasMany<AiDocument, $this>
     */
    public function documents(): HasMany
    {
        return $this->hasMany(AiDocument::class, 'ai_product_id');
    }

    /**
     * Newest first.
     *
     * @return HasMany<AiProductIncident, $this>
     */
    public function incidents(): HasMany
    {
        return $this->hasMany(AiProductIncident::class)->orderByDesc('started_at')->orderByDesc('id');
    }

    /**
     * @return HasOne<MonitoredApp, $this>
     */
    public function monitoredApp(): HasOne
    {
        return $this->hasOne(MonitoredApp::class);
    }

    /**
     * AI calls attributed to this product (`ai_interactions.source`).
     *
     * @return MorphMany<AiInteraction, $this>
     */
    public function aiInteractions(): MorphMany
    {
        return $this->morphMany(AiInteraction::class, 'source');
    }

    /**
     * Health from monitoring (PROMPT 13.4). Until the monitoring module is
     * active and the product has a monitored app, it is the placeholder.
     *
     * @return array{monitoring_active: bool, status: ?string, label: string}
     */
    public function health(): array
    {
        $app = $this->monitoredApp;

        if ($app === null || ! (bool) config('modules.monitoring.active', false)) {
            return ['monitoring_active' => false, 'status' => null, 'label' => self::HEALTH_INACTIVE_LABEL];
        }

        return [
            'monitoring_active' => true,
            'status' => $app->last_status,
            'label' => 'Salud: '.($app->last_status ?? 'sin datos'),
        ];
    }

    public function openRequestsCount(): int
    {
        return $this->requests()
            ->whereNotIn('status', array_map(fn (RequestStatus $status) => $status->value, self::NOT_OPEN_STATUSES))
            ->count();
    }

    /**
     * Average of the "resolvió mi necesidad" scores (`ai_ratings`) of its
     * requests, one decimal; null without ratings.
     */
    public function averageRating(): ?float
    {
        $average = AiRating::query()->whereIn('ai_request_id', $this->requests()->select('id'))->avg('solved_need');

        return $average === null ? null : round((float) $average, 1);
    }

    public function ratingsCount(): int
    {
        return AiRating::query()->whereIn('ai_request_id', $this->requests()->select('id'))->count();
    }

    /**
     * Hours saved per month from the 30-day answers (`ai_hours_saved_reports`):
     * the reported hours per week of its requests × 52 / 12, one decimal;
     * null without answers.
     */
    public function hoursSaved(): ?float
    {
        $reports = AiHoursSavedReport::query()->whereIn('ai_request_id', $this->requests()->select('id'));

        if (! $reports->exists()) {
            return null;
        }

        return round((float) $reports->sum('hours_per_week') * 52 / 12, 1);
    }

    /**
     * AI spend of the current calendar month (app timezone), summed from
     * `ai_interactions` attributed to this product. In the interactions'
     * currency (USD for DeepSeek), not in `monthly_cost_currency`.
     */
    public function monthlyAiSpend(): float
    {
        return round((float) $this->aiInteractions()
            ->where('created_at', '>=', now()->startOfMonth())
            ->where('created_at', '<', now()->startOfMonth()->addMonth())
            ->sum('cost'), 6);
    }

    public function costVisibleTo(User $user): bool
    {
        return $user->can('ai.costs.view');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('ai')
            ->logOnly([
                'slug', 'name', 'owner_user_id', 'owner_name', 'url', 'repository', 'stack', 'hosting',
                'ai_provider', 'ai_model', 'monthly_cost', 'status', 'current_version',
            ])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
