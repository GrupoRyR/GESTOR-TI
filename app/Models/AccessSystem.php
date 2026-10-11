<?php

namespace App\Models;

use Database\Factories\AccessSystemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * A system employees can have an access to (Office 365, SAP Business One,
 * VPN...). Global: shared by every company.
 *
 * @property int $id
 * @property string $name
 * @property ?string $description
 * @property bool $is_active
 */
#[Fillable(['name', 'description', 'is_active'])]
class AccessSystem extends Model
{
    /** @use HasFactory<AccessSystemFactory> */
    use HasFactory, LogsActivity;

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
     * @param  Builder<self>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * @return HasMany<EmployeeAccess, $this>
     */
    public function accesses(): HasMany
    {
        return $this->hasMany(EmployeeAccess::class);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('access_systems')
            ->logOnly(['name', 'description', 'is_active'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
