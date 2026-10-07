<?php

namespace App\Models;

use Database\Factories\EmployeeAccessFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Contracts\Activity;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * An access (user + password) of an employee to a system.
 *
 * The password is encrypted at rest with Laravel `Crypt` (APP_KEY), hidden
 * from serialization and never logged: the activity log only records that
 * it changed (`password_changed`) and the new `last_rotated_at`. The plain
 * value is only read through App\Services\Credentials\EmployeeCredentialService.
 *
 * Not company-scoped by itself: it is always reached through its employee
 * (which is).
 *
 * @property int $id
 * @property int $employee_id
 * @property int $access_system_id
 * @property string $username
 * @property string $password
 * @property ?string $notes
 * @property ?Carbon $last_rotated_at
 */
#[Fillable(['employee_id', 'access_system_id', 'username', 'password', 'notes'])]
#[Hidden(['password'])]
class EmployeeAccess extends Model
{
    /** @use HasFactory<EmployeeAccessFactory> */
    use HasFactory, LogsActivity;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'password' => 'encrypted',
            'last_rotated_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $access): void {
            if ($access->isDirty('password')) {
                $access->last_rotated_at = now();
            }
        });
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * @return BelongsTo<AccessSystem, $this>
     */
    public function system(): BelongsTo
    {
        return $this->belongsTo(AccessSystem::class, 'access_system_id');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('employee_accesses')
            ->logOnly(['employee_id', 'access_system_id', 'username', 'notes', 'last_rotated_at'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    /**
     * Records that the password changed, never its value.
     */
    public function tapActivity(Activity $activity, string $eventName): void
    {
        $changed = match ($eventName) {
            'created' => true,
            'updated' => $this->wasChanged('password'),
            default => false,
        };

        if ($changed) {
            $activity->properties = $activity->properties->put('password_changed', true);
        }
    }
}
