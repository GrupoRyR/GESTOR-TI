<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Database\Factories\DepartmentAreaMappingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Microsoft department → area of the same company. Departments are compared
 * by `department_key` (case, accents and extra spaces ignored).
 *
 * @property int $id
 * @property int $company_id
 * @property string $department
 * @property string $department_key
 * @property int $area_id
 */
#[Fillable(['company_id', 'department', 'area_id'])]
class DepartmentAreaMapping extends Model
{
    /** @use HasFactory<DepartmentAreaMappingFactory> */
    use BelongsToCompany, HasFactory, LogsActivity;

    protected static function booted(): void
    {
        static::saving(function (self $mapping): void {
            $mapping->department = Str::squish($mapping->department);
            $mapping->department_key = self::keyFor($mapping->department);
        });
    }

    /**
     * Normalized department: lower case, ASCII (no accents), single spaces.
     */
    public static function keyFor(?string $department): string
    {
        return Str::lower(Str::squish(Str::ascii((string) $department)));
    }

    /**
     * Area mapped to the department in the company, ignoring the current
     * company context (used by the sync and by manual unlocks).
     */
    public static function areaIdFor(int $companyId, ?string $department): ?int
    {
        $key = self::keyFor($department);

        if ($key === '') {
            return null;
        }

        $areaId = self::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('department_key', $key)
            ->value('area_id');

        return $areaId === null ? null : (int) $areaId;
    }

    /**
     * @return array<string, int> department_key => area_id
     */
    public static function mapFor(int $companyId): array
    {
        return self::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->pluck('area_id', 'department_key')
            ->map(fn ($areaId) => (int) $areaId)
            ->all();
    }

    /**
     * @return BelongsTo<Area, $this>
     */
    public function area(): BelongsTo
    {
        return $this->belongsTo(Area::class);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('employees')
            ->logOnly(['company_id', 'department', 'area_id'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
