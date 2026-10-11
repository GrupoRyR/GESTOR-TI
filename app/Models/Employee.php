<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Database\Factories\EmployeeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * An employee (colaborador) of a company, synchronized from Microsoft Graph
 * or created by hand. May or may not be a user of the app (`user_id`).
 *
 * The area comes from the department mapping unless it was assigned by
 * hand (`area_locked`).
 *
 * @property int $id
 * @property int $company_id
 * @property ?int $area_id
 * @property bool $area_locked
 * @property ?int $user_id
 * @property string $source
 * @property ?string $azure_id
 * @property string $name
 * @property ?string $email
 * @property ?string $job_title
 * @property ?string $department
 * @property ?string $manager_azure_id
 * @property ?int $manager_id
 * @property string $status
 * @property ?Carbon $last_synced_at
 */
#[Fillable([
    'company_id', 'area_id', 'area_locked', 'user_id', 'source', 'azure_id', 'name', 'email',
    'job_title', 'department', 'manager_azure_id', 'manager_id', 'status', 'last_synced_at',
])]
class Employee extends Model
{
    /** @use HasFactory<EmployeeFactory> */
    use BelongsToCompany, HasFactory, LogsActivity;

    public const SOURCE_GRAPH = 'graph';

    public const SOURCE_MANUAL = 'manual';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    public const STATUSES = [self::STATUS_ACTIVE, self::STATUS_INACTIVE];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'area_locked' => 'boolean',
            'last_synced_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Area, $this>
     */
    public function area(): BelongsTo
    {
        return $this->belongsTo(Area::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<self, $this>
     */
    public function manager(): BelongsTo
    {
        return $this->belongsTo(self::class, 'manager_id');
    }

    /**
     * @return HasMany<EmployeeAccess, $this>
     */
    public function accesses(): HasMany
    {
        return $this->hasMany(EmployeeAccess::class);
    }

    /**
     * Manual area assignment: a non-null area locks it (the sync keeps it);
     * null unlocks it and goes back to the department mapping.
     */
    public function assignArea(?int $areaId): void
    {
        if ($areaId === null) {
            $this->forceFill([
                'area_locked' => false,
                'area_id' => DepartmentAreaMapping::areaIdFor($this->company_id, $this->department),
            ])->save();

            return;
        }

        $this->forceFill(['area_id' => $areaId, 'area_locked' => true])->save();
    }

    /**
     * Base shape of an employee on the backend page `Admin/Employees/Show`.
     *
     * @return array{id: int, name: string, email: ?string, job_title: ?string, department: ?string, area: ?array{id: int, name: string}, status: string, user_id: ?int, source: string, last_synced_at: ?string}
     */
    public function toListArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'job_title' => $this->job_title,
            'department' => $this->department,
            'area' => $this->area ? ['id' => $this->area->id, 'name' => $this->area->name] : null,
            'status' => $this->status,
            'user_id' => $this->user_id,
            'source' => $this->source,
            'last_synced_at' => $this->last_synced_at?->toIso8601String(),
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('employees')
            ->logOnly([
                'company_id', 'area_id', 'area_locked', 'user_id', 'source', 'azure_id', 'name',
                'email', 'job_title', 'department', 'manager_id', 'status',
            ])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
