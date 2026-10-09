<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Database\Factories\AreaFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * A functional area of a company (Ventas, Contabilidad, TI...). Its
 * Microsoft departments are its `department_area_mappings`.
 *
 * @property int $id
 * @property int $company_id
 * @property string $name
 * @property string $slug
 * @property ?int $approver_user_id
 * @property bool $is_active
 */
#[Fillable(['company_id', 'name', 'slug', 'approver_user_id', 'is_active'])]
class Area extends Model
{
    /** @use HasFactory<AreaFactory> */
    use BelongsToCompany, HasFactory, LogsActivity;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * Aprobador de área (visto bueno); null = no approval needed.
     *
     * @return BelongsTo<User, $this>
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approver_user_id');
    }

    /**
     * Microsoft departments grouped in this area.
     *
     * @return HasMany<DepartmentAreaMapping, $this>
     */
    public function departmentMappings(): HasMany
    {
        return $this->hasMany(DepartmentAreaMapping::class);
    }

    /**
     * @return HasMany<Employee, $this>
     */
    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('areas')
            ->logOnly(['company_id', 'name', 'slug', 'approver_user_id', 'is_active'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
