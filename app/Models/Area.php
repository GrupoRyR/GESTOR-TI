<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Database\Factories\AreaFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * A functional area of a company (Ventas, Contabilidad, TI...).
 *
 * @property int $id
 * @property int $company_id
 * @property string $name
 * @property string $slug
 * @property bool $is_active
 */
#[Fillable(['company_id', 'name', 'slug', 'is_active'])]
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

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('areas')
            ->logOnly(['company_id', 'name', 'slug', 'is_active'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
