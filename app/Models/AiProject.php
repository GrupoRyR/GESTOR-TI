<?php

namespace App\Models;

use App\Domain\Ai\Board;
use App\Domain\Ai\ProjectKeys;
use App\Enums\Ai\RequestStatus;
use App\Models\Concerns\BelongsToCompany;
use Database\Factories\AiProjectFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * A Gestor IA project (key P-n, PROMPT 6.4.3). Company data; born from
 * approved requests (App\Actions\Ai\ConvertRequestToProject). Its status
 * uses the request lifecycle keys `planned → in_development → in_testing
 * → in_production → closed` (design `ProjectStatus`); the board moves it
 * (App\Actions\Ai\MoveTask). The five board columns are created with it.
 *
 * @property int $id
 * @property string $key
 * @property int $company_id
 * @property ?int $area_id
 * @property string $name
 * @property ?string $objective
 * @property RequestStatus $status
 * @property ?int $lead_id
 * @property ?int $product_id
 * @property ?Carbon $start_date
 * @property ?Carbon $due_date
 * @property ?Carbon $started_at
 * @property ?Carbon $approved_at
 * @property ?string $repository_url
 * @property ?string $repository_branch
 * @property ?Carbon $reapproval_requested_at
 * @property ?int $created_by_id
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
#[Fillable([
    'key', 'company_id', 'area_id', 'name', 'objective', 'status', 'lead_id', 'product_id', 'start_date',
    'due_date', 'started_at', 'approved_at', 'repository_url', 'repository_branch', 'reapproval_requested_at',
    'created_by_id',
])]
class AiProject extends Model
{
    /** @use HasFactory<AiProjectFactory> */
    use BelongsToCompany, HasFactory, LogsActivity;

    /** Statuses a project may have (design `ProjectStatus`). */
    public const STATUSES = ['planned', 'in_development', 'in_testing', 'in_production', 'closed'];

    /** Active first in the list: en desarrollo, en pruebas, planificada. */
    public const ACTIVE_ORDER = ['in_development', 'in_testing', 'planned'];

    protected $attributes = [
        'status' => 'planned',
    ];

    protected static function booted(): void
    {
        static::creating(function (AiProject $project): void {
            if (blank($project->key)) {
                $project->key = app(ProjectKeys::class)->next();
            }
        });

        static::created(fn (AiProject $project) => Board::createColumns($project));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => RequestStatus::class,
            'start_date' => 'date',
            'due_date' => 'date',
            'started_at' => 'datetime',
            'approved_at' => 'datetime',
            'reapproval_requested_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'key';
    }

    /**
     * @return BelongsTo<Area, $this>
     */
    public function area(): BelongsTo
    {
        return $this->belongsTo(Area::class)->withoutGlobalScopes();
    }

    /**
     * Benefited areas (`note` on the pivot), the primary area excluded.
     *
     * @return BelongsToMany<Area, $this>
     */
    public function benefitedAreas(): BelongsToMany
    {
        return $this->belongsToMany(Area::class, 'ai_project_area')
            ->withoutGlobalScopes()
            ->withPivot('note')
            ->withTimestamps();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(User::class, 'lead_id');
    }

    /**
     * @return BelongsTo<AiProduct, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(AiProduct::class, 'product_id')->withTrashed();
    }

    /**
     * Team with its role ("Líder del proyecto", "Desarrollo"), in order.
     *
     * @return HasMany<AiProjectMember, $this>
     */
    public function members(): HasMany
    {
        return $this->hasMany(AiProjectMember::class)->orderBy('position')->orderBy('id');
    }

    /**
     * Requests the project came from (oldest link first).
     *
     * @return BelongsToMany<AiRequest, $this>
     */
    public function requests(): BelongsToMany
    {
        return $this->belongsToMany(AiRequest::class, 'ai_project_request')
            ->withoutGlobalScopes()
            ->whereNull('ai_requests.deleted_at')
            ->withTimestamps()
            ->orderBy('ai_project_request.id');
    }

    /**
     * @return HasMany<AiProjectColumn, $this>
     */
    public function columns(): HasMany
    {
        return $this->hasMany(AiProjectColumn::class)->orderBy('position');
    }

    public function column(string $key): AiProjectColumn
    {
        return $this->columns()->where('key', $key)->firstOrFail();
    }

    /**
     * @return HasMany<AiTask, $this>
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(AiTask::class);
    }

    /**
     * @return HasMany<AiRequirement, $this>
     */
    public function requirements(): HasMany
    {
        return $this->hasMany(AiRequirement::class)->withoutGlobalScopes();
    }

    /**
     * @return HasMany<AiProjectActivity, $this>
     */
    public function activities(): HasMany
    {
        return $this->hasMany(AiProjectActivity::class);
    }

    /**
     * @return HasMany<AiEstimate, $this>
     */
    public function estimates(): HasMany
    {
        return $this->hasMany(AiEstimate::class)->orderBy('id');
    }

    /**
     * @return HasMany<AiTimeEntry, $this>
     */
    public function timeEntries(): HasMany
    {
        return $this->hasMany(AiTimeEntry::class);
    }

    /**
     * Costs entered by hand (licenses, hosting, providers…).
     *
     * @return HasMany<AiProjectCost, $this>
     */
    public function costs(): HasMany
    {
        return $this->hasMany(AiProjectCost::class);
    }

    /**
     * Timers running right now on the project.
     *
     * @return HasMany<AiTimer, $this>
     */
    public function timers(): HasMany
    {
        return $this->hasMany(AiTimer::class);
    }

    /**
     * The request it came from (oldest link), whose approval rows the
     * re-approval reuses; null for a project without requests.
     */
    public function originRequest(): ?AiRequest
    {
        /** @var AiRequest|null $request */
        $request = $this->requests()->first();

        return $request;
    }

    /**
     * @return HasOne<AiProjectReport, $this>
     */
    public function report(): HasOne
    {
        return $this->hasOne(AiProjectReport::class);
    }

    /**
     * Writes an entry of the "Actividad" feed.
     */
    public function recordActivity(string $kind, string $text, ?User $actor = null): AiProjectActivity
    {
        return $this->activities()->create([
            'kind' => $kind,
            'text' => mb_substr($text, 0, 255),
            'user_id' => $actor?->id,
        ]);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('ai')
            ->logOnly(['key', 'company_id', 'area_id', 'name', 'status', 'lead_id', 'due_date'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
