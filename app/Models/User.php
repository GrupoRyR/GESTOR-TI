<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Models\Exceptions\ExclusivePermissionException;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Spatie\Permission\Traits\HasRoles;

/**
 * Users are not company-scoped (authentication must resolve them before any
 * company context exists); `company_id` is their home company.
 *
 * Identity, privilege and lockout columns (company_id, azure_id, tenant_id,
 * is_global, active, last_login_at, failed_login_attempts, locked_until) are
 * deliberately not fillable: set them with `forceFill()`.
 *
 * @property ?int $company_id
 * @property ?bool $active
 * @property ?string $avatar_url
 * @property ?string $github_login GitHub account (issue assignee, Stage D)
 * @property ?string $azure_id
 * @property ?string $tenant_id
 * @property bool $is_global
 * @property ?Carbon $last_login_at
 * @property int $failed_login_attempts
 * @property ?Carbon $locked_until
 */
#[Fillable(['name', 'email', 'password', 'avatar_url', 'github_login'])]
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
            'active' => 'boolean',
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

    /**
     * Employee record linked to this user (by Graph sync or first login).
     *
     * @return HasOne<Employee, $this>
     */
    public function employee(): HasOne
    {
        return $this->hasOne(Employee::class)->withoutGlobalScopes();
    }

    /**
     * @return HasMany<NotificationPreference, $this>
     */
    public function notificationPreferences(): HasMany
    {
        return $this->hasMany(NotificationPreference::class);
    }

    /**
     * @return HasOne<TelegramLink, $this>
     */
    public function telegramLink(): HasOne
    {
        return $this->hasOne(TelegramLink::class);
    }

    /**
     * Chat id for the `telegram` notification channel; null while the chat
     * is not linked (the channel is then dropped by GestorNotification).
     */
    public function routeNotificationForTelegram(): ?string
    {
        if (! $this->isActive()) {
            return null;
        }

        $link = $this->telegramLink;

        return $link !== null && $link->isLinked() ? $link->chat_id : null;
    }

    /**
     * Deactivated users receive no mail (the bell keeps its history).
     */
    public function routeNotificationForMail(): ?string
    {
        return $this->isActive() ? $this->email : null;
    }

    /**
     * `users.active` (Administración › Usuarios › Desactivar). A model not
     * yet refreshed from the database counts as active (column default).
     */
    public function isActive(): bool
    {
        return $this->active ?? true;
    }

    /**
     * Local account (the emergency login): no Microsoft identity.
     */
    public function isLocal(): bool
    {
        return $this->azure_id === null;
    }
}
