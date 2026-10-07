<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Models\Exceptions\ExclusivePermissionException;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Spatie\Permission\Traits\HasRoles;

/**
 * Users are not company-scoped (authentication must resolve them before any
 * company context exists); `company_id` is their home company.
 *
 * Identity, privilege and lockout columns (company_id, azure_id, tenant_id,
 * is_global, last_login_at, failed_login_attempts, locked_until) are
 * deliberately not fillable: set them with `forceFill()`.
 *
 * @property ?int $company_id
 * @property ?string $avatar_url
 * @property ?string $azure_id
 * @property ?string $tenant_id
 * @property bool $is_global
 * @property ?Carbon $last_login_at
 * @property int $failed_login_attempts
 * @property ?Carbon $locked_until
 */
#[Fillable(['name', 'email', 'password', 'avatar_url'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    use HasRoles {
        HasRoles::givePermissionTo as protected grantPermissions;
        HasRoles::syncPermissions as protected replacePermissions;
    }

    /**
     * Exclusive permissions (`ai.vault.access`) can only be granted through
     * their dedicated action (App\Actions\Users\GrantVaultAccess or the
     * configured vault owner in AssignInitialRoles).
     */
    public function givePermissionTo(...$permissions): static
    {
        $this->guardExclusiveGrant($permissions);

        return $this->grantPermissions(...$permissions);
    }

    public function syncPermissions(...$permissions): static
    {
        $this->guardExclusiveGrant($permissions);

        return $this->replacePermissions(...$permissions);
    }

    private function guardExclusiveGrant(array $permissions): void
    {
        if (Permission::exclusiveGrantAllowed()) {
            return;
        }

        $exclusive = Permission::exclusiveAmong($permissions);

        if ($exclusive !== []) {
            throw ExclusivePermissionException::forDirectGrant($exclusive[0]);
        }
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'is_global' => 'boolean',
            'failed_login_attempts' => 'integer',
            'locked_until' => 'datetime',
        ];
    }

    public function isLocked(): bool
    {
        return $this->locked_until !== null && $this->locked_until->isFuture();
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
